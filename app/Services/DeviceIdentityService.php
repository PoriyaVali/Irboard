<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Which devices an account has been used from.
 *
 * The app sends `X-Device-Ids: <kind>:<hash>,...` - keyed HMAC-SHA256 digests
 * of the signals a device offers (ANDROID_ID and the Widevine id on Android,
 * the machine id on Windows). Raw device ids never reach the panel.
 *
 * Stored on the user row itself, in two columns:
 *  - `device_ids`   JSON list of devices, each {"ids":[...],"f":first,"l":last}
 *  - `device_count` how many distinct devices that list holds
 *
 * A DEVICE is the set of hashes one phone presents, not a single hash. A phone
 * sends two, so counting hashes would call every phone two devices. The set
 * also changes over time: a factory reset regenerates ANDROID_ID while the
 * Widevine id usually survives. So a sighting that shares ANY hash with a
 * stored device IS that device, and its new hash is added to it.
 *
 * Finding every account one device has used is a scan of this column:
 *
 *   SELECT id, email, device_count FROM v2_user
 *   WHERE device_ids LIKE '%<64-hex hash>%';
 *
 * A 64-character hex hash cannot match anything by accident, and at a few
 * thousand accounts the scan is milliseconds. MySQL 5.7 cannot index inside a
 * JSON array, so revisit this if the user table grows by orders of magnitude.
 *
 * Everything here is client-supplied. It is a claim, parsed strictly, bounded
 * in size, and it must never be able to fail the request that carries it.
 */
class DeviceIdentityService
{
    /** A header longer than this did not come from the app. */
    const MAX_HEADER = 512;
    /** Pairs read from one header. The app sends at most two today. */
    const MAX_PAIRS = 4;
    /** Hashes kept per device - room for several factory resets. */
    const MAX_IDS_PER_DEVICE = 8;
    /** Devices kept per account; past this the least recently seen goes. */
    const MAX_DEVICES = 20;
    /** Seconds before one account presenting one header is written again. */
    const SEEN_TTL = 21600;

    /**
     * Record the devices in an X-Device-Ids header against an account.
     *
     * Runs on login and on every authenticated request, so the common case has
     * to be nearly free: after the first write, the same account presenting
     * the same header costs one Redis SETNX and nothing else for SEEN_TTL.
     */
    public static function recordFromHeader(int $userId, $header): void
    {
        $gate = null;
        try {
            $ids = self::parseHeader((string)$header);
            if (!$ids) return;
            $gate = 'device_ids_seen_' . $userId . '_' . md5(implode(',', $ids));
            if (!Cache::add($gate, 1, self::SEEN_TTL)) return;
            if (!self::store($userId, $ids, time())) {
                // Nothing was written, so let the next request try again
                // instead of waiting out the gate.
                Cache::forget($gate);
            }
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: on PHP 8 a type error is an \Error,
            // and this runs in front of every authenticated request.
            try {
                if ($gate) Cache::forget($gate);
            } catch (\Throwable $ignored) {
            }
            Log::warning('device identity not recorded', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The valid `kind:hash` pairs in a header, lowercased, de-duplicated and
     * sorted, so one device always produces the same list.
     *
     * A malformed pair is dropped rather than failing the header: one bad
     * pair from some future app version must not cost the good ones beside it.
     * An oversized header is ignored whole - that is not the app talking.
     */
    public static function parseHeader(string $header): array
    {
        if ($header === '' || strlen($header) > self::MAX_HEADER) return [];
        $out = [];
        foreach (explode(',', $header) as $pair) {
            $pair = strtolower(trim($pair));
            if (!preg_match('/^[a-z]{2,16}:[a-f0-9]{64}$/', $pair)) continue;
            $out[$pair] = true;
            if (count($out) >= self::MAX_PAIRS) break;
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /**
     * The device list after one sighting. Pure, so it is testable without a
     * database.
     *
     * Every stored device that shares a hash with the sighting is the same
     * device, and all of them merge into one. That is what stops a phone seen
     * once with only its ANDROID_ID and once with only its Widevine id from
     * being counted twice, the moment one request carries both.
     */
    public static function merge(array $devices, array $ids, int $now): array
    {
        $merged = ['ids' => $ids, 'f' => $now, 'l' => $now];
        $rest = [];
        foreach ($devices as $device) {
            if (!is_array($device) || !isset($device['ids']) || !is_array($device['ids'])) continue;
            $known = array_map('strval', $device['ids']);
            if (array_intersect($known, $ids)) {
                // The sighting's own hashes come first, so when a device has
                // presented more than it may keep, the ones it stopped sending
                // are the ones that fall off.
                $merged['ids'] = array_merge($merged['ids'], $known);
                $merged['f'] = min($merged['f'], (int)($device['f'] ?? $now));
            } else {
                $rest[] = $device;
            }
        }
        $merged['ids'] = array_slice(array_values(array_unique($merged['ids'])), 0, self::MAX_IDS_PER_DEVICE);
        $rest[] = $merged;
        // Most recently seen first; an account over the cap loses the device
        // it has not used for longest.
        usort($rest, function ($a, $b) {
            return ((int)($b['l'] ?? 0)) <=> ((int)($a['l'] ?? 0));
        });
        return array_slice($rest, 0, self::MAX_DEVICES);
    }

    /**
     * Read, merge, and write back only if nobody wrote in between.
     *
     * A compare-and-swap on the old value rather than SELECT ... FOR UPDATE:
     * node traffic reports update this same row all day, and a row lock held
     * across PHP work would queue them behind it or deadlock with them.
     * DB::table rather than the model, so updated_at does not move - noticing
     * a device is not the account changing.
     */
    private static function store(int $userId, array $ids, int $now): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = DB::table('v2_user')->where('id', $userId)->first(['device_ids']);
            if (!$row) return false;
            $old = $row->device_ids;
            $devices = $old ? json_decode($old, true) : [];
            if (!is_array($devices)) $devices = [];

            $new = self::merge($devices, $ids, $now);
            $encoded = json_encode($new, JSON_UNESCAPED_SLASHES);
            // MySQL reports 0 affected rows for an UPDATE that changes nothing,
            // which would read as a lost race below.
            if ($encoded === $old) return true;

            $query = DB::table('v2_user')->where('id', $userId);
            if ($old === null) {
                $query->whereNull('device_ids');
            } else {
                $query->where('device_ids', $old);
            }
            if ($query->update(['device_ids' => $encoded, 'device_count' => count($new)])) {
                return true;
            }
            // Another request changed the row first: read it again and merge
            // onto what it wrote.
        }
        return false;
    }
}
