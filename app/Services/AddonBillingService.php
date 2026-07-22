<?php

namespace App\Services;

use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;

/**
 * Metered billing for access groups a user bought on top of their plan.
 *
 * A group becomes sellable by setting addon_enabled and a price_per_gb on it,
 * so an operator can add as many tiers as they like - a tunnelled tier, a
 * static-IP tier - without any code change. Nothing here knows the name or the
 * id of any particular group.
 *
 * Only traffic the user could NOT otherwise reach is charged. If a node is
 * also tagged with the group their plan gives them, they were entitled to it
 * anyway, so it stays free and counts against their plan quota as before.
 * Charged traffic is deliberately kept out of that quota: the user is already
 * paying for it from their wallet, and taking it from the plan as well would
 * bill them twice for one download.
 */
class AddonBillingService
{
    public const BYTES_PER_GB = 1073741824;

    /** @var array<int,ServerGroup>|null groups that are sellable, keyed by id */
    private static $paidGroups = null;

    /** Sellable groups, read once per process. */
    public static function paidGroups(): array
    {
        if (self::$paidGroups === null) {
            self::$paidGroups = ServerGroup::where('addon_enabled', 1)
                ->where('price_per_gb', '>', 0)
                ->get()
                ->keyBy('id')
                ->all();
        }
        return self::$paidGroups;
    }

    /** A wallet must hold at least one GB's worth before the tier will run. */
    public static function minimumBalance(ServerGroup $group): int
    {
        return (int)$group->price_per_gb;
    }

    /**
     * Keep a user's paid grants ending exactly when their plan does.
     *
     * A paid grant stores its own expired_at, snapshotted from the plan the
     * moment it was switched on. Every place that changes a user's plan expiry
     * - a renewal, a reseller re-assigning a plan, the renewal cron - moves
     * v2_user.expired_at but leaves the grant on its old date, so a renewed
     * plan would quietly outlive the tier the customer paid to ride on it, and
     * the access and billing gates (both keyed on the grant's expired_at) would
     * cut the tier off early. Call this right after writing the new plan expiry
     * and the grants move with it.
     *
     * Only paid grants are touched. A free admin grant may deliberately carry a
     * null (never-expiring) expiry, and matching it to the plan would be wrong.
     *
     * @param int      $userId
     * @param int|null $planExpiredAt the user's new expired_at (null = a
     *                                 one-time plan that never expires)
     */
    public static function syncGrantExpiry(int $userId, $planExpiredAt): void
    {
        UserGroup::where('user_id', $userId)
            ->where('is_paid', 1)
            ->update([
                'expired_at' => $planExpiredAt,
                'updated_at' => time(),
            ]);
    }

    /**
     * Which sellable group, if any, this node is reachable through *only*
     * because of a paid grant. Returns null when the traffic is free.
     *
     * @param array $serverGroupIds the node's own group tags
     * @param array $baseGroupIds   the groups the user's plan gives them
     * @param array $paidGrantIds   groups the user bought
     */
    public static function chargeableGroupId(array $serverGroupIds, array $baseGroupIds, array $paidGrantIds): ?int
    {
        $nodeGroups = array_map('strval', $serverGroupIds);
        // entitled through the plan -> nothing to charge
        if (array_intersect(array_map('strval', $baseGroupIds), $nodeGroups)) {
            return null;
        }
        foreach ($paidGrantIds as $gid) {
            if (in_array((string)$gid, $nodeGroups, true) && isset(self::paidGroups()[$gid])) {
                return (int)$gid;
            }
        }
        return null;
    }

    /**
     * Charge a user for traffic on one paid group.
     *
     * Bytes are accumulated on the grant and converted to whole units of
     * currency, with the remainder carried forward. Reports arrive about once a
     * minute, so rounding each one up to a whole GB would charge a user a full
     * GB for a few megabytes - roughly sixty GB an hour for someone who is
     * barely using the connection. Carrying the remainder means the total
     * charged always matches what was actually transferred.
     *
     * @return int the amount actually taken from the wallet
     */
    public static function charge(int $userId, int $groupId, int $bytes): int
    {
        if ($bytes <= 0) return 0;
        $groups = self::paidGroups();
        if (!isset($groups[$groupId])) return 0;
        $price = (int)$groups[$groupId]->price_per_gb;
        if ($price <= 0) return 0;

        $charged = 0;
        DB::transaction(function () use ($userId, $groupId, $bytes, $price, &$charged) {
            $grant = UserGroup::where('user_id', $userId)
                ->where('group_id', $groupId)
                ->lockForUpdate()
                ->first();
            if (!$grant || !$grant->is_paid) return;

            $total  = (int)$grant->unbilled_bytes + $bytes;
            $amount = intdiv($total * $price, self::BYTES_PER_GB);   // whole units earned

            if ($amount > 0) {
                $user = User::lockForUpdate()->find($userId);
                if (!$user) return;
                // Never push a wallet negative: take what is there and let the
                // cutoff drop the user off this tier on the next node poll.
                $take = min($amount, max(0, (int)$user->balance));
                if ($take > 0) {
                    $user->balance = (int)$user->balance - $take;
                    $user->save();
                    $charged = $take;
                }
                // Bytes matching what was actually taken stay settled; anything
                // that could not be paid for is dropped rather than held as a
                // debt the user never agreed to.
                $grant->unbilled_bytes = $total - intdiv($amount * self::BYTES_PER_GB, $price);
            } else {
                $grant->unbilled_bytes = $total;
            }
            $grant->updated_at = time();
            $grant->save();
        });

        return $charged;
    }

    /** Append to the day's ledger row so a balance change can always be explained. */
    public static function record(int $userId, int $groupId, int $u, int $d, int $amount): void
    {
        $recordAt = strtotime(date('Y-m-d'));
        DB::table('v2_user_group_usage')->updateOrInsert(
            ['user_id' => $userId, 'group_id' => $groupId, 'record_at' => $recordAt],
            ['created_at' => time()]
        );
        DB::table('v2_user_group_usage')
            ->where('user_id', $userId)
            ->where('group_id', $groupId)
            ->where('record_at', $recordAt)
            ->update([
                'u'      => DB::raw('u + ' . (int)$u),
                'd'      => DB::raw('d + ' . (int)$d),
                'amount' => DB::raw('amount + ' . (int)$amount),
            ]);
    }
}
