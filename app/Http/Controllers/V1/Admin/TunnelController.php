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
        $data = [];
        foreach ($nodes as $n) {
            $id = (int) $n->id;
            $has = isset($map[$id]);
            $port = (int) ($n->port ?: 0);
            $live = null;
            if ($has) {
                $tunnel = $map[$id]['tunnel'];
                $live = Cache::remember("tun_live_{$id}", 8, function () use ($tunnel, $port) {
                    return $this->probe($tunnel, $port ?: 2087);
                });
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
            ];
        }
        return response(['data' => $data]);
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
