<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ReservedPlan;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Tailored packages: one total quota, exact days, and server-owned prices. */
class CustomPlanService
{
    public const PERIOD = 'custom_price';
    public const QUOTE_SECONDS = 900;
    private const PERIOD_DAYS = ['month_price' => 30, 'quarter_price' => 90, 'half_year_price' => 180, 'year_price' => 365];
    private const MODEL = ['offset_gb' => 150, 'reference_gb' => 185, 'reference_days' => 30, 'alpha' => 1.13, 'beta' => 0.20];

    private static function fail(int $status, string $code, string $message): void
    {
        throw new HttpResponseException(response()->json(['message' => __($message), 'code' => $code], $status));
    }

    public static function enabled(): bool
    {
        if (!Schema::hasColumn('v2_order', 'custom_snapshot') || !Schema::hasColumn('v2_plan', 'custom_key')) return false;
        // During a partial rollout both columns may exist before the index.
        // Without uniqueness, concurrent first quotes could create templates
        // with different IDs for the same access contract.
        if (DB::connection()->getDriverName() === 'mysql') {
            $rows = DB::select("SHOW INDEX FROM `v2_plan` WHERE Key_name = 'custom_key'");
            return count($rows) === 1 && (int)$rows[0]->Non_unique === 0 && $rows[0]->Column_name === 'custom_key';
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('v2_plan')") as $index) {
                if ($index->name !== 'custom_key' || (int)$index->unique !== 1) continue;
                $columns = DB::select("PRAGMA index_info('custom_key')");
                return count($columns) === 1 && $columns[0]->name === 'custom_key';
            }
        }
        return false;
    }

    public static function isCustom(Order $order): bool
    {
        return $order->period === self::PERIOD && is_array($order->custom_snapshot);
    }

    private static function cohort(Plan $plan): array
    {
        return [
            'group_id' => (int)$plan->group_id,
            'device_limit' => $plan->device_limit === null ? null : (int)$plan->device_limit,
            'speed_limit' => $plan->speed_limit === null ? null : (int)$plan->speed_limit,
            'reset_traffic_method' => $plan->reset_traffic_method === null ? (int)config('v2board.reset_traffic_method', 0) : (int)$plan->reset_traffic_method,
        ];
    }

    public static function factor(int $volume, int $days): float
    {
        return pow(($volume + 150) / 185, 1.13) * pow($days / 30, 0.20);
    }

    /** Reading the catalogue must never create a plan or an order. */
    public static function catalogue(): array
    {
        if (!self::enabled()) return ['enabled' => false];
        $rate = ExchangeService::getCurrentRate();
        if ($rate <= 0) return ['enabled' => false];
        // Finite-capacity tariffs cannot be cloned into a separately counted
        // hidden plan: that would evade their capacity. Fail closed for them.
        $plans = Plan::where('show', 1)->whereNull('custom_key')->whereNull('capacity_limit')
            ->whereBetween('transfer_enable', [1, 1024])->orderBy('sort')->orderBy('id')->get();
        $usdRows = Schema::hasTable('v2_plan_prices') ? DB::table('v2_plan_prices')->whereIn('plan_id', $plans->pluck('id'))->get()->keyBy('plan_id') : collect();
        $points = [];
        $cohort = null;
        $source = null;
        foreach ($plans as $plan) {
            $candidate = self::cohort($plan);
            if ($cohort !== null && $candidate !== $cohort) continue;
            $candidatePoints = [];
            foreach (self::PERIOD_DAYS as $period => $days) {
                if ($plan->$period === null || $plan->$period <= 0) continue;
                $usdField = $period . '_usd';
                // Match PlanController's overlay precedence. When no USD price
                // exists, the actual sellable Toman tariff still supplies a basis.
                $row = $usdRows->get($plan->id);
                $usd = $row ? $row->$usdField : $plan->$usdField;
                $priceSource = $usd === null ? 'toman' : 'usd';
                if ($usd === null) $usd = $plan->$period / $rate;
                if (!is_numeric($usd) || !is_finite((float)$usd) || $usd <= 0) continue;
                $candidatePoints[] = ['plan_id' => (int)$plan->id, 'plan_name' => $plan->name,
                    'volume_gb' => (int)$plan->transfer_enable, 'duration_days' => $days,
                    'period' => $period, 'price_usd' => (float)$usd, 'price_source' => $priceSource];
            }
            if (!$candidatePoints) continue;
            if ($cohort === null) { $cohort = $candidate; $source = (int)$plan->id; }
            $points = array_merge($points, $candidatePoints);
        }
        if (!$points) return ['enabled' => false];
        $bases = array_map(fn ($point) => $point['price_usd'] / self::factor($point['volume_gb'], $point['duration_days']), $points);
        sort($bases, SORT_NUMERIC);
        $middle = intdiv(count($bases), 2);
        $base = count($bases) % 2 ? $bases[$middle] : ($bases[$middle - 1] + $bases[$middle]) / 2;
        $model = ['base_usd' => $base] + self::MODEL;
        $revision = hash('sha256', json_encode([$model, $points, $cohort, $source, $rate], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        return ['enabled' => true, 'model' => $model,
            'bounds' => ['volume_gb' => ['min' => 1, 'max' => 1024], 'duration_days' => ['min' => 1, 'max' => 365]],
            'currency' => 'Toman', 'exchange_rate' => $rate, 'pricing_revision' => $revision,
            'source_plan_id' => $source, 'cohort' => $cohort, 'source_points' => $points];
    }

    private static function accessPlan(array $access): Plan
    {
        $key = hash('sha256', json_encode([$access['group_id'], $access['device_limit'], $access['speed_limit']], JSON_THROW_ON_ERROR));
        // The unique key also protects two concurrent first quotes. No quota,
        // duration or public tariff is inserted into this shared template.
        DB::table('v2_plan')->insertOrIgnore([
            'custom_key' => $key, 'group_id' => $access['group_id'], 'device_limit' => $access['device_limit'],
            'speed_limit' => $access['speed_limit'], 'transfer_enable' => 0, 'name' => 'تعرفه دست‌دوز',
            'show' => 0, 'renew' => 0, 'reset_traffic_method' => 2, 'created_at' => time(), 'updated_at' => time(),
        ]);
        return Plan::where('custom_key', $key)->firstOrFail();
    }

    private static function coupon(?string $code, Order $order, bool $consume): array
    {
        if ($code === null || $code === '') return [null, 0, null];
        $coupon = new CouponService($code);
        // An entitlement for a fixed purchased tariff cannot buy arbitrary GB.
        if ($coupon->isPurchasedGift()) abort(422, __('Purchased gift codes cannot pay for tailored packages'));
        $coupon->setPlanId($order->plan_id);
        $coupon->setUserId($order->user_id);
        $coupon->setPeriod(self::PERIOD);
        $coupon->check();
        $row = $coupon->getCoupon();
        if (!in_array((int)$row->type, [1, 2], true) || !is_numeric($row->value) || $row->value < 0) abort(422, __('Invalid coupon'));
        $discount = (int)round(min($order->total_amount, (int)$row->type === 1 ? $row->value : $order->total_amount * ($row->value / 100)));
        if ($consume && !$coupon->use($order)) self::fail(409, 'custom_quote_changed', 'Coupon availability changed; request a new quote');
        return [(int)$row->id, $discount, ['id' => (int)$row->id, 'type' => (int)$row->type, 'value' => (float)$row->value]];
    }

    private static function economics(User $user, Plan $plan, int $tariff, ?string $code, bool $consume): array
    {
        $preview = new Order(['user_id' => $user->id, 'plan_id' => $plan->id, 'period' => self::PERIOD, 'total_amount' => $tariff]);
        [$couponId, $couponDiscount, $couponMeta] = self::coupon($code, $preview, $consume);
        // The panel's existing contract adds VIP and coupon discounts against
        // the raw tariff. Clamp to zero and keep all stored amounts integral.
        $vip = (int)round($tariff * max(0, min(100, (float)$user->discount)) / 100);
        $discount = min($tariff, $couponDiscount + $vip);
        $total = $tariff - $discount;
        $balance = $user->is_staff ? 0 : min(max(0, (int)$user->balance), $total);
        return ['coupon_id' => $couponId, 'coupon_meta' => $couponMeta, 'vip_discount' => (float)$user->discount,
            'discount_amount' => $discount, 'total_amount' => $total, 'balance_amount' => $balance, 'payable_amount' => $total - $balance];
    }

    public static function quote(int $userId, int $volume, int $days, string $revision, ?string $code): array
    {
        $catalogue = self::catalogue();
        if (!$catalogue['enabled']) self::fail(503, 'custom_unavailable', 'Tailored purchases are not available');
        if (!hash_equals($catalogue['pricing_revision'], $revision)) self::fail(409, 'custom_price_changed', 'Tariffs changed; refresh the pricing chart');
        return DB::transaction(function () use ($userId, $volume, $days, $code, $catalogue) {
            $user = User::findOrFail($userId);
            $plan = self::accessPlan($catalogue['cohort']);
            $usd = $catalogue['model']['base_usd'] * self::factor($volume, $days);
            $tariff = (int)floor($usd * $catalogue['exchange_rate'] / 1000) * 1000;
            if ($tariff <= 0 || $tariff > 2147483647) abort(503, __('The tailored tariff is unavailable'));
            $money = self::economics($user, $plan, $tariff, $code, false);
            $snapshot = ['version' => 1, 'volume_gb' => $volume, 'duration_days' => $days,
                'price_usd' => $usd, 'tariff_amount' => $tariff, 'exchange_rate' => $catalogue['exchange_rate'],
                'source_plan_id' => $catalogue['source_plan_id'], 'access' => $catalogue['cohort'],
                'pricing_revision' => $catalogue['pricing_revision'], 'model' => $catalogue['model']];
            $expires = time() + self::QUOTE_SECONDS;
            $payload = ['kind' => 'custom-quote-v1', 'user_id' => $userId, 'trade_no' => (string)Str::uuid(),
                'expires_at' => $expires, 'template_plan_id' => (int)$plan->id, 'coupon_code' => $code ?: '',
                'snapshot' => $snapshot, 'economics' => $money];
            return ['quote_token' => Crypt::encryptString(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)),
                'expires_at' => $expires, 'volume_gb' => $volume, 'duration_days' => $days,
                'price_usd' => round($usd, 8), 'tariff_amount' => $tariff,
                'discount_amount' => $money['discount_amount'], 'total_amount' => $money['total_amount'],
                'balance_amount' => $money['balance_amount'], 'payable_amount' => $money['payable_amount'],
                'wallet_balance' => max(0, (int)$user->balance), 'wallet_eligible' => !$user->is_staff,
                'template_plan_id' => (int)$plan->id, 'pricing_revision' => $catalogue['pricing_revision'],
                'will_reserve' => OrderService::hasCurrentPlanToWaitFor($user)];
        });
    }

    public static function save(int $userId, string $token, bool $useWallet, ?string $code): Order
    {
        if (!self::enabled()) self::fail(503, 'custom_unavailable', 'Tailored purchases are not available');
        try { $quote = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { self::fail(422, 'custom_invalid_quote', 'Invalid tailored quote'); }
        if (!is_array($quote) || ($quote['kind'] ?? null) !== 'custom-quote-v1' || ($quote['user_id'] ?? null) !== $userId || !isset($quote['trade_no'], $quote['snapshot'], $quote['economics'])) {
            self::fail(422, 'custom_invalid_quote', 'Invalid tailored quote');
        }
        return DB::transaction(function () use ($userId, $quote, $useWallet, $code) {
            $user = User::lockForUpdate()->findOrFail($userId);
            // User serialization plus unique trade_no makes repeated/double-tap
            // save requests return the same order without another wallet debit.
            $existing = Order::where('trade_no', $quote['trade_no'])->first();
            if ($existing) {
                if ((int)$existing->user_id !== $userId || !self::isCustom($existing)) abort(409, __('Invalid tailored order'));
                return $existing;
            }
            if (($code ?: '') !== $quote['coupon_code']) self::fail(409, 'custom_quote_changed', 'Discount code changed; request a new quote');
            if ((int)$quote['expires_at'] <= time()) self::fail(409, 'custom_quote_expired', 'The quote expired; request a new quote');
            $source = Plan::find($quote['snapshot']['source_plan_id']);
            if (!$source || !$source->show || $source->capacity_limit !== null || self::cohort($source) !== $quote['snapshot']['access']) {
                self::fail(409, 'custom_price_changed', 'The source tariff changed; request a new quote');
            }
            if ((new UserService())->isNotCompleteOrderByUserId($userId)) self::fail(409, 'pending_order', 'You have an unpaid or pending order');
            if (OrderService::reservationLimitReached($user, self::PERIOD)) abort(409, __('The reserved plan limit has been reached'));
            $plan = self::accessPlan($quote['snapshot']['access']);
            if ((int)$plan->id !== (int)$quote['template_plan_id']) self::fail(409, 'custom_price_changed', 'The access template changed; request a new quote');
            try {
                $money = self::economics($user, $plan, (int)$quote['snapshot']['tariff_amount'], $quote['coupon_code'], true);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                self::fail(409, 'custom_quote_changed', 'Coupon availability changed; request a new quote');
            }
            foreach (['coupon_meta', 'vip_discount', 'discount_amount', 'total_amount'] as $key) {
                if ($money[$key] !== $quote['economics'][$key]) self::fail(409, 'custom_quote_changed', 'Discounts changed; request a new quote');
            }
            if ($useWallet && $money['balance_amount'] !== $quote['economics']['balance_amount']) self::fail(409, 'custom_quote_changed', 'Wallet balance changed; request a new quote');
            $wallet = $useWallet ? $money['balance_amount'] : 0;
            $order = new Order(['user_id' => $userId, 'plan_id' => $plan->id, 'period' => self::PERIOD,
                'trade_no' => $quote['trade_no'], 'total_amount' => $money['total_amount'] - $wallet,
                'balance_amount' => $wallet, 'discount_amount' => $money['discount_amount'], 'coupon_id' => $money['coupon_id'],
                'exchange_rate' => $quote['snapshot']['exchange_rate'], 'custom_snapshot' => $quote['snapshot'],
                'type' => $user->plan_id ? 3 : 1]);
            // A tailored allowance never turns an earlier allowance into cash.
            $service = new OrderService($order);
            $service->setInvite($user);
            if ($wallet) { $user->balance = (int)$user->balance - $wallet; $user->saveOrFail(); }
            $order->saveOrFail();
            return $order;
        }, 3);
    }

    /** Must run under the caller's transaction/user lock. */
    public static function activate(Order $order, User $user): void
    {
        if (!self::isCustom($order) || (int)$order->user_id !== (int)$user->id) abort(500, __('Invalid tailored entitlement'));
        $snapshot = $order->custom_snapshot;
        $volume = (int)($snapshot['volume_gb'] ?? 0);
        $days = (int)($snapshot['duration_days'] ?? 0);
        if ($volume < 1 || $volume > 1024 || $days < 1 || $days > 365) abort(500, __('Invalid tailored entitlement'));
        $plan = self::accessPlan($snapshot['access']);
        // Delivery is a boundary between consumed older ordinary allowances
        // and later purchases. Commercial snapshot fields stay unchanged.
        $activatedAt = time();
        $snapshot['activated_at'] = $activatedAt;
        $snapshot['activated_at_microseconds'] = (int)round(microtime(true) * 1000000);
        $snapshot['boundary_order_id'] = (int)Order::where('user_id', $user->id)->max('id');
        // Payment can precede delivery when a worker is delayed. These are
        // current undelivered purchases, not an unbounded history of orders.
        $snapshot['pending_paid_order_ids'] = Order::where('user_id', $user->id)->where('status', 1)
            ->where('type', '!=', 8)->where('plan_id', '>', 0)->whereNull('custom_snapshot')
            ->whereNotIn('period', [self::PERIOD, 'deposit', 'reset_price'])->pluck('id')->map(fn ($id) => (int)$id)->all();
        $snapshot['consumed_same_second_order_ids'] = Order::where('user_id', $user->id)->where('status', 3)->whereNull('custom_snapshot')
            ->whereNotIn('id', ReservedPlan::where('user_id', $user->id)->where('status', 0)->select('order_id'))
            ->where(function ($query) use ($user, $activatedAt) {
                $query->where(function ($direct) use ($user, $activatedAt) {
                    $direct->whereNotIn('id', ReservedPlan::where('user_id', $user->id)->select('order_id'))
                        ->whereRaw('COALESCE(paid_at, created_at) >= ?', [$activatedAt]);
                })->orWhereIn('id', ReservedPlan::where('user_id', $user->id)->where('status', 1)->where('activated_at', '>=', $activatedAt)->select('order_id'));
            })->pluck('id')->map(fn ($id) => (int)$id)->all();
        $order->custom_snapshot = $snapshot;
        $order->plan_id = $plan->id;
        $user->plan_id = $plan->id;
        $user->group_id = $snapshot['access']['group_id'];
        $user->device_limit = $snapshot['access']['device_limit'];
        $user->speed_limit = $snapshot['access']['speed_limit'];
        $user->u = 0;
        $user->d = 0;
        $user->transfer_enable = $volume * 1073741824;
        $user->expired_at = time() + $days * 86400;
        AddonBillingService::syncGrantExpiry($user->id, $user->expired_at);
    }

    public static function fulfil(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $fresh = Order::lockForUpdate()->find($order->id);
            if (!$fresh || !self::isCustom($fresh) || (int)$fresh->status !== 1) return;
            $user = User::lockForUpdate()->findOrFail($fresh->user_id);
            if (OrderService::hasCurrentPlanToWaitFor($user)) {
                ReservedPlan::create(['user_id' => $user->id, 'order_id' => $fresh->id, 'plan_id' => $fresh->plan_id,
                    'period' => self::PERIOD, 'status' => 0, 'created_at' => time(), 'updated_at' => time()]);
            } else {
                self::activate($fresh, $user);
                $user->saveOrFail();
            }
            $fresh->status = 3;
            $fresh->saveOrFail();
        }, 3);
    }

    public static function present(Order $order): Order
    {
        $order->is_custom = self::isCustom($order);
        if (!$order->is_custom) return $order;
        $snapshot = $order->custom_snapshot;
        $order->custom_volume_gb = (int)$snapshot['volume_gb'];
        $order->custom_duration_days = (int)$snapshot['duration_days'];
        $order->plan = ['id' => (int)$order->plan_id, 'name' => 'تعرفه دست‌دوز',
            'transfer_enable' => $order->custom_volume_gb, 'is_wallet_recharge' => false];
        return $order;
    }

    /** Never value pre-tailored consumed orders against a later allowance. */
    public static function constrainSurplus(Builder $query, int $userId): void
    {
        if (!Schema::hasColumn('v2_order', 'custom_snapshot')) return;
        $query->whereNull('custom_snapshot');
        $boundary = null;
        foreach (Order::where('user_id', $userId)->where('period', self::PERIOD)->where('status', 3)->whereNotNull('custom_snapshot')->get(['id', 'custom_snapshot']) as $custom) {
            $snapshot = $custom->custom_snapshot;
            if (empty($snapshot['activated_at'])) continue; // paid but still queued
            $stamp = $snapshot['activated_at_microseconds'] ?? ($snapshot['activated_at'] * 1000000);
            if ($boundary === null || $stamp > $boundary['stamp']) $boundary = ['stamp' => $stamp, 'snapshot' => $snapshot, 'id' => (int)$custom->id];
        }
        if ($boundary === null) return;
        $at = (int)$boundary['snapshot']['activated_at'];
        $cutoff = (int)($boundary['snapshot']['boundary_order_id'] ?? $boundary['id']);
        $consumed = $boundary['snapshot']['consumed_same_second_order_ids'] ?? [];
        $pendingPaid = $boundary['snapshot']['pending_paid_order_ids'] ?? [];
        $query->whereNotIn('id', ReservedPlan::where('user_id', $userId)->where('status', 0)->select('order_id'))
            ->where(function ($after) use ($userId, $at, $cutoff, $consumed, $pendingPaid) {
                $after->where('id', '>', $cutoff)
                    ->orWhereIn('id', $pendingPaid)
                    ->orWhere(function ($direct) use ($userId, $at, $consumed) {
                        // A cancelled gateway-only order may be paid late,
                        // with an old created_at/ID but a new paid entitlement.
                        $direct->whereNotIn('id', ReservedPlan::where('user_id', $userId)->select('order_id'))
                            ->where(function ($time) use ($at, $consumed) {
                                $time->whereRaw('COALESCE(paid_at, created_at) > ?', [$at])
                                    ->orWhere(function ($sameSecond) use ($at, $consumed) {
                                        $sameSecond->whereRaw('COALESCE(paid_at, created_at) = ?', [$at])->whereNotIn('id', $consumed);
                                    });
                            });
                    })->orWhereIn('id', ReservedPlan::where('user_id', $userId)->where('status', 1)
                        ->where(function ($time) use ($at, $consumed) {
                            $time->where('activated_at', '>', $at)->orWhere(function ($sameSecond) use ($at, $consumed) {
                                $sameSecond->where('activated_at', $at)->whereNotIn('order_id', $consumed);
                            });
                        })->select('order_id'));
            });
    }
}
