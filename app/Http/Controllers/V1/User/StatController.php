<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        // ?days=N gives a rolling window of the last N days instead of the
        // calendar month. Without it nothing changes, so the existing web
        // client keeps the behaviour it was written against.
        //
        // The month boundary is wrong for anything that says "the last month":
        // on the 1st it returns a single day. Capped at 90 so a crafted request
        // cannot ask for the whole table.
        $days = (int) $request->input('days', 0);
        $since = $days > 0
            ? strtotime('today') - (min($days, 90) * 86400)
            : strtotime(date('Y-m-1'));

        $builder = StatUser::select([
            'u',
            'd',
            'record_at',
            'user_id',
            'server_rate'
        ])
            ->where('user_id', $request->user['id'])
            ->where('record_at', '>=', $since)
            ->orderBy('record_at', 'DESC');
        return response([
            'data' => $builder->get()
        ]);
    }

    /**
     * How many devices this account had, day by day.
     *
     * "Devices" is distinct IP addresses, not sessions and not connections:
     * the count comes from what the nodes report as alive and is folded by
     * UniProxyController, which with device_limit_mode=1 de-duplicates by IP
     * across every node. Two phones behind one wifi are therefore one device
     * here - a property of counting IPs, and worth knowing when reading the
     * number.
     *
     * peak is the most seen at once that day and is the number that answers
     * "is someone else on my account". avg is what the day looked like on
     * average, which is the fairer one for "how much am I really using it".
     */
    public function getDeviceLog(Request $request)
    {
        $days = (int) $request->input('days', 30);
        $days = max(1, min($days, 35));
        $since = strtotime('today') - ($days * 86400);

        $rows = DB::table('v2_stat_user_device')
            ->where('user_id', $request->user['id'])
            ->where('record_at', '>=', $since)
            ->orderBy('record_at', 'ASC')
            ->get(['record_at', 'peak', 'total', 'samples']);

        return response([
            'data' => $rows->map(function ($r) {
                return [
                    'record_at' => (int) $r->record_at,
                    'peak' => (int) $r->peak,
                    // Rounded here rather than in the client: the divisor is a
                    // detail of how the row is stored, and nothing outside this
                    // file should have to know that samples exist.
                    'avg' => $r->samples > 0
                        ? round($r->total / $r->samples, 2)
                        : 0,
                ];
            }),
        ]);
    }
}
