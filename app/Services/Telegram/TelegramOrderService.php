<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Services\PaymentService;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class TelegramOrderService
{
    protected User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /** Periods a plan order may name - the same list the website accepts. */
    private const PLAN_PERIODS = [
        'month_price', 'quarter_price', 'half_year_price', 'year_price',
        'two_year_price', 'three_year_price', 'onetime_price', 'reset_price',
    ];

    /**
     * ساخت سفارش برای خرید پلن
     *
     * The same rules as the website's order form (User\OrderController::save).
     * The period comes from callback data, which a modified client can set to
     * anything, so it is checked against the list above and every sale rule is
     * applied here rather than trusted to the buttons the bot showed.
     */
    public function createPlanOrder(int $planId, string $period): array
    {
        $periodField = $period . '_price';
        if (!in_array($periodField, self::PLAN_PERIODS, true)) {
            return ['success' => false, 'message' => 'این دوره قابل خرید نیست.'];
        }

        $userService = new UserService();

        $planService = new PlanService($planId);
        $plan = $planService->plan;

        if (!$plan) {
            return ['success' => false, 'message' => 'پلن یافت نشد.'];
        }

        $user = User::find($this->user->id);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد.'];
        }
        $isReset = $periodField === 'reset_price';

        // بررسی ظرفیت
        if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && !$isReset) {
            return ['success' => false, 'message' => 'این محصول فروخته شده است.'];
        }

        if ($plan->$periodField === null) {
            return ['success' => false, 'message' => 'این دوره قابل خرید نیست.'];
        }

        if ($isReset && (!$userService->isAvailable($user) || $plan->id !== $user->plan_id)) {
            return ['success' => false, 'message' => 'اشتراک فعالی از این پلن ندارید؛ خرید بسته بازنشانی ترافیک ممکن نیست.'];
        }

        if (!$isReset && ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id))) {
            return ['success' => false, 'message' => 'این اشتراک فروخته شده است، لطفاً اشتراک دیگری انتخاب کنید.'];
        }

        if (!$isReset && !$plan->renew && $user->plan_id == $plan->id) {
            return ['success' => false, 'message' => 'این اشتراک قابل تمدید نیست، لطفاً اشتراک دیگری انتخاب کنید.'];
        }

        if (!$plan->show && $plan->renew && !$userService->isAvailable($user)) {
            return ['success' => false, 'message' => 'این اشتراک منقضی شده است، لطفاً اشتراک دیگری انتخاب کنید.'];
        }

        if (OrderService::reservationLimitReached($user, $periodField)) {
            return ['success' => false, 'message' => 'حداکثر ۱۰ بسته رزرو مجاز است.'];
        }

        // لغو سفارش‌های ناتمام قبلی (با بازگشت مبلغ کیف پول)
        $this->cancelPendingOrders();

        DB::beginTransaction();
        try {
            // The wallet is read and charged on a locked row. The bot used the
            // user it loaded when the update arrived and ignored whether the
            // charge succeeded, so a double tap charged the wallet once and
            // still produced two orders marked as paid from it.
            $user = User::lockForUpdate()->find($this->user->id);

            $order = new Order();
            $orderService = new OrderService($order);
            
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $periodField;
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $plan->$periodField;

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);

            // کسر از کیف پول
            if ($user->balance > 0 && $order->total_amount > 0 && !$user->is_staff) {
                $remainingBalance = $user->balance - $order->total_amount;
                
                if ($remainingBalance >= 0) {
                    $charged = $userService->addBalance($order->user_id, -$order->total_amount);
                    $order->balance_amount = $order->total_amount;
                    $order->total_amount = 0;
                } else {
                    $charged = $userService->addBalance($order->user_id, -$user->balance);
                    $order->balance_amount = $user->balance;
                    $order->total_amount -= $user->balance;
                }
                if (!$charged) {
                    DB::rollBack();
                    return ['success' => false, 'message' => 'موجودی کیف پول کافی نیست.'];
                }
            }

            $order->status = 0;
            $order->exchange_rate = \App\Services\ExchangeService::getCurrentRate();
            $order->source = 'telegram';
            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollback();
                return ['success' => false, 'message' => 'خطا در ایجاد سفارش.'];
            }

            DB::commit();

            // اگر مبلغ صفر شد، پرداخت شده
            if ($order->total_amount <= 0) {
                $orderService->paid($order->trade_no);
                return [
                    'success' => true,
                    'paid' => true,
                    'message' => 'سفارش با موفقیت از کیف پول پرداخت شد.',
                    'order' => $order
                ];
            }

            return [
                'success' => true,
                'paid' => false,
                'order' => $order,
                'trade_no' => $order->trade_no,
                'amount' => $order->total_amount
            ];

        } catch (\Exception $e) {
            DB::rollback();
            return ['success' => false, 'message' => 'خطا: ' . $e->getMessage()];
        }
    }

    /**
     * ساخت سفارش شارژ کیف پول
     */
    public function createDepositOrder(int $amount): array
    {
        if ($amount < 10000) {
            return ['success' => false, 'message' => 'حداقل مبلغ شارژ 10,000 تومان است.'];
        }

        if ($amount > 9999999) {
            return ['success' => false, 'message' => 'مبلغ شارژ بیش از حد مجاز است.'];
        }

        // لغو سفارش‌های ناتمام قبلی
        $this->cancelPendingOrders();

        DB::beginTransaction();
        try {
            $order = new Order();
            $orderService = new OrderService($order);

            $order->user_id = $this->user->id;
            $order->plan_id = 0;
            $order->period = 'deposit';
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $amount;

            $orderService->setOrderType($this->user);
            $orderService->setInvite($this->user);
            $order->status = 0;
            $order->exchange_rate = \App\Services\ExchangeService::getCurrentRate();
            $order->source = 'telegram';

            if (!$order->save()) {
                DB::rollback();
                return ['success' => false, 'message' => 'خطا در ایجاد سفارش.'];
            }

            DB::commit();

            return [
                'success' => true,
                'order' => $order,
                'trade_no' => $order->trade_no,
                'amount' => $order->total_amount
            ];

        } catch (\Exception $e) {
            DB::rollback();
            return ['success' => false, 'message' => 'خطا: ' . $e->getMessage()];
        }
    }

    /**
     * دریافت لینک پرداخت
     */
    public function getPaymentLink(string $tradeNo, int $paymentId): array
    {
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $this->user->id)
            ->where('status', 0)
            ->first();

        if (!$order) {
            return ['success' => false, 'message' => 'سفارش یافت نشد یا قبلاً پرداخت شده.'];
        }

        $payment = Payment::find($paymentId);
        if (!$payment || $payment->enable !== 1) {
            return ['success' => false, 'message' => 'درگاه پرداخت فعال نیست.'];
        }

        // محاسبه کارمزد
        $order->handling_amount = null;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $paymentId;
        $order->save();

        try {
            $paymentService = new PaymentService($payment->payment, $payment->id);
            $result = $paymentService->pay([
                'trade_no' => $tradeNo,
                'id' => $order->id,
                'total_amount' => $order->handling_amount ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
                'user_id' => $order->user_id
            ]);

            // بررسی فعال بودن صفحه ترانزیت
            // اگر data آرایه باشد (مثل Card2Card)، مستقیم برگردان
            if (is_array($result['data'])) {
                return [
                    'success' => true,
                    'type' => $result['type'],
                    'data' => $result['data']
                ];
            }
            $paymentUrl = $result['data'];
            $transitEnable = \DB::table('v2_bot_settings')->where('key', 'payment_transit_enable')->value('value');
            $transitUrl = \DB::table('v2_bot_settings')->where('key', 'payment_transit_url')->value('value');
            
            if ($transitEnable == '1' && !empty($transitUrl)) {
                $paymentUrl = rtrim($transitUrl, '/') . '?url=' . base64_encode($result['data']);
            }
            
            return [
                'success' => true,
                'type' => $result['type'],
                'data' => $paymentUrl
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'خطا در اتصال به درگاه: ' . $e->getMessage()];
        }
    }

    /**
     * دریافت لیست درگاه‌های فعال
     */
    public static function getActivePayments(): array
    {
        return Payment::where('enable', 1)
            ->orderBy('sort')
            ->get(['id', 'name', 'icon', 'handling_fee_fixed', 'handling_fee_percent'])
            ->toArray();
    }

    /**
     * لغو سفارش
     */
    public function cancelOrder(string $tradeNo): array
    {
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $this->user->id)
            ->where('status', 0)
            ->first();

        if (!$order) {
            return ['success' => false, 'message' => 'سفارش یافت نشد.'];
        }

        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return ['success' => false, 'message' => 'خطا در لغو سفارش.'];
        }

        return ['success' => true, 'message' => 'سفارش لغو شد.'];
    }

    /**
     * لغو سفارش‌های ناتمام کاربر
     */
    protected function cancelPendingOrders(): void
    {
        // لغو سفارش‌های در انتظار پرداخت (status = 0)
        // One by one through OrderService::cancel(), which gives back what the
        // order took from the wallet. A bulk status update cancelled the order
        // and kept that money.
        $orders = Order::where('user_id', $this->user->id)
            ->where('status', 0)
            ->get();
        foreach ($orders as $order) {
            // A card-to-card payment the customer has already reported as sent
            // is waiting for the admin, not abandoned.
            $claimed = \App\Models\CardPayment::where('order_id', $order->id)
                ->where('status', \App\Models\CardPayment::STATUS_CLAIMED)
                ->exists();
            if ($claimed) continue;
            (new OrderService($order))->cancel();
        }
    }
}