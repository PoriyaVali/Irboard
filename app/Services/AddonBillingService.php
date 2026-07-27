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

    /** @var int unix time the snapshot above was taken */
    private static $paidGroupsAt = 0;

    /**
     * How long the sellable-groups snapshot may be reused, in seconds. One
     * billing tick: traffic reports arrive about once a minute per node, so at
     * most one report per worker is ever billed at outdated terms - the same
     * order of lag the reports themselves already carry.
     */
    public const PAID_GROUPS_TTL = 60;

    /**
     * Sellable groups, re-read at most every {@see PAID_GROUPS_TTL} seconds.
     *
     * This must NOT be a read-once-per-process cache, because "the process" is
     * not always a request. Every HTTP caller runs under php-fpm, where state
     * dies with the request - but TrafficFetchJob, the only code that actually
     * charges wallets, runs inside a Horizon worker that lives until its memory
     * limit trips (no maxJobs/maxTime is configured, and nothing schedules a
     * recycle). A plain static therefore froze the operator's pricing at
     * whatever it was when each worker was born: a tier whose sale was switched
     * off kept charging, a price change kept billing the old price, and a newly
     * priced tier billed nothing - per worker, indefinitely, while the access
     * gates in ServerService (fpm, always fresh) disagreed in real time. With
     * workers of different ages the two errors even interleave minute by
     * minute. The TTL bounds all of that to one billing tick; under fpm nothing
     * changes, since no request outlives it.
     */
    public static function paidGroups(): array
    {
        if (self::$paidGroups === null || time() - self::$paidGroupsAt > self::PAID_GROUPS_TTL) {
            self::$paidGroups = ServerGroup::where('addon_enabled', 1)
                ->where('price_per_gb', '>', 0)
                ->get()
                ->keyBy('id')
                ->all();
            self::$paidGroupsAt = time();
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
     * @param array $baseGroupIds   every group the user is entitled to WITHOUT
     *                              per-GB charging: the plan's group plus any
     *                              grant that is not both bought and currently
     *                              priced (an admin's free/test grant, or a
     *                              tier whose sale has been switched off).
     *                              Entitlement exempts: if any of these reach
     *                              the node, its traffic is never billed.
     * @param array $paidGrantIds   groups the user bought that are on sale now
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
                // Settle ONLY the bytes that were actually paid for. When the
                // wallet covered everything this is the usual remainder-carry;
                // when it fell short, the unpaid bytes stay on the grant instead
                // of being written off, so a top-up while the tier is still on
                // pays for exactly what was used - no more, no less. This is
                // bounded, not open-ended debt: the access gates cut a drained
                // wallet off within about a minute, so at most one final tick of
                // traffic can accumulate here, and disabling the tier discards
                // it with the grant.
                $grant->unbilled_bytes = $total - intdiv($take * self::BYTES_PER_GB, $price);
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
