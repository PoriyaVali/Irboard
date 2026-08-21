<?php

namespace App\Http\Controllers\V1\Mirror;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hands the Iranian relay the snapshot `mirror:build` prepared.
 *
 * Deliberately dull: it reads rows and pages them. Everything expensive or
 * side-effecting happens in the command, so this endpoint cannot hold a webman
 * worker open, cannot contaminate its own response with headers a renderer set,
 * and cannot become slower because a user's server list grew.
 */
class MirrorController extends Controller
{
    public function export(Request $request)
    {
        $size = max(1, min(200, (int) config('mirror.page_size', 50)));

        // The cursor is the last id of the previous page. An id rather than an
        // offset: the build runs on its own schedule, and a row inserted
        // between two pages would make an offset skip a user - silently, and
        // only for that one sync, which is the kind of gap nobody ever traces.
        $cursor = (int) $request->input('cursor', 0);

        $rows = DB::table('v2_mirror_export')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($size + 1)
            ->get(['id', 'payload', 'built_at']);

        // One extra row was asked for purely to answer "is there more" without
        // a second COUNT over a table the build is writing to.
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size);

        $users = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            if (!is_array($payload)) {
                continue;
            }
            $payload['built_at'] = (int) $row->built_at;
            $users[] = $payload;
        }

        return response([
            'users' => $users,
            'next' => $hasMore && $rows->count() ? (string) $rows->last()->id : null,
            // The oldest row in the whole table, so the relay can tell the
            // difference between "the sync ran" and "the sync ran and the data
            // it copied was already three days old". Those look identical from
            // the relay's side otherwise.
            'built_at_min' => (int) DB::table('v2_mirror_export')->min('built_at'),
            'total' => (int) DB::table('v2_mirror_export')->count(),
        ]);
    }
}
