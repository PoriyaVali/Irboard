<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use App\Services\AddonBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pricing for access groups sold as metered add-ons.
 *
 * Everything about a tier lives on its group row, so an operator can turn any
 * group into a sellable one - and add new tiers later - without a release.
 */
class GroupPricingController extends Controller
{
    public function fetch(Request $request)
    {
        $groups = ServerGroup::orderBy('id')->get(['id', 'name', 'addon_enabled', 'price_per_gb']);

        // How much each group has carried and earned, so the price can be set
        // against something real rather than guessed.
        $since = strtotime('-30 days');
        $usage = DB::table('v2_user_group_usage')
            ->where('record_at', '>=', $since)
            ->groupBy('group_id')
            ->select('group_id', DB::raw('SUM(u + d) AS bytes'), DB::raw('SUM(amount) AS earned'), DB::raw('COUNT(DISTINCT user_id) AS users'))
            ->get()
            ->keyBy('group_id');

        $holders = DB::table('v2_user_group')
            ->where('is_paid', 1)
            ->where(function ($q) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>', time());
            })
            ->groupBy('group_id')
            ->select('group_id', DB::raw('COUNT(*) AS c'))
            ->pluck('c', 'group_id');

        $nodeCounts = $this->nodeCountsByGroup();

        $data = $groups->map(function ($g) use ($usage, $holders, $nodeCounts) {
            $row = $usage[$g->id] ?? null;
            return [
                'id'            => (int)$g->id,
                'name'          => $g->name,
                'addon_enabled' => (bool)$g->addon_enabled,
                'price_per_gb'  => (int)$g->price_per_gb,
                'min_balance'   => (int)$g->price_per_gb,   // one GB's worth
                'nodes'         => $nodeCounts[$g->id] ?? 0,
                'subscribers'   => (int)($holders[$g->id] ?? 0),
                'gb_30d'        => $row ? round($row->bytes / AddonBillingService::BYTES_PER_GB, 2) : 0,
                'earned_30d'    => $row ? (int)$row->earned : 0,
                'users_30d'     => $row ? (int)$row->users : 0,
            ];
        });

        return response(['data' => $data]);
    }

    public function save(Request $request)
    {
        $id = (int)$request->input('id');
        $group = ServerGroup::find($id);
        if (!$group) abort(500, 'گروه یافت نشد');

        if ($request->exists('addon_enabled')) {
            $group->addon_enabled = $request->input('addon_enabled') ? 1 : 0;
        }
        if ($request->exists('price_per_gb')) {
            $price = (int)$request->input('price_per_gb');
            if ($price < 0) abort(500, 'قیمت نمی‌تواند منفی باشد');
            // A sellable tier with no price would let traffic through for free
            // while still looking like it is being charged for.
            if ($group->addon_enabled && $price === 0) {
                abort(500, 'برای فروش این گروه باید قیمت هر گیگ را مشخص کنید');
            }
            $group->price_per_gb = $price;
        }
        $group->updated_at = time();
        $group->save();

        return response(['data' => [
            'id'            => (int)$group->id,
            'name'          => $group->name,
            'addon_enabled' => (bool)$group->addon_enabled,
            'price_per_gb'  => (int)$group->price_per_gb,
        ]]);
    }

    /** Recent charges, newest first, so any balance question can be answered. */
    public function usage(Request $request)
    {
        $rows = DB::table('v2_user_group_usage as ug')
            ->leftJoin('v2_user as u', 'u.id', '=', 'ug.user_id')
            ->leftJoin('v2_server_group as g', 'g.id', '=', 'ug.group_id')
            ->when($request->input('group_id'), function ($q, $gid) {
                return $q->where('ug.group_id', (int)$gid);
            })
            ->when($request->input('email'), function ($q, $email) {
                return $q->where('u.email', $email);
            })
            ->orderByDesc('ug.record_at')
            ->orderByDesc('ug.id')
            ->limit(200)
            ->get(['ug.user_id', 'u.email', 'ug.group_id', 'g.name as group_name',
                   'ug.u', 'ug.d', 'ug.amount', 'ug.record_at']);

        return response(['data' => $rows->map(function ($r) {
            return [
                'user_id'    => (int)$r->user_id,
                'email'      => $r->email,
                'group_id'   => (int)$r->group_id,
                'group_name' => $r->group_name,
                'gb'         => round(((int)$r->u + (int)$r->d) / AddonBillingService::BYTES_PER_GB, 3),
                'amount'     => (int)$r->amount,
                'record_at'  => (int)$r->record_at,
            ];
        })]);
    }

    /** Visible nodes per group, across every protocol table that has groups. */
    private function nodeCountsByGroup(): array
    {
        $counts = [];
        foreach (['v2_server_anytls', 'v2_server_mdns', 'v2_server_vless', 'v2_server_vmess',
                  'v2_server_trojan', 'v2_server_hysteria', 'v2_server_tuic',
                  'v2_server_shadowsocks', 'v2_server_v2node'] as $table) {
            try {
                $rows = DB::table($table)->where('show', 1)->pluck('group_id');
            } catch (\Throwable $e) {
                continue;   // a protocol this install does not have
            }
            foreach ($rows as $raw) {
                $ids = is_string($raw) ? (json_decode($raw, true) ?: []) : (array)$raw;
                foreach ($ids as $gid) {
                    $gid = (int)$gid;
                    $counts[$gid] = ($counts[$gid] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }
}
