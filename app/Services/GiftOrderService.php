<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ReservedPlan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Paid, transferable plan entitlements; no fulfilment on the purchaser. */
class GiftOrderService
{
    public static function enabled(): bool
    {
        return Schema::hasColumns('v2_order', ['is_gift', 'gift_coupon_id', 'gift_code_snapshot']);
    }

    public static function sourceForCoupon($couponId): ?Order
    {
        // Receipt readiness gates new purchases. Existing entitlements must
        // still retain their plan/period and no-cash rules during partial DDL.
        if (!$couponId || !Schema::hasColumns('v2_order', ['is_gift', 'gift_coupon_id'])) return null;
        return Order::where('gift_coupon_id', $couponId)->where('is_gift', 1)->first();
    }

    public static function setRedemptionType(Order $order, User $user): void
    {
        // A gift is an entitlement, never a reason to turn a recipient's
        // previous plan into transferable wallet credit.
        $order->type = $user->plan_id == $order->plan_id && $user->expired_at > time() ? 2 : ($user->plan_id ? 3 : 1);
    }

    public static function fulfil(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $fresh = Order::lockForUpdate()->find($order->id);
            // The row lock, status and coupon link make callback/job replays safe,
            // even on a cache store without distributed locks.
            if (!$fresh || !$fresh->is_gift || (int)$fresh->status !== 1) return;
            if (!$fresh->gift_coupon_id) {
                do {
                    // The receipt column is VARCHAR(64): 256 bits of hex fit
                    // exactly, without a prefix. Existing normal codes retain
                    // their established format and all existing codes work.
                    $code = CustomPlanService::isCustom($fresh)
                        ? strtoupper(bin2hex(random_bytes(32)))
                        : 'GIFT-' . strtoupper(bin2hex(random_bytes(16)));
                } while (Coupon::where('code', $code)->exists());
                $coupon = Coupon::create([
                    'code' => $code,
                    'name' => 'Purchased subscription gift',
                    'type' => 2,
                    'value' => 100,
                    'show' => 1,
                    'limit_use' => 1,
                    'limit_use_with_user' => null,
                    'limit_plan_ids' => [(string)$fresh->plan_id],
                    'limit_period' => [$fresh->period],
                    'started_at' => time(),
                    // Retain the maximum expiry supported by the existing
                    // signed 32-bit coupon timestamp schema (January 2038).
                    'ended_at' => 2147483647,
                ]);
                $fresh->gift_coupon_id = $coupon->id;
            }
            // The receipt outlives coupon administration or cleanup. It is
            // written in the same transaction as issuance, then never changed.
            if (!$fresh->gift_code_snapshot) {
                $coupon = $coupon ?? Coupon::findOrFail($fresh->gift_coupon_id);
                $fresh->gift_code_snapshot = $coupon->code;
            }
            $fresh->status = 3;
            $fresh->saveOrFail();
        });
    }

    /** Called only after the user controller has scoped the order to its owner. */
    public static function present(Order $order): Order
    {
        $order->gift_code = null;
        $order->gift_code_type = null;
        $order->gift_status = null;
        if (!$order->is_gift) return $order;
        $order->gift_status = [0 => 'pending', 1 => 'pending', 2 => 'cancelled', 4 => 'refunded'][(int)$order->status] ?? 'pending';
        if ((int)$order->status === 3 && $order->gift_coupon_id) {
            $coupon = Coupon::find($order->gift_coupon_id);
            $code = $order->gift_code_snapshot ?: ($coupon ? $coupon->code : null);
            if ($code) {
                $order->gift_code = $code;
                $order->gift_code_type = 'coupon';
                $order->gift_status = $coupon ? ((int)$coupon->limit_use > 0 ? 'available' : 'redeemed') : 'unavailable';
            }
        }
        return $order;
    }

    /** Redeem purchased coupons through the existing gift-card endpoint. */
    public static function redeem(int $userId, string $code): ?Order
    {
        if (!self::enabled()) return null;
        $coupon = Coupon::where('code', $code)->first();
        if (!$coupon || !self::sourceForCoupon($coupon->id)) return null;

        return DB::transaction(function () use ($userId, $code) {
            // Same user -> coupon lock order used by ordinary order creation.
            $user = User::lockForUpdate()->find($userId);
            if (!$user) abort(500, __('The user does not exist'));
            $couponService = new CouponService($code);
            $source = self::sourceForCoupon($couponService->getId());
            if (!$source || (int)$source->status !== 3) abort(500, __('Invalid coupon'));
            $plan = Plan::find($source->plan_id);
            if (!$plan) abort(500, __('Subscription plan does not exist'));
            if (OrderService::reservationLimitReached($user, $source->period)) {
                abort(500, __('The reserved plan limit has been reached'));
            }
            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $source->plan_id,
                'period' => $source->period,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => 0,
                'discount_amount' => 0,
                'coupon_id' => $couponService->getId(),
            ]);
            if (CustomPlanService::isCustom($source)) {
                if (!CustomPlanService::enabled()) abort(503, __('Tailored purchases are not available'));
                $order->custom_snapshot = CustomPlanService::commercialSnapshot($source);
                $order->exchange_rate = $source->exchange_rate;
            }
            if (!$couponService->use($order)) abort(500, __('Coupon failed'));
            // No price/availability re-check after payment: this is delivery of
            // an already purchased entitlement. Never credit buyer surplus.
            self::setRedemptionType($order, $user);
            $order->saveOrFail();
            $orderService = new OrderService($order);
            if (!$orderService->paid('gift-redemption')) {
                throw new \RuntimeException('Gift redemption failed');
            }
            $orderService->open();
            return $order;
        });
    }

    /** Optional receipt metadata; a failed follow-up read never undoes a redeemed code. */
    public static function redemptionSummary(Order $order): ?array
    {
        try {
            $fresh = $order->fresh();
            if (!$fresh || $fresh->is_gift) return null;
            $plan = Plan::find($fresh->plan_id);
            if (!$plan) return null;
            $custom = CustomPlanService::isCustom($fresh);
            $reservation = ReservedPlan::where('user_id', $fresh->user_id)
                ->where('order_id', $fresh->id)->first(['status']);
            $status = 'processing';
            if ((int)$fresh->status === 3) {
                $status = !$reservation || (int)$reservation->status === 1
                    ? 'active' : ((int)$reservation->status === 0 ? 'reserved' : 'unknown');
            }
            return [
                'status' => $status,
                'plan_name' => $custom ? 'تعرفه دست‌دوز' : $plan->name,
                'period' => $fresh->period,
                'is_custom' => $custom,
                'volume_gb' => $custom ? (int)$fresh->custom_snapshot['volume_gb'] : (float)$plan->transfer_enable,
                'duration_days' => $custom ? (int)$fresh->custom_snapshot['duration_days'] : null,
            ];
        } catch (\Throwable $e) {
            // The redemption transaction already committed. Keep its success
            // response even if optional presentation data is temporarily unavailable.
            return null;
        }
    }

    public static function restoreCancelledCoupon(Order $order): void
    {
        if (!self::sourceForCoupon($order->coupon_id)) return;
        // cancel() holds the order lock; transition 0 -> 2 occurs only once.
        $coupon = Coupon::lockForUpdate()->find($order->coupon_id);
        if ($coupon && (int)$coupon->limit_use === 0) {
            $coupon->limit_use = 1;
            $coupon->saveOrFail();
        }
    }
}
