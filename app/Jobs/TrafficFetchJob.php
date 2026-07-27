<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserGroup;
use App\Services\AddonBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $billable = $this->billableUsers();

        foreach(array_keys($this->data) as $userId){
            $up   = $this->data[$userId][0] * $this->server['rate'];
            $down = $this->data[$userId][1] * $this->server['rate'];

            if (isset($billable[$userId])) {
                $groupId = $billable[$userId];
                $amount = AddonBillingService::charge($userId, $groupId, (int)($up + $down));
                AddonBillingService::record($userId, $groupId, (int)$up, (int)$down, $amount);
                // Deliberately NOT added to the counters below. These bytes
                // were paid for from the wallet; taking them out of the plan's
                // quota as well would charge for one download twice.
                continue;
            }

            Redis::hincrby('v2board_upload_traffic', $userId, $up);
            Redis::hincrby('v2board_download_traffic', $userId, $down);
        }
    }

    /**
     * Users whose access to THIS node exists only because they bought it,
     * mapped to the group they bought. Anyone entitled to the node through
     * their own plan is not charged.
     *
     * @return array<int,int> user id => group id
     */
    private function billableUsers(): array
    {
        if (!AddonBillingService::paidGroups()) return [];

        $nodeGroups = $this->server['group_id'] ?? [];
        if (is_string($nodeGroups)) $nodeGroups = json_decode($nodeGroups, true) ?: [];
        if (!is_array($nodeGroups) || !$nodeGroups) return [];

        $userIds = array_map('intval', array_keys($this->data));
        if (!$userIds) return [];

        $now = time();
        $grants = UserGroup::whereIn('user_id', $userIds)
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>', $now);
            })
            ->get(['user_id', 'group_id', 'is_paid']);
        if ($grants->isEmpty()) return [];

        // One predicate decides money everywhere: a grant is chargeable only
        // when it was BOUGHT (is_paid) AND its group is on sale right now -
        // the same rule the access gates in ServerService apply. Everything
        // else the user holds is entitlement, and entitlement must EXEMPT,
        // exactly like the plan's own group: if any of it reaches this node,
        // the traffic was never the paid tier's to bill. Fetching only
        // is_paid=1 rows here used to hide an admin's free/test grant from the
        // exemption side, so a user reaching a node through it while also
        // holding a paid tier that shared the node was charged for traffic
        // they were entitled to anyway.
        $priced = AddonBillingService::paidGroups();

        $baseGroups = User::whereIn('id', $grants->pluck('user_id')->unique()->all())
            ->pluck('group_id', 'id');

        $out = [];
        foreach ($grants->groupBy('user_id') as $userId => $rows) {
            $chargeable = $rows->filter(function ($g) use ($priced) {
                return $g->is_paid && isset($priced[$g->group_id]);
            });
            if ($chargeable->isEmpty()) continue;

            $exempt = $rows->reject(function ($g) use ($priced) {
                return $g->is_paid && isset($priced[$g->group_id]);
            })->pluck('group_id')->all();
            $exempt[] = $baseGroups[$userId] ?? null;

            $groupId = AddonBillingService::chargeableGroupId(
                $nodeGroups,
                $exempt,
                $chargeable->pluck('group_id')->all()
            );
            if ($groupId !== null) $out[(int)$userId] = $groupId;
        }
        return $out;
    }
}
