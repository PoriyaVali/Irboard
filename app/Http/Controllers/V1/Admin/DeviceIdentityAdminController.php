<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reading what DeviceIdentityService records.
 *
 * The app sends `X-Device-Ids`, and DeviceIdentityService folds it onto the
 * user row as `device_ids` (a JSON list) plus `device_count`. This controller
 * is the admin's side of that: it only reads. Blocking and deleting are a
 * later step on purpose - a device block means banning accounts, and this panel
 * has no undo for that, so it should not ride in on the same change as the
 * first view of the data.
 *
 * 🔴 `v2_user_banned_backup` is NOT free space to write that undo into. No code
 * in this repository touches it, yet production holds 1031 rows, every one
 * `banned = 1`, while not one account is banned today. Among them are 120 live
 * subscriptions, 136 accounts that moved traffic in the last 30 days, 80 who
 * paid by card, and a staff account - so it is the record of a bulk ban that
 * was reverted, not a list of abusers. `v2_log` only reaches back to
 * 2026-08-14 and holds no trace of it. Overwriting it would erase the only
 * evidence left; a device-ban undo needs its own table.
 *
 * The point of the whole mechanism is `shared()`: one device behind many
 * accounts. `fetch()` and `lookup()` exist to answer "and who are they".
 *
 * 🔑 `device_ids` has no Eloquent cast (User::$casts carries only the two
 * timestamps), so it arrives as a raw string and is decoded by hand here.
 * Every read tolerates a row that is null, empty, invalid JSON or the wrong
 * shape: this column is written from a client-supplied header, and an admin
 * screen must not 500 because one row is odd.
 */
class DeviceIdentityAdminController extends Controller
{
    /** A hash pair as DeviceIdentityService::parseHeader accepts it. */
    const PAIR_RE = '/^[a-z]{2,16}:[a-f0-9]{64}$/';
    /** A bare digest, which is what an admin will paste most of the time. */
    const HASH_RE = '/^[a-f0-9]{64}$/';

    /**
     * Decode one row's `device_ids` into a list of well-formed devices.
     *
     * Anything that is not a device with at least one string hash is dropped
     * rather than repaired - a half-understood row is worse than a missing one.
     */
    private function devices($raw): array
    {
        if (!is_string($raw) || $raw === '') return [];
        $list = json_decode($raw, true);
        if (!is_array($list)) return [];

        $out = [];
        foreach ($list as $device) {
            if (!is_array($device) || !isset($device['ids']) || !is_array($device['ids'])) continue;
            $ids = [];
            foreach ($device['ids'] as $id) {
                if (is_string($id) && $id !== '') $ids[] = $id;
            }
            if (!$ids) continue;
            $out[] = [
                'ids'   => $ids,
                // `kind` is what an operator actually reads - "androidid",
                // "widevine", "machineid", "smbios" - so split it out here
                // rather than making the browser parse hashes.
                'kinds' => array_values(array_unique(array_map(function ($id) {
                    $at = strpos($id, ':');
                    return $at === false ? '?' : substr($id, 0, $at);
                }, $ids))),
                'first_seen' => (int)($device['f'] ?? 0),
                'last_seen'  => (int)($device['l'] ?? 0),
                'sightings'  => max(1, (int)($device['n'] ?? 1)),
            ];
        }
        return $out;
    }

    /**
     * Accounts that have at least one recorded device.
     *
     * Paginated the way the other admin lists are, so the table component and
     * the operator's habits both keep working: `current` / `pageSize` in,
     * `data` + `total` out.
     */
    public function fetch(Request $request)
    {
        $current  = (int)$request->input('current') ?: 1;
        $pageSize = (int)$request->input('pageSize') >= 10 ? (int)$request->input('pageSize') : 20;

        $builder = DB::table('v2_user')
            ->where('device_count', '>', 0);

        // One search box, matching how CardPaymentController does it.
        $search = trim((string)$request->input('search', ''));
        if ($search !== '') {
            $builder->where('email', 'like', "%{$search}%");
        }

        $total = (clone $builder)->count();

        $rows = $builder
            ->orderByDesc('device_count')
            ->orderByDesc('id')
            ->forPage($current, $pageSize)
            ->get(['id', 'email', 'device_count', 'device_ids', 'banned', 'plan_id', 'expired_at']);

        $data = $rows->map(function ($r) {
            return [
                'user_id'      => (int)$r->id,
                'email'        => (string)$r->email,
                'device_count' => (int)$r->device_count,
                'banned'       => (bool)$r->banned,
                'expired_at'   => $r->expired_at === null ? null : (int)$r->expired_at,
                'devices'      => $this->devices($r->device_ids),
            ];
        });

        return response([
            'data'  => $data,
            'total' => $total,
        ]);
    }

    /**
     * 🔑 Devices seen on more than one account - the reason this exists.
     *
     * A device is a SET of hashes, and the sets drift (a factory reset makes a
     * new ANDROID_ID while the Widevine id survives), so two accounts count as
     * sharing a device when they share ANY hash. Grouping by a single hash is
     * therefore the correct join key here, even though a device is not a hash.
     *
     * Done in PHP, not SQL: MySQL 5.7 cannot index inside a JSON array, and at
     * this size it does not need to. 3,169 accounts hold 1.5 MB in total, and
     * only rows with device_count > 0 are read at all - today that is a handful
     * and it will stay small relative to the table for a long time. Revisit
     * with a proper join table if this ever becomes slow; do not pre-build one
     * for a scan that costs milliseconds.
     */
    public function shared(Request $request)
    {
        $minAccounts = (int)$request->input('min_accounts') ?: 2;
        if ($minAccounts < 2) $minAccounts = 2;

        $rows = DB::table('v2_user')
            ->where('device_count', '>', 0)
            ->get(['id', 'email', 'device_ids', 'banned']);

        // hash => [user_id => account summary]
        $byHash = [];
        foreach ($rows as $r) {
            foreach ($this->devices($r->device_ids) as $device) {
                foreach ($device['ids'] as $hash) {
                    if (!isset($byHash[$hash])) $byHash[$hash] = [];
                    $byHash[$hash][(int)$r->id] = [
                        'user_id'    => (int)$r->id,
                        'email'      => (string)$r->email,
                        'banned'     => (bool)$r->banned,
                        'last_seen'  => $device['last_seen'],
                        'sightings'  => $device['sightings'],
                    ];
                }
            }
        }

        $out = [];
        foreach ($byHash as $hash => $accounts) {
            if (count($accounts) < $minAccounts) continue;
            $at = strpos($hash, ':');
            $accounts = array_values($accounts);
            usort($accounts, function ($a, $b) {
                return $b['last_seen'] <=> $a['last_seen'];
            });
            $out[] = [
                'hash'     => $hash,
                'kind'     => $at === false ? '?' : substr($hash, 0, $at),
                'accounts' => $accounts,
                'count'    => count($accounts),
            ];
        }

        // Worst first: the device behind the most accounts is the one to look at.
        usort($out, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return response([
            'data'  => $out,
            'total' => count($out),
            // So the screen can say "nothing shared yet" honestly rather than
            // looking broken when the answer is genuinely an empty list.
            'scanned_accounts' => $rows->count(),
        ]);
    }

    /**
     * Reverse lookup: which accounts hold this hash, or what does this account hold.
     *
     * Accepts a bare 64-hex digest, a full `kind:hash` pair, or an email. A
     * 64-character hex string cannot collide with anything by accident, which
     * is what makes the LIKE scan safe as well as fast.
     */
    public function lookup(Request $request)
    {
        $q = strtolower(trim((string)$request->input('q', '')));
        if ($q === '') abort(500, 'چیزی برای جست‌وجو وارد نشده است');

        $isPair = (bool)preg_match(self::PAIR_RE, $q);
        $isHash = (bool)preg_match(self::HASH_RE, $q);

        if (!$isPair && !$isHash) {
            // Treat it as an email. Exact, not LIKE: fetch() already has the
            // fuzzy search box, and an admin pasting an address wants that
            // account, not everything containing it.
            $row = DB::table('v2_user')
                ->where('email', $q)
                ->first(['id', 'email', 'device_count', 'device_ids', 'banned']);
            if (!$row) abort(500, 'کاربری با این ایمیل یافت نشد');

            return response([
                'data' => [
                    'mode'     => 'email',
                    'query'    => $q,
                    'accounts' => [[
                        'user_id'      => (int)$row->id,
                        'email'        => (string)$row->email,
                        'banned'       => (bool)$row->banned,
                        'device_count' => (int)$row->device_count,
                        'devices'      => $this->devices($row->device_ids),
                    ]],
                ],
            ]);
        }

        // The stored form is always `kind:hash`. A pair matches the whole
        // token; a bare digest matches the tail of one, which is why the
        // confirmation below compares with substr(-64) rather than equality.
        $needle = $q;

        $rows = DB::table('v2_user')
            ->where('device_ids', 'like', '%' . $needle . '%')
            ->limit(200)
            ->get(['id', 'email', 'device_count', 'device_ids', 'banned']);

        $accounts = [];
        foreach ($rows as $r) {
            $devices = $this->devices($r->device_ids);
            // The LIKE narrowed it; this confirms it. A substring match on the
            // column is not proof the hash is really one of this row's ids.
            $hit = false;
            foreach ($devices as $d) {
                foreach ($d['ids'] as $id) {
                    if ($id === $needle || ($isHash && substr($id, -64) === $needle)) {
                        $hit = true;
                        break 2;
                    }
                }
            }
            if (!$hit) continue;

            $accounts[] = [
                'user_id'      => (int)$r->id,
                'email'        => (string)$r->email,
                'banned'       => (bool)$r->banned,
                'device_count' => (int)$r->device_count,
                'devices'      => $devices,
            ];
        }

        return response([
            'data' => [
                'mode'     => $isPair ? 'pair' : 'hash',
                'query'    => $q,
                'accounts' => $accounts,
            ],
        ]);
    }

    /**
     * Ban an explicit list of accounts, recording what each one was first.
     *
     * 🔴 Takes `user_ids`, never a hash. Banning "everyone on this hash" in one
     * server-side sweep would mean a duplicated Widevine id - which Android does
     * not guarantee against - could ban strangers the admin never saw. The
     * screen shows the accounts, the admin ticks them, and only those ids arrive
     * here. The hash comes along only so the record says what they were matched
     * by.
     *
     * Reversible by construction: `was_banned` is written before `banned` is
     * changed, and one click is one `batch`, so [[revert]] can put back exactly
     * what this changed and nothing else.
     *
     * Mirrors UserController@ban: sessions are dropped first, then the flag is
     * set. That is what makes it bite - UserService::isAvailable() checks
     * `banned` and seven call sites use it, including the subscription itself.
     */
    public function ban(Request $request)
    {
        $ids = $this->userIds($request);
        $hash = strtolower(trim((string)$request->input('hash', '')));
        if ($hash !== '' && !preg_match(self::PAIR_RE, $hash) && !preg_match(self::HASH_RE, $hash)) {
            abort(500, 'شناسه‌ی دستگاه معتبر نیست');
        }

        $admin = (int)($request->user['id'] ?? 0);
        $batch = Helper::guid();
        $now = time();

        $users = User::whereIn('id', $ids)->get(['id', 'banned', 'is_admin', 'is_staff']);
        if ($users->isEmpty()) abort(500, 'کاربری برای مسدود کردن یافت نشد');

        // An admin or staff account reached this screen once already, in the
        // 1031-row backup of a bulk ban that had to be undone. Refuse rather
        // than let one click lock the operator out of their own panel.
        foreach ($users as $u) {
            if ($u->is_admin || $u->is_staff) {
                abort(500, 'حساب ادمین یا نماینده را نمی‌توان از این صفحه مسدود کرد');
            }
        }

        $done = [];
        foreach ($users as $u) {
            // Written BEFORE the change, so an interrupted run still leaves a
            // complete record of what to restore.
            DB::table('v2_device_ban')->insert([
                'batch'      => $batch,
                'hash'       => $hash,
                'user_id'    => (int)$u->id,
                'was_banned' => (int)$u->banned,
                'admin_id'   => $admin ?: null,
                'created_at' => $now,
            ]);
            try {
                (new AuthService($u))->removeAllSession();
            } catch (\Throwable $e) {
                // A session store that will not answer must not leave the
                // account unbanned; the flag is what actually stops service.
                Log::warning('device ban: sessions not cleared', [
                    'user_id' => $u->id, 'error' => $e->getMessage(),
                ]);
            }
            DB::table('v2_user')->where('id', $u->id)->update(['banned' => 1]);
            $done[] = (int)$u->id;
        }

        return response([
            'data' => ['batch' => $batch, 'banned' => $done, 'count' => count($done)],
        ]);
    }

    /**
     * Put a batch back exactly as it was.
     *
     * Restores each account to its recorded `was_banned` rather than simply
     * unbanning: an account that was already banned before this batch must stay
     * banned, or an undo would quietly release someone banned for another
     * reason.
     */
    public function revert(Request $request)
    {
        $batch = trim((string)$request->input('batch', ''));
        if ($batch === '') abort(500, 'شناسه‌ی عملیات وارد نشده است');

        $rows = DB::table('v2_device_ban')
            ->where('batch', $batch)
            ->whereNull('reverted_at')
            ->get(['id', 'user_id', 'was_banned']);
        if ($rows->isEmpty()) abort(500, 'این عملیات یافت نشد یا قبلاً برگردانده شده است');

        $now = time();
        foreach ($rows as $r) {
            DB::table('v2_user')->where('id', $r->user_id)->update(['banned' => (int)$r->was_banned]);
            DB::table('v2_device_ban')->where('id', $r->id)->update(['reverted_at' => $now]);
        }

        return response(['data' => ['batch' => $batch, 'restored' => $rows->count()]]);
    }

    /** What device bans have been made, newest first. */
    public function bans(Request $request)
    {
        $rows = DB::table('v2_device_ban as b')
            ->leftJoin('v2_user as u', 'u.id', '=', 'b.user_id')
            ->orderByDesc('b.created_at')
            ->orderByDesc('b.id')
            ->limit(200)
            ->get(['b.batch', 'b.hash', 'b.user_id', 'b.was_banned', 'b.admin_id',
                   'b.reverted_at', 'b.created_at', 'u.email', 'u.banned']);

        return response(['data' => $rows->map(function ($r) {
            return [
                'batch'       => $r->batch,
                'hash'        => $r->hash,
                'user_id'     => (int)$r->user_id,
                'email'       => (string)($r->email ?? ''),
                'was_banned'  => (bool)$r->was_banned,
                'banned_now'  => (bool)$r->banned,
                'admin_id'    => $r->admin_id === null ? null : (int)$r->admin_id,
                'reverted_at' => $r->reverted_at === null ? null : (int)$r->reverted_at,
                'created_at'  => (int)$r->created_at,
            ];
        })]);
    }

    /**
     * Drop one device from one account's list.
     *
     * ⚠️ Housekeeping, not control. The app sends `X-Device-Ids` on every
     * authenticated request, so a device removed from an account that still
     * works will be recorded again within six hours (the service's write gate).
     * Use it to clean a wrong record; use [[ban]] to stop someone.
     *
     * 🔑 Written with the same compare-and-set loop DeviceIdentityService::store
     * uses, and for the same reason: that writer conditions its UPDATE on the
     * whole previous column value. A plain UPDATE here would race it, and one of
     * the two writes would vanish - most likely this one, silently.
     */
    public function forget(Request $request)
    {
        $userId = (int)$request->input('user_id');
        $hash = strtolower(trim((string)$request->input('hash', '')));
        if (!$userId) abort(500, 'کاربر مشخص نشده است');
        if (!preg_match(self::PAIR_RE, $hash) && !preg_match(self::HASH_RE, $hash)) {
            abort(500, 'شناسه‌ی دستگاه معتبر نیست');
        }
        $bare = strlen($hash) === 64;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = DB::table('v2_user')->where('id', $userId)->first(['device_ids']);
            if (!$row) abort(500, 'کاربر یافت نشد');
            $old = $row->device_ids;
            $devices = $old ? json_decode($old, true) : [];
            if (!is_array($devices)) $devices = [];

            $kept = [];
            $removed = 0;
            foreach ($devices as $d) {
                $ids = (is_array($d) && isset($d['ids']) && is_array($d['ids'])) ? $d['ids'] : [];
                $match = false;
                foreach ($ids as $id) {
                    if (!is_string($id)) continue;
                    if ($id === $hash || ($bare && substr($id, -64) === $hash)) { $match = true; break; }
                }
                if ($match) { $removed++; continue; }
                $kept[] = $d;
            }
            if (!$removed) abort(500, 'این دستگاه روی این حساب ثبت نشده است');

            $encoded = json_encode(array_values($kept), JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) abort(500, 'ذخیره‌سازی ناموفق بود');

            $query = DB::table('v2_user')->where('id', $userId);
            if ($old === null) $query->whereNull('device_ids');
            else $query->where('device_ids', $old);

            if ($query->update(['device_ids' => $encoded, 'device_count' => count($kept)])) {
                return response(['data' => ['user_id' => $userId, 'removed' => $removed,
                                            'remaining' => count($kept)]]);
            }
            // Someone wrote first - read again and redo the removal on top.
        }
        abort(500, 'حساب هم‌زمان تغییر کرد؛ دوباره تلاش کنید');
    }

    /**
     * The ticked accounts, validated.
     *
     * Bounded because this arrives from a browser: a request asking to ban
     * thousands of ids is not an operator ticking boxes.
     */
    private function userIds(Request $request): array
    {
        $raw = $request->input('user_ids');
        if (!is_array($raw) || !$raw) abort(500, 'هیچ حسابی انتخاب نشده است');
        if (count($raw) > 100) abort(500, 'تعداد حساب‌های انتخاب‌شده بیش از حد مجاز است');
        $ids = [];
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) $ids[$id] = true;
        }
        if (!$ids) abort(500, 'هیچ حساب معتبری انتخاب نشده است');
        return array_keys($ids);
    }
}
