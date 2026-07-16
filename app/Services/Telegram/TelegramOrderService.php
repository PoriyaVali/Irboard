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

    /**
     * ساخت سفارش برای خرید پلن
     */
    public function createPlanOrder(int $planId, string $period): array
    {
        // لغو سفارش‌های ناتمام قبلی
        $this->cancelPendingOrders();
        
        $userService = new UserService();

        $planService = new PlanService($planId);
        $plan = $planService->plan;

        if (!$plan) {
            return ['success' => false, 'message' => 'پلن یافت نشد.'];
        }

        // بررسی ظرفیت
        if ($this->user->plan_id !== $plan->id && !$planService->haveCapacity() && $period !== 'reset_price') {
            return ['success' => false, 'message' => 'این محصول فروخته شده است.'];
        }

        $periodField = $period . '_price';
        if ($plan->$periodField === null) {
            return ['success' => false, 'message' => 'این دوره قابل خرید نیست.'];
        }

        DB::beginTransaction();
        try {
            $order = new Order();
            $orderService = new OrderService($order);
            
            $order->user_id = $this->user->id;
            $order->plan_id = $plan->id;
            $order->period = $periodField;
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $plan->$periodField;

            $orderService->setVipDiscount($this->user);
            $orderService->setOrderType($this->user);

            // کسر از کیف پول
            if ($this->user->balance > 0 && $order->total_amount > 0) {
                $remainingBalance = $this->user->balance - $order->total_amount;
                
                if ($remainingBalance >= 0) {
                    $userService->addBalance($order->user_id, -$order->total_amount);
                    $order->balance_amount = $order->total_amount;
                    $order->total_amount = 0;
                } else {
                    $userService->addBalance($order->user_id, -$this->user->balance);
                    $order->balance_amount = $this->user->balance;
                    $order->total_amount -= $this->user->balance;
                }
            }

            $order->status = 0;
            $order->exchange_rate = \App\Services\ExchangeService::getCurrentRate();
            $order->source = 'telegram';
            $orderService->setInvite($this->user);

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
        // لغو سفارش‌های ناتمام قبلی
        $this->cancelPendingOrders();
        
        $userService = new UserService();


        if ($amount < 10000) {
            return ['success' => false, 'message' => 'حداقل مبلغ شارژ 10,000 تومان است.'];
        }

        if ($amount > 9999999) {
            return ['success' => false, 'message' => 'مبلغ شارژ بیش از حد مجاز است.'];
        }

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
        Order::where('user_id', $this->user->id)
            ->where('status', 0)
            ->update(['status' => 2]);
    }
}