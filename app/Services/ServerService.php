<?php

namespace App\Services;

use App\Models\ServerHysteria;
use App\Models\ServerLog;
use App\Models\ServerRoute;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\ServerV2node;
use App\Models\User;
use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerAnytls;
use App\Models\ServerMdns;
use App\Models\UserGroup;
use App\Services\AddonBillingService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class ServerService
{
    /** Memoised: getAvailableServers asks for the same user's groups nine times. */
    private $groupIdsCache = [];

    /**
     * Every group this user can reach: the one their plan gave them
     * (v2_user.group_id) plus any an admin granted on top of it.
     *
     * Returned as strings because a node stores its own group_id as a JSON
     * array of strings; keeping both sides strings avoids "1" vs 1 surprises
     * in the comparison below.
     */
    public function getUserGroupIds(User $user): array
    {
        if (isset($this->groupIdsCache[$user->id])) {
            return $this->groupIdsCache[$user->id];
        }
        $ids = [];
        if ($user->group_id !== null) {
            $ids[] = (string)$user->group_id;
        }
        foreach ($this->activeGrants($user) as $grant) {
            $ids[] = (string)$grant->group_id;
        }
        return $this->groupIdsCache[$user->id] = array_values(array_unique($ids));
    }

    /**
     * Grants that are still in force: not past their end date, and - for the
     * metered ones - still backed by a wallet with something left in it. A tier
     * the user can no longer pay for simply stops being listed, which is also
     * what takes them off its nodes.
     *
     * @return \Illuminate\Support\Collection
     */
    private function activeGrants(User $user)
    {
        $now = time();
        $grants = UserGroup::where('user_id', $user->id)
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>', $now);
            })
            ->get(['group_id', 'is_paid']);

        $paid = AddonBillingService::paidGroups();
        return $grants->filter(function ($grant) use ($user, $paid) {
            if (!$grant->is_paid) return true;
            $group = $paid[$grant->group_id] ?? null;
            if (!$group) return false;
            // Serve for as long as ANY credit is left. The customer paid for every
            // rial of it and charging is proportional per byte, so 50 toman simply
            // buys the last ~50 MB. Demanding a full GB's worth here stranded
            // whatever sat below that line - a wallet on 999 lost access while
            // holding almost a gigabyte of credit. The one-GB floor is an entry
            // requirement only, enforced when the tier is switched on.
            return (int)$user->balance > 0;
        });
    }

    /**
     * True when at least one of the user's groups is listed on the node.
     * A user with no groups at all reaches nothing, which is what we want.
     */
    private function groupCanReach(array $userGroupIds, $serverGroupIds): bool
    {
        if (!is_array($serverGroupIds) || !$userGroupIds) return false;
        return (bool)array_intersect($userGroupIds, array_map('strval', $serverGroupIds));
    }

    public function getAvailableVless(User $user): array
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $servers = [];
        $model = ServerVless::orderBy('sort', 'ASC');
        $server = $model->get();
        foreach ($server as $key => $v) {
            if (!$v['show']) continue;
            $server[$key]['type'] = 'vless';
            if (!$this->groupCanReach($userGroupIds, $server[$key]['group_id'])) continue;
            if (strpos($server[$key]['port'], '-') !== false) {
                $server[$key]['port'] = Helper::randomPort($server[$key]['port']);
            }
            if ($server[$key]['parent_id']) {
                $server[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $server[$key]['parent_id']));
            } else {
                $server[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $server[$key]['id']));
            }
            if (isset($server[$key]['tls_settings'])) {
                $server[$key]['tls_settings'] = array_diff_key(
                    $server[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($server, $key) {
                        return isset($server[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($server[$key]['encryption_settings'])) {
                if (isset($server[$key]['encryption_settings']['private_key'])) {
                    $server[$key]['encryption_settings'] = array_diff_key($server[$key]['encryption_settings'], array('private_key' => ''));
                }
            }
            $servers[] = $server[$key]->toArray();
        }


        return $servers;
    }

    public function getAvailableVmess(User $user): array
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $servers = [];
        $model = ServerVmess::orderBy('sort', 'ASC');
        $vmess = $model->get();
        foreach ($vmess as $key => $v) {
            if (!$v['show']) continue;
            $vmess[$key]['type'] = 'vmess';
            if (!$this->groupCanReach($userGroupIds, $vmess[$key]['group_id'])) continue;
            if (strpos($vmess[$key]['port'], '-') !== false) {
                $vmess[$key]['port'] = Helper::randomPort($vmess[$key]['port']);
            }
            if ($vmess[$key]['parent_id']) {
                $vmess[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $vmess[$key]['parent_id']));
            } else {
                $vmess[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $vmess[$key]['id']));
            }
            $servers[] = $vmess[$key]->toArray();
        }


        return $servers;
    }

    public function getAvailableTrojan(User $user): array
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $servers = [];
        $model = ServerTrojan::orderBy('sort', 'ASC');
        $trojan = $model->get();
        foreach ($trojan as $key => $v) {
            if (!$v['show']) continue;
            $trojan[$key]['type'] = 'trojan';
            if (!$this->groupCanReach($userGroupIds, $trojan[$key]['group_id'])) continue;
            if (strpos($trojan[$key]['port'], '-') !== false) {
                $trojan[$key]['port'] = Helper::randomPort($trojan[$key]['port']);
            }
            if ($trojan[$key]['parent_id']) {
                $trojan[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $trojan[$key]['parent_id']));
            } else {
                $trojan[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $trojan[$key]['id']));
            }
            $servers[] = $trojan[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableTuic(User $user)
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $availableServers = [];
        $model = ServerTuic::orderBy('sort', 'ASC');
        $servers = $model->get()->keyBy('id');
        foreach ($servers as $key => $v) {
            if (!$v['show']) continue;
            $servers[$key]['type'] = 'tuic';
            $servers[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TUIC_LAST_CHECK_AT', $v['id']));
            if (!$this->groupCanReach($userGroupIds, $v['group_id'])) continue;
            if (isset($servers[$v['parent_id']])) {
                $servers[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TUIC_LAST_CHECK_AT', $v['parent_id']));
                $servers[$key]['created_at'] = $servers[$v['parent_id']]['created_at'];
            }
            $availableServers[] = $servers[$key]->toArray();
        }
        return $availableServers;
    }

    public function getAvailableHysteria(User $user)
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $availableServers = [];
        $model = ServerHysteria::orderBy('sort', 'ASC');
        $servers = $model->get()->keyBy('id');
        foreach ($servers as $key => $v) {
            if (!$v['show']) continue;
            $servers[$key]['type'] = 'hysteria';
            $servers[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_HYSTERIA_LAST_CHECK_AT', $v['id']));
            if (!$this->groupCanReach($userGroupIds, $v['group_id'])) continue;
            if (isset($servers[$v['parent_id']])) {
                $servers[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_HYSTERIA_LAST_CHECK_AT', $v['parent_id']));
                $servers[$key]['created_at'] = $servers[$v['parent_id']]['created_at'];
            }
            $servers[$key]['server_key'] = Helper::getServerKey($servers[$key]['created_at'], 16);
            $availableServers[] = $servers[$key]->toArray();
        }
        return $availableServers;
    }

    public function getAvailableShadowsocks(User $user)
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $servers = [];
        $model = ServerShadowsocks::orderBy('sort', 'ASC');
        $shadowsocks = $model->get()->keyBy('id');
        foreach ($shadowsocks as $key => $v) {
            if (!$v['show']) continue;
            $shadowsocks[$key]['type'] = 'shadowsocks';
            $shadowsocks[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_SHADOWSOCKS_LAST_CHECK_AT', $v['id']));
            if (!$this->groupCanReach($userGroupIds, $v['group_id'])) continue;
            if (strpos($v['port'], '-') !== false) {
                $shadowsocks[$key]['port'] = Helper::randomPort($v['port']);
            }
            if (isset($shadowsocks[$v['parent_id']])) {
                $shadowsocks[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_SHADOWSOCKS_LAST_CHECK_AT', $v['parent_id']));
                $shadowsocks[$key]['created_at'] = $shadowsocks[$v['parent_id']]['created_at'];
            }
            if ($v['obfs'] === 'http') {
                $shadowsocks[$key]['obfs'] = 'http';
                $shadowsocks[$key]['obfs-host'] = $v['obfs_settings']['host'];
                $shadowsocks[$key]['obfs-path'] = $v['obfs_settings']['path'];
            }
            $servers[] = $shadowsocks[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableAnyTLS(User $user)
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $servers = [];
        $model = ServerAnytls::orderBy('sort', 'ASC');
        $anytls = $model->get()->keyBy('id');
        foreach ($anytls as $key => $v) {
            if (!$v['show']) continue;
            $anytls[$key]['type'] = 'anytls';
            $anytls[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_ANYTLS_LAST_CHECK_AT', $v['id']));
            if (!$this->groupCanReach($userGroupIds, $v['group_id'])) continue;
            if (strpos($v['port'], '-') !== false) {
                $anytls[$key]['port'] = Helper::randomPort($v['port']);
            }
            if (isset($anytls[$v['parent_id']])) {
                $anytls[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_ANYTLS_LAST_CHECK_AT', $v['parent_id']));
                $anytls[$key]['created_at'] = $anytls[$v['parent_id']]['created_at'];
            }
            $servers[] = $anytls[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableV2node(User $user)
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $servers = [];
        $model = ServerV2node::orderBy('sort', 'ASC');
        $v2node = $model->get()->keyBy('id');
        foreach ($v2node as $key => $v) {
            if (!$v['show']) continue;
            $v2node[$key]['type'] = 'v2node';
            $v2node[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $v['id']));
            if (!$this->groupCanReach($userGroupIds, $v['group_id'])) continue;
            if (isset($v2node[$v['parent_id']])) {
                $v2node[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $v['parent_id']));
                $v2node[$key]['created_at'] = $v2node[$v['parent_id']]['created_at'];
            }
            if (isset($v2node[$key]['tls_settings'])) {
                $v2node[$key]['tls_settings'] = array_diff_key(
                    $v2node[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($v2node, $key) {
                        return isset($v2node[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($v2node[$key]['encryption_settings'])) {
                if (isset($v2node[$key]['encryption_settings']['private_key'])) {
                    $v2node[$key]['encryption_settings'] = array_diff_key($v2node[$key]['encryption_settings'], array('private_key' => ''));
                }
            }
            $servers[] = $v2node[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableMdns(User $user)
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $servers = [];
        $model = ServerMdns::orderBy('sort', 'ASC');
        $mdns = $model->get()->keyBy('id');
        foreach ($mdns as $key => $v) {
            if (!$v['show']) continue;
            $mdns[$key]['type'] = 'mdns';
            $mdns[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_MDNS_LAST_CHECK_AT', $v['id']));
            if (!$this->groupCanReach($userGroupIds, $v['group_id'])) continue;
            if (isset($mdns[$v['parent_id']])) {
                $mdns[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_MDNS_LAST_CHECK_AT', $v['parent_id']));
                $mdns[$key]['created_at'] = $mdns[$v['parent_id']]['created_at'];
            }
            $servers[] = $mdns[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableServers(User $user)
    {
        $servers = array_merge(
            $this->getAvailableShadowsocks($user),
            $this->getAvailableVmess($user),
            $this->getAvailableTrojan($user),
            $this->getAvailableTuic($user),
            $this->getAvailableHysteria($user),
            $this->getAvailableVless($user),
            $this->getAvailableAnyTLS($user),
            $this->getAvailableV2node($user),
            $this->getAvailableMdns($user)
        );
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        $servers = $this->dropDuplicateServers($servers);
        return array_map(function ($server) {
            if (strpos($server['port'], '-')) {
                $server['mport'] = (string)$server['port'];
            } else {
                $server['port'] = (int)$server['port'];
            }
            $server['is_online'] = (time() - 300 > $server['last_check_at']) ? 0 : 1;
            $server['cache_key'] = "{$server['type']}-{$server['id']}-{$server['updated_at']}-{$server['is_online']}";
            return $server;
        }, $servers);
    }

    /**
     * Send each server to the client once.
     *
     * The same machine is often registered as more than one node so it can be
     * tagged into different groups. With one group per user only one of those
     * rows was ever reachable, but a user who now holds several groups would
     * receive the same host and port several times, and clients react badly to
     * that - duplicate entries, duplicate remarks, and connections bouncing
     * between identical targets.
     *
     * Identity is protocol + host + port, so genuinely different endpoints on
     * one machine (a second port, a different protocol) are kept. The list has
     * already been sorted, so the first copy of a duplicate is the one with the
     * lowest sort - the operator's preferred row survives.
     */
    private function dropDuplicateServers(array $servers): array
    {
        $seen = [];
        $unique = [];
        foreach ($servers as $server) {
            $key = strtolower(
                ($server['type'] ?? '') . '|' .
                ($server['host'] ?? '') . '|' .
                ($server['port'] ?? '')
            );
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $server;
        }
        return $unique;
    }

    public function getAvailableUsers($groupId)
    {
        return User::where(function ($query) use ($groupId) {
                // Reachable either through the plan's group or through one an
                // admin granted. This OR *must* stay wrapped in its own closure:
                // at the top level it would break the AND-chain below, and every
                // user with an extra group would then bypass the traffic, expiry
                // and ban checks entirely - i.e. keep connecting after running
                // out of data or after their subscription ended.
                $query->whereIn('group_id', $groupId)
                    ->orWhereIn('id', function ($sub) use ($groupId) {
                        $sub->select('user_id')
                            ->from('v2_user_group')
                            ->whereIn('group_id', $groupId)
                            // a grant only counts while it is still in force
                            ->where(function ($g) {
                                $g->whereNull('expired_at')->orWhere('expired_at', '>', time());
                            })
                            // and a metered one only while the wallet can pay
                            // for the next GB; below that the user drops off
                            // this node at its next poll
                            ->where(function ($g) use ($groupId) {
                                $g->where('is_paid', 0)
                                  ->orWhereRaw(
                                      'v2_user.balance > 0'
                                  );
                            });
                    });
            })
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->get();
    }

    public function log(int $userId, int $serverId, int $u, int $d, float $rate, string $method)
    {
        if (($u + $d) < 10240) return true;
        $timestamp = strtotime(date('Y-m-d'));
        $serverLog = ServerLog::where('log_at', '>=', $timestamp)
            ->where('log_at', '<', $timestamp + 3600)
            ->where('server_id', $serverId)
            ->where('user_id', $userId)
            ->where('rate', $rate)
            ->where('method', $method)
            ->first();
        if ($serverLog) {
            try {
                $serverLog->increment('u', $u);
                $serverLog->increment('d', $d);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        } else {
            $serverLog = new ServerLog();
            $serverLog->user_id = $userId;
            $serverLog->server_id = $serverId;
            $serverLog->u = $u;
            $serverLog->d = $d;
            $serverLog->rate = $rate;
            $serverLog->log_at = $timestamp;
            $serverLog->method = $method;
            return $serverLog->save();
        }
    }

    public function getAllShadowsocks()
    {
        $servers = ServerShadowsocks::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'shadowsocks';
        }
        return $servers;
    }

    public function getAllVMess()
    {
        $servers = ServerVmess::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vmess';
        }
        return $servers;
    }

    public function getAllVLess()
    {
        $servers = ServerVless::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vless';
        }
        return $servers;
    }

    public function getAllTrojan()
    {
        $servers = ServerTrojan::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'trojan';
        }
        return $servers;
    }

    public function getAllTuic()
    {
        $servers = ServerTuic::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'tuic';
        }
        return $servers;
    }

    public function getAllHysteria()
    {
        $servers = ServerHysteria::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'hysteria';
        }
        return $servers;
    }

    public function getAllAnyTLS()
    {
        $servers = ServerAnytls::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'anytls';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }
        }
        return $servers;
    }

    public function getAllMdns()
    {
        $servers = ServerMdns::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'mdns';
        }
        return $servers;
    }

    public function getAllV2node()
    {
        $servers = ServerV2node::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'v2node';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }

            $apiHost = config('v2board.server_api_url', config('v2board.app_url'));
            $apiKey = config('v2board.server_token', '');
            $nodeId = (int) $v['id'];
            $apiHostArg = escapeshellarg((string) $apiHost);
            $apiKeyArg = escapeshellarg((string) $apiKey);
            $servers[$k]['install_command'] = sprintf(
                'wget -N https://raw.githubusercontent.com/wyx2685/v2node/master/script/install.sh && bash install.sh --api-host %s --node-id %d --api-key %s',
                $apiHostArg,
                $nodeId,
                $apiKeyArg
            );
        }
        return $servers;
    }

    private function mergeData(&$servers)
    {
        foreach ($servers as $k => $v) {
            $serverType = strtoupper($v['type']);
            $servers[$k]['online'] = Cache::get(CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $v['parent_id'] ?? $v['id']));
            $servers[$k]['last_check_at'] = Cache::get(CacheKey::get("SERVER_{$serverType}_LAST_CHECK_AT", $v['parent_id'] ?? $v['id']));
            $servers[$k]['last_push_at'] = Cache::get(CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $v['parent_id'] ?? $v['id']));
            if ((time() - 300) >= $servers[$k]['last_check_at']) {
                $servers[$k]['available_status'] = 0;
            } else if ((time() - 300) >= $servers[$k]['last_push_at']) {
                $servers[$k]['available_status'] = 1;
            } else {
                $servers[$k]['available_status'] = 2;
            }
        }
    }

    public function getAllServers()
    {
        $servers = array_merge(
            $this->getAllShadowsocks(),
            $this->getAllVMess(),
            $this->getAllTrojan(),
            $this->getAllTuic(),
            $this->getAllHysteria(),
            $this->getAllVLess(),
            $this->getAllAnyTLS(),
            $this->getAllV2node(),
            $this->getAllMdns()
        );
        $this->mergeData($servers);
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        return $servers;
    }

    public function getRoutes(array $routeIds)
    {
        $routeIds = array_map('intval', $routeIds);
        $order = implode(',', $routeIds);
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])
            ->whereIn('id', $routeIds)
            ->orderByRaw("FIELD(id, $order)")
            ->get();
        foreach ($routes as $k => $route) {
            $array = json_decode($route->match, true);
            if (is_array($array)) $routes[$k]['match'] = $array;
        }
        return $routes;
    }

    public function getServer($serverId, $serverType)
    {
        switch ($serverType) {
            case 'v2node':
                return ServerV2node::find($serverId);
            case 'vmess':
                return ServerVmess::find($serverId);
            case 'shadowsocks':
                return ServerShadowsocks::find($serverId);
            case 'trojan':
                return ServerTrojan::find($serverId);
            case 'tuic':
                return ServerTuic::find($serverId);
            case 'hysteria':
                return ServerHysteria::find($serverId);
            case 'vless':
                return ServerVless::find($serverId);
            case 'anytls':
                return ServerAnytls::find($serverId);
            case 'mdns':
                return ServerMdns::find($serverId);
            default:
                return false;
        }
    }
}