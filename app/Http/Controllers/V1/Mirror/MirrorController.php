<?php

namespace App\Http\Controllers\V1\Mirror;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Yes or no: are these an admin's email and password?
     *
     * The relay's admin area signs in with the panel's own admin accounts
     * instead of a password of its own - one fewer secret to keep, change and
     * lose. This is how it asks. It returns a verdict and nothing else: the
     * passport login would mint a session and a token for every sign-in on the
     * relay and leave them in the admin's session list.
     *
     * 🔑 The wrong-password counter is the login page's own, so the relay is not
     * a second door with a fresh set of guesses.
     *
     * The reason is for the relay, never shown as-is: "password" means keep what
     * it remembers (a typo), "account" means forget it (no longer an admin).
     */
    public function adminVerify(Request $request)
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        if ($email === '' || $password === '') {
            return response(['data' => ['ok' => false, 'reason' => 'password']]);
        }

        $limitEnabled = (int) config('v2board.password_limit_enable', 1);
        $limitKey = CacheKey::get('PASSWORD_ERROR_LIMIT', $email);
        $errors = (int) Cache::get($limitKey, 0);
        if ($limitEnabled && $errors >= (int) config('v2board.password_limit_count', 5)) {
            return response(['data' => [
                'ok' => false,
                'reason' => 'locked',
                'minutes' => (int) config('v2board.password_limit_expire', 60),
            ]]);
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response(['data' => ['ok' => false, 'reason' => 'account']]);
        }
        if (!Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password)) {
            if ($limitEnabled) {
                Cache::put($limitKey, $errors + 1, 60 * (int) config('v2board.password_limit_expire', 60));
            }
            return response(['data' => ['ok' => false, 'reason' => 'password']]);
        }
        if (!$user->is_admin || $user->banned) {
            return response(['data' => ['ok' => false, 'reason' => 'account']]);
        }

        return response(['data' => ['ok' => true]]);
    }
}
