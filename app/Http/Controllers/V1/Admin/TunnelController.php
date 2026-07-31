<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Per-node Hedioum tunnel on/off + LIVE health for the admin panel.
 * Registration lives in v2_tunnel_map (server_id|direct_host|tunnel_ip).
 * "live" = the Iran relay's public port is reachable; because the relay's
 * watchdog only opens that port while the pool to the foreign node is up, a
 * successful connect means the tunnel is actually serving. Cached briefly.
 */
class TunnelController extends Controller
{
    const TABLE = 'v2_server_anytls';
    const MAP = 'v2_tunnel_map';

    private function loadMap()
    {
        $map = [];
        foreach (DB::table(self::MAP)->get() as $r) {
            $map[(int) $r->server_id] = ['direct' => $r->direct_host, 'tunnel' => $r->tunnel_ip];
        }
        return $map;
    }

    /** true=up, false=down, null=unknown/cannot probe */
    private function probe($ip, $port)
    {
        if (!$ip || !$port || !function_exists('stream_socket_client')) {
            return null;
        }
        try {
            $errno = 0; $errstr = '';
            $fp = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, 1.2, STREAM_CLIENT_CONNECT);
            if ($fp) { fclose($fp); return true; }
            return false;
        } catch (\Throwable $t) {
            return null;
        }
    }

    public function status(Request $request)
    {
        $map = $this->loadMap();
        $nodes = DB::table(self::TABLE)->select('id', 'name', 'host', 'port')->get();

        // A relay forwards strictly by public port: it listens once on
        // 0.0.0.0:<port> and hands whatever arrives to the foreign registered for
        // it. Two nodes mapped to the same relay AND the same port therefore
        // cannot both work - the relay can only bind that port once - yet the
        // probe below connects to relay:port and would report BOTH as live,
        // which is the most misleading answer available: two green lights for a
        // pair where one is silently dead.
        $claims = [];
        foreach ($nodes as $n) {
            $id = (int) $n->id;
            if (!isset($map[$id])) continue;
            $key = $map[$id]['tunnel'] . ':' . (int) ($n->port ?: 0);
            $claims[$key][] = $id;
        }

        $data = [];
        foreach ($nodes as $n) {
            $id = (int) $n->id;
            $has = isset($map[$id]);
            $port = (int) ($n->port ?: 0);
            $live = null;
            $conflict = [];
            if ($has) {
                $tunnel = $map[$id]['tunnel'];
                $live = Cache::remember("tun_live_{$id}", 8, function () use ($tunnel, $port) {
                    return $this->probe($tunnel, $port ?: 2087);
                });
                $conflict = array_values(array_diff($claims[$tunnel . ':' . $port] ?? [], [$id]));
            }
            $data[] = [
                'id'       => $id,
                'name'     => $n->name,
                'host'     => $n->host,
                'detected' => $has,
                'is_on'    => $has && $n->host === $map[$id]['tunnel'],
                'live'     => $live,
                'direct'   => $has ? $map[$id]['direct'] : null,
                'tunnel'   => $has ? $map[$id]['tunnel'] : null,
                'port'     => $port,
                // Other node ids claiming this exact relay:port. Non-empty means
                // at most one of them is really being served.
                'conflict' => $conflict,
            ];
        }
        return response(['data' => $data]);
    }

    /**
     * Register (or re-point) a node's tunnel, so a new tunnelled node no longer
     * needs a hand-written INSERT on the panel host.
     *
     * Everything else here reads v2_tunnel_map and nothing could write it: the
     * only way in was SQL, and the CLI that used to do it lived on a panel host
     * that has since been decommissioned. A node missing from the map still
     * works if its host is pointed at the relay by hand - but the admin sees no
     * status for it and cannot switch it back, which is the opposite of what the
     * toggle exists for.
     *
     * `direct_host` is asked for rather than inferred: by the time an operator
     * notices the node is unregistered its host is usually ALREADY the relay IP,
     * so taking the current host would record the relay as the direct address
     * and make "switch off" a no-op that looks like a failure.
     */
    public function register(Request $request)
    {
        $id = (int) $request->input('id');
        $direct = trim((string) $request->input('direct_host'));
        $tunnel = trim((string) $request->input('tunnel_ip'));

        $node = DB::table(self::TABLE)->where('id', $id)->first();
        if (!$node) {
            return response(['message' => 'نود یافت نشد.'], 400);
        }
        if ($direct === '' || $tunnel === '') {
            return response(['message' => 'آدرس مستقیم و آی‌پی رله هر دو لازم‌اند.'], 400);
        }
        if (strcasecmp($direct, $tunnel) === 0) {
            return response(['message' => 'آدرس مستقیم و آی‌پی رله نباید یکی باشند.'], 400);
        }

        // Refuse a collision at the door rather than reporting it afterwards.
        $port = (int) ($node->port ?: 0);
        $clash = [];
        foreach ($this->loadMap() as $otherId => $row) {
            if ($otherId === $id || $row['tunnel'] !== $tunnel) continue;
            $other = DB::table(self::TABLE)->where('id', $otherId)->first();
            if ($other && (int) ($other->port ?: 0) === $port) $clash[] = $otherId;
        }
        if ($clash) {
            return response([
                'message' => 'نود ' . implode('،', $clash) . ' همین رله را روی پورت ' . $port
                    . ' گرفته است. رله هر پورت را فقط یک بار می‌تواند بگیرد، پس یکی از این دو باید پورت دیگری داشته باشد.',
            ], 400);
        }

        DB::table(self::MAP)->updateOrInsert(
            ['server_id' => $id],
            ['direct_host' => $direct, 'tunnel_ip' => $tunnel]
        );
        Cache::forget("tun_live_{$id}");
        try {
            Artisan::call('cache:clear');
        } catch (\Throwable $e) {
        }
        return response(['data' => ['id' => $id, 'direct' => $direct, 'tunnel' => $tunnel]]);
    }

    /**
     * Forget a node's tunnel registration. Refuses while the node is still
     * pointed at the relay: dropping the row then would strand it there with no
     * record of the address to go back to.
     */
    public function unregister(Request $request)
    {
        $id = (int) $request->input('id');
        $map = $this->loadMap();
        if (!isset($map[$id])) {
            return response(['data' => ['id' => $id, 'removed' => false]]);
        }
        $node = DB::table(self::TABLE)->where('id', $id)->first();
        if ($node && $node->host === $map[$id]['tunnel']) {
            return response([
                'message' => 'این نود هنوز روی رله است. اول تانل را خاموش کنید تا آدرس مستقیمش برگردد.',
            ], 400);
        }
        DB::table(self::MAP)->where('server_id', $id)->delete();
        Cache::forget("tun_live_{$id}");
        try {
            Artisan::call('cache:clear');
        } catch (\Throwable $e) {
        }
        return response(['data' => ['id' => $id, 'removed' => true]]);
    }

    public function toggle(Request $request)
    {
        $id = (int) $request->input('id');
        $on = filter_var($request->input('on'), FILTER_VALIDATE_BOOLEAN);
        $map = $this->loadMap();
        if (!isset($map[$id])) {
            return response(['message' => 'این نود تانل ندارد یا در نقشه ثبت نشده است.'], 400);
        }
        $target = $on ? $map[$id]['tunnel'] : $map[$id]['direct'];
        DB::table(self::TABLE)->where('id', $id)->update(['host' => $target]);
        Cache::forget("tun_live_{$id}");
        try {
            Artisan::call('cache:clear');
        } catch (\Throwable $e) {
        }
        return response(['data' => ['id' => $id, 'host' => $target, 'is_on' => $on]]);
    }
}
