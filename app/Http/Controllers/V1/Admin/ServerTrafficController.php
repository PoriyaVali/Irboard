<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServerTrafficController extends Controller
{
    public function top15(Request $request)
    {
        $days = $request->input('days', 30);
        $since = strtotime("-{$days} days midnight");

        $stats = DB::table('v2_stat_server')
            ->select('server_id', 'server_type', DB::raw('SUM(u + d) as total_bytes'))
            ->where('record_type', 'd')
            ->where('record_at', '>=', $since)
            ->groupBy('server_id', 'server_type')
            ->orderByDesc('total_bytes')
            ->limit(15)
            ->get();

        $result = [];
        foreach ($stats as $s) {
            $tableName = 'v2_server_' . $s->server_type;
            $server = DB::table($tableName)->where('id', $s->server_id)->first();

            $daily = DB::table('v2_stat_server')
                ->select('record_at', DB::raw('(u + d) as bytes'))
                ->where('server_id', $s->server_id)
                ->where('server_type', $s->server_type)
                ->where('record_type', 'd')
                ->where('record_at', '>=', $since)
                ->orderBy('record_at')
                ->get();

            $result[] = [
                'server_id' => $s->server_id,
                'server_type' => $s->server_type,
                'name' => $server ? $server->name : 'Unknown #' . $s->server_id,
                'total_gb' => round($s->total_bytes / 1073741824, 2),
                'daily' => $daily->map(function($d) {
                    return [
                        'date' => date('m/d', $d->record_at),
                        'gb' => round($d->bytes / 1073741824, 2)
                    ];
                })
            ];
        }

        return response(['data' => $result]);
    }

    public function topUsers(Request $request)
    {
        $days = $request->input('days', 30);
        $since = strtotime("-{$days} days midnight");

        $stats = DB::table('v2_stat_user')
            ->select('user_id', DB::raw('SUM(u + d) as total_bytes'))
            ->where('record_type', 'd')
            ->where('record_at', '>=', $since)
            ->groupBy('user_id')
            ->orderByDesc('total_bytes')
            ->limit(15)
            ->get();

        $result = [];
        foreach ($stats as $s) {
            $user = DB::table('v2_user')->where('id', $s->user_id)->first();
            $result[] = [
                'user_id' => $s->user_id,
                'email' => $user ? $user->email : 'Unknown #' . $s->user_id,
                'total_gb' => round($s->total_bytes / 1073741824, 2),
            ];
        }

        return response(['data' => $result]);
    }
}
