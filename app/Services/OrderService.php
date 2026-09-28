<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\ReservedPlan;

class OrderService
{
    CONST STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public const MAX_RESERVED_PLANS = 10;

    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Open (fulfil) a paid order - once.
     *
     * The same payment can be marked paid twice at nearly the same moment: the
     * gateway's callback and the pending-payment recovery job both call paid(),
     * each dispatches an OrderHandleJob, and with several queue workers both
     * jobs read status 1 and opened the order - a wallet top-up credited twice,
     * a plan extended twice. A short lock per order plus a fresh read of its
     * status makes the second one a no-op.
     */
    public function open()
    {
        try {
            $lock = Cache::lock('order_open_' . $this->order->id, 120);
        } catch (\BadMethodCallException $e) {
            $lock = null; // a cache store without locks: behave as before
        }
        if ($lock && !$lock->get()) {
            return; // another worker is opening this order right now
        }
        $level = DB::transactionLevel();
        try {
            $fresh = Order::find($this->order->id);
            if (!$fresh || (int)$fresh->status === 3) {
                return; // already opened
            }
            $this->openOnce();
        } finally {
            // A failure half way through openOnce() must not leave its
            // transaction open on a queue worker's long-lived connection,
            // where every later job would write into it and never commit.
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            if ($lock) {
                $lock->release();
            }
        }
    }

    private function openOnce()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        if ($order->type == 9) {
            DB::beginTransaction();
            // Read the balance under a row lock, inside the transaction. The
            // user used to be read before the transaction without a lock, so a
            // top-up landing together with an auto-renewal or a gift card wrote
            // back a stale balance and one of the two changes was lost.
            $this->user = User::lockForUpdate()->find($order->user_id);
            $this->user->balance += $order->total_amount + $this->getbounus($order->total_amount);

            if (!$this->user->save()) {
                DB::rollBack();
                abort(500, 'شارژ حساب ناموفق بود');
            }
            $order->status = 3;
            if (!$order->save()) {
                DB::rollBack();
                abort(500, 'شارژ حساب ناموفق بود');
            }
            DB::commit();
            return;
        }

        $plan = Plan::find($order->plan_id);
        if (!$plan) {
            abort(500, 'پلن این سفارش یافت نشد');
        }

        // بررسی رزرو بسته
        // An order that carries a surplus credit was priced as a plan CHANGE:
        // the unused part of the current plan was taken off its price. It must
        // replace the current plan, as a change always did - queueing it kept
        // the old plan running as well, so the same remaining value was both
        // credited and used (and, since the old orders stayed un-offset, could
        // be credited again on the next order).
        if ($order->period !== 'reset_price' && empty($order->surplus_order_ids) && $this->shouldReserve($order)) {
            $this->reservePlan($order, $plan);
            return;
        }

        DB::beginTransaction();
        // Same reason as the top-up above: take the balance fresh and locked
        // before this path writes the whole user row back.
        $this->user = User::lockForUpdate()->find($order->user_id);
        if ($order->refund_amount) {
            $this->user->balance = $this->user->balance + $order->refund_amount;
        }
        if ($order->surplus_order_ids) {
            try {
                Order::whereIn('id', $order->surplus_order_ids)->update([
                    'status' => 4
                ]);
            } catch (\Exception $e) {
                DB::rollback();
                abort(500, 'فعال‌سازی ناموفق بود');
            }
        }
        switch ((string)$order->period) {
            case 'onetime_price':
                $this->buyByOneTime($order, $plan);
                break;
            case 'reset_price':
                $this->buyByResetTraffic();
                break;
            default:
                $this->buyByPeriod($order, $plan);
        }

        switch ((int)$order->type) {
            case 1:
                $this->openEvent(config('v2board.new_order_event_id', 0));
                break;
            case 2:
                $this->openEvent(config('v2board.renew_order_event_id', 0));
                break;
            case 3:
                $this->openEvent(config('v2board.change_order_event_id', 0));
                break;
        }

        $this->setSpeedLimit($plan->speed_limit);

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, 'فعال‌سازی ناموفق بود');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, 'فعال‌سازی ناموفق بود');
        }

        // The plan's expiry just moved (renew, or a change to a different plan).
        // Carry any paid add-on grant to the same date so a tier the customer
        // paid to ride on this plan does not die on the plan's previous expiry.
        // A first purchase simply has no grants and this updates nothing.
        AddonBillingService::syncGrantExpiry($this->user->id, $this->user->expired_at);

        DB::commit();
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'deposit'){
            $order->type = 9;
        } else if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, 'در حال حاضر تغییر اشتراک مجاز نیست؛ لطفاً با پشتیبانی تماس بگیرید یا تیکت ثبت کنید');
            $order->type = 3;
            // No surplus credit when the new plan is going to be queued behind
            // the current one: the current plan keeps running to its end, so
            // its remaining value is used, not handed back as a discount.
            if ((int)config('v2board.surplus_enable', 1) && !self::hasPlanToWaitFor($user)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user):void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', 'onetime_price')
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastOneTimeOrder) return;
        $nowUserTraffic = $user->transfer_enable / 1073741824;
        if ($nowUserTraffic == 0) return;
        $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
        if ($paidTotalAmount == 0) return;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $remainingTrafficRatio = $notUsedTraffic / $nowUserTraffic;
        $result = $remainingTrafficRatio * $paidTotalAmount;
        $order->surplus_amount = max($result, 0);
        $orderModel = Order::where('user_id', $user->id)->where('period', '!=', 'reset_price')->where('status', 3);
        $order->surplus_order_ids = array_column($orderModel->get()->toArray(), 'id');
    }

    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('period', '!=', 'reset_price')
            ->where('period', '!=', 'onetime_price')
            ->where('period', '!=', 'deposit')
            ->where('status', 3)
            ->get()
            ->toArray();
        if (!$orders) return;
        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $lastValidateAt = null;
        foreach ($orders as $item) {
            $period = self::STR_TO_TIME[$item['period']];
            $orderEndTime = strtotime("+{$period} month", $item['created_at']);
            if ($orderEndTime < time()) continue;
            $lastValidateAt = $item['created_at'] > $lastValidateAt ? $item['created_at'] : $lastValidateAt;
            $orderMonthSum += $period;
            $orderAmountSum += $item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount'];
        }
        if ($lastValidateAt === null) return;
    
        $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
        $expiredAtByUser = $user->expired_at;
        if ($expiredAtByOrder < time() || $expiredAtByUser < time()) return;
        $orderSurplusSecond = $expiredAtByUser - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidateAt;
    
        $totalTraffic = $user->transfer_enable;
        $usedTraffic = ($user->u + $user->d);
        if ($totalTraffic == 0) return;
    
        $remainingTrafficRatio = ($totalTraffic - $usedTraffic) / $totalTraffic;
    
        $avgPricePerSecond = $orderAmountSum / $orderRangeSecond;
        if ($orderRangeSecond <= 31 * 86400) {
            $remainingExpiredTimeRatio = $orderSurplusSecond / $orderRangeSecond;
            $surplusRatio = min($remainingExpiredTimeRatio, $remainingTrafficRatio);
            $orderSurplusAmount = $avgPricePerSecond * $orderSurplusSecond * $surplusRatio;
        } else {
            $monthSeconds = 30 * 86400;
            $firstMonthRemainSeconds = $orderSurplusSecond % $monthSeconds;
            $surplusRatio = min($firstMonthRemainSeconds / $monthSeconds, $remainingTrafficRatio);
            $laterMonthsSeconds = $orderSurplusSecond - $firstMonthRemainSeconds;
            $orderSurplusAmount = $avgPricePerSecond * $monthSeconds * $surplusRatio +
                                  $avgPricePerSecond * $laterMonthsSeconds;
        }
    
        $order->surplus_amount = max($orderSurplusAmount, 0);
        $order->surplus_order_ids = array_column($orders, 'id');
    }

    /**
     * Record a payment for this order and queue its fulfilment - once.
     *
     * The status is decided on the row read under a lock, not on the copy the
     * caller holds. That copy can be minutes old (the payment recovery job
     * loads its whole batch first), and writing status 1 from it over an order
     * that meanwhile reached 3 made the order open a second time: a second
     * top-up, a second plan.
     *
     * A cancelled order can still be paid late (the customer finished at the
     * gateway after cancelling, or after the pending order timed out). If part
     * of it was paid from the wallet, cancelling gave that part back, so
     * opening the order now would hand the plan over for only the gateway
     * share. Such a payment is credited to the wallet instead, where it buys
     * the plan again at its full price.
     */
    public function paid(string $callbackNo)
    {
        $order = $this->order;
        $dispatch = false;
        DB::beginTransaction();
        try {
            $fresh = Order::lockForUpdate()->find($order->id);
            if (!$fresh) {
                DB::rollBack();
                return false;
            }
            $status = (int)$fresh->status;
            if ($status !== 0 && $status !== 2) {
                DB::rollBack();
                return true;
            }
            if ($status === 2 && (int)$fresh->balance_amount > 0) {
                $user = User::lockForUpdate()->find($fresh->user_id);
                if (!$user) {
                    DB::rollBack();
                    return false;
                }
                $user->balance = (int)$user->balance + (int)$fresh->total_amount;
                $fresh->status = 4;
            } else {
                $fresh->status = 1;
                $dispatch = true;
            }
            $fresh->paid_at = time();
            $fresh->callback_no = $callbackNo;
            if ((isset($user) && !$user->save()) || !$fresh->save()) {
                DB::rollBack();
                return false;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return false;
        }

        foreach (['status', 'paid_at', 'callback_no'] as $key) {
            $order->{$key} = $fresh->{$key};
        }
        $order->syncOriginalAttributes(['status', 'paid_at', 'callback_no']);

        if (!$dispatch) return true;
        try {
            // After the commit of whatever transaction the caller runs this
            // in, so the job never reads the order before its status 1 exists.
            OrderHandleJob::dispatch($order->trade_no)->afterCommit();
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Cancel a pending order and give back what it took from the wallet.
     *
     * Only an order still waiting for payment (status 0) is cancelled, checked
     * on the locked row: an order paid in the meantime must not be cancelled
     * and refunded on top. Cancelling one that is already cancelled is a no-op
     * that reports success, so a repeated cancel never refunds twice.
     */
    public function cancel():bool
    {
        $order = $this->order;
        DB::beginTransaction();
        try {
            $fresh = Order::lockForUpdate()->find($order->id);
            if (!$fresh) {
                DB::rollBack();
                return false;
            }
            if ((int)$fresh->status !== 0) {
                DB::rollBack();
                return (int)$fresh->status === 2;
            }
            $fresh->status = 2;
            if (!$fresh->save()) {
                DB::rollBack();
                return false;
            }
            if ($fresh->balance_amount) {
                $userService = new UserService();
                if (!$userService->addBalance($fresh->user_id, (int)$fresh->balance_amount)) {
                    DB::rollBack();
                    return false;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return false;
        }
        $order->status = 2;
        $order->syncOriginalAttribute('status');
        return true;
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();

        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }

        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        if ($order->type === 2 && !$plan->carry_over_days) {
            $this->user->expired_at = $this->getTime($order->period, time());
        } else {
            $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
        }
    }

    private function buyByOneTime(Order $order, Plan $plan)
    {
        $transfer_enable = $plan->transfer_enable;
        if (!$order->surplus_order_ids) {
            $notUsedTraffic = ($this->user->transfer_enable - ($this->user->u + $this->user->d)) / 1073741824;
            if ($notUsedTraffic > 0 && $this->user->expired_at == NULL) {
                $transfer_enable += $notUsedTraffic;
            }
        }
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    public function getTimePublic($str, $timestamp)
    {
        return $this->getTime($str, $timestamp);
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        $months = match($str) {
            'month_price' => 1,
            'quarter_price' => 3,
            'half_year_price' => 6,
            'year_price' => 12,
            'two_year_price' => 24,
            'three_year_price' => 36,
            default => 0,
        };
        $result = $timestamp;
        for ($i = 0; $i < $months; $i++) {
            $jDate = jdate($result);
            $daysInMonth = $jDate->getMonthDays();
            $result += $daysInMonth * 86400;
        }
        return $result;
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function getbounus($total_amount) {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus) || $deposit_bounus[0] === null) {
            return 0;
        }
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (float)$amount * 100;
            $bounus = (float)$bounus * 100;
            $amount = (int)$amount;
            $bounus = (int)$bounus;
            if ($total_amount >= $amount) {
                $add = max($add, $bounus);
            }
        }
        return $add;
    }

    private function shouldReserve($order)
    {
        // The ten-reservation limit is enforced when the order is created
        // (reservationLimitReached). Here the customer has already paid, and an
        // abort left the order stuck at "processing" for good - retried every
        // minute, never opened and never refunded.
        return self::hasPlanToWaitFor($this->user);
    }

    /**
     * Whether a new plan order for this user would be queued and the user
     * already holds the maximum number of queued plans. Checked before an
     * order is created, while it can still be refused without taking money.
     */
    public static function reservationLimitReached(User $user, ?string $period = null): bool
    {
        if ($period === 'reset_price' || $period === 'deposit') return false;
        if (!self::hasPlanToWaitFor($user)) return false;
        return ReservedPlan::where('user_id', $user->id)->where('status', 0)->count() >= self::MAX_RESERVED_PLANS;
    }

    /**
     * Whether the user's current plan still has time and traffic left, so a
     * newly paid plan is queued behind it rather than replacing it.
     */
    private static function hasPlanToWaitFor(User $user): bool
    {
        // اگه کاربر اشتراک فعال نداره، نیاز به رزرو نیست
        if ($user->plan_id === null || $user->expired_at === null) return false;
        if ($user->expired_at <= time()) return false;

        // بررسی حجم باقیمانده (بیشتر از 100 مگابایت)
        $remainingTraffic = $user->transfer_enable - ($user->u + $user->d);
        if ($remainingTraffic <= 104857600) return false; // 100MB

        // بررسی زمان باقیمانده (بیشتر از 1 ساعت)
        $remainingTime = $user->expired_at - time();
        if ($remainingTime <= 3600) return false;

        return true;
    }

    /**
     * Whether a paid plan order was, or is about to be, queued as a reserved
     * plan instead of taking effect now. The payment pages and the bot used to
     * say "your subscription is active" either way, so a customer who paid for
     * a plan while the old one still ran was told it was active and kept the
     * old plan.
     */
    public static function isReserved(Order $order): bool
    {
        if (!$order->plan_id || $order->period === 'reset_price') return false;
        // Read the user before the order: fulfilment writes both in one
        // transaction, so an order still not done means the user row read
        // first is the state fulfilment will decide on.
        $user = User::find($order->user_id);
        if ((int)Order::where('id', $order->id)->value('status') === 3) {
            return ReservedPlan::where('order_id', $order->id)->exists();
        }
        // An order carrying a surplus credit is a plan change that replaces the
        // current plan (see openOnce), so it is never queued.
        return $user && empty($order->surplus_order_ids) && self::hasPlanToWaitFor($user);
    }

    private function reservePlan($order, $plan)
    {
        DB::beginTransaction();
        try {
            ReservedPlan::create([
                'user_id' => $this->user->id,
                'order_id' => $order->id,
                'plan_id' => $plan->id,
                'period' => $order->period,
                'status' => 0,
                'created_at' => time(),
                'updated_at' => time()
            ]);

            $order->status = 3;
            $order->save();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, 'خطا در رزرو بسته');
        }
    }
}
