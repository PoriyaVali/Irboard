<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Which network each device of an account is on now.
 *
 * The app knows: on mobile data from the SIM (MCC+MNC), on Wi-Fi from the
 * relay, which matches the address the line reaches it from against every
 * Iranian network's ranges. It sends what it found on authenticated requests
 * as
 *
 *   X-Network: t=cellular;op=irancell;mnc=43235
 *   X-Network: t=wifi;op=tci;as=58224;nm=Iran Telecommunication Company PJS
 *   X-Network: t=wifi;op=as12345;as=12345;nm=Some Iranian ISP
 *
 *  - t   cellular, wifi, ethernet or other (required)
 *  - op  the operator id the app and the app-config document use (mci,
 *        irancell, rightel, shatel, tci, ...), or `as<number>` for a network
 *        without one
 *  - mnc the SIM's MCC+MNC, on mobile data
 *  - as  the line's ASN, when the relay was asked
 *  - nm  the network's registered name (ASCII), when the relay was asked
 *
 * so the admin can see who is on which network, and judge what the
 * per-operator settings in the app-config document should be.
 *
 * Stored in v2_user_network, one row per account and device (the device is
 * the first 16 hex of its most stable X-Device-Ids hash, the same one the app
 * counts devices by), moved in place as the device changes networks.
 *
 * Like X-Device-Ids this is a client claim: parsed strictly, bounded, written
 * at once when a device's network changes and otherwise once every SEEN_TTL,
 * and it can never fail the request that carries it.
 */
class UserNetworkService
{
    /** A header longer than this did not come from the app. */
    const MAX_HEADER = 256;
    /** Seconds before the same report from the same device is written again. */
    const SEEN_TTL = 600;
    /** Devices kept per account; the one heard from least recently goes. */
    const MAX_DEVICES = 8;

    const TRANSPORTS = ['cellular', 'wifi', 'ethernet', 'other'];

    /** The order the app picks a device's stable id in (DeviceIdentity.primaryOf). */
    const DEVICE_KINDS = ['androidid', 'smbios', 'machineid', 'widevine'];

    public static function recordFromHeader(int $userId, $header, $deviceIdsHeader = null): void
    {
        $gate = null;
        try {
            $network = self::parseHeader((string)$header);
            if (!$network) return;
            $device = self::deviceKey((string)$deviceIdsHeader);
            // The gate holds the network last written for this device, not
            // one flag per network: a phone going Wi-Fi -> mobile -> Wi-Fi
            // within the window must end on Wi-Fi, which a per-network flag
            // still set from the first Wi-Fi report would skip.
            $gate = 'user_net_' . $userId . '_' . $device;
            $signature = md5(json_encode($network));
            if (Cache::get($gate) === $signature) return;
            Cache::put($gate, $signature, self::SEEN_TTL);
            self::store($userId, $device, $network, time());
        } catch (\Throwable $e) {
            try {
                if ($gate) Cache::forget($gate);
            } catch (\Throwable $ignored) {
            }
            Log::warning('network not recorded', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The report in an X-Network header, or null when it is not one.
     *
     * A field that does not parse is dropped and the rest kept; a missing or
     * unknown transport drops the whole header - without it nothing else
     * means anything.
     */
    public static function parseHeader(string $header): ?array
    {
        if ($header === '' || strlen($header) > self::MAX_HEADER) return null;
        $fields = [];
        foreach (explode(';', $header) as $part) {
            $at = strpos($part, '=');
            if ($at === false) continue;
            $key = strtolower(trim(substr($part, 0, $at)));
            if (!isset($fields[$key])) $fields[$key] = trim(substr($part, $at + 1));
        }
        $transport = strtolower($fields['t'] ?? '');
        if (!in_array($transport, self::TRANSPORTS, true)) return null;

        $operator = strtolower($fields['op'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $operator)) $operator = null;
        $mccmnc = $fields['mnc'] ?? '';
        if (!preg_match('/^\d{5,6}$/', $mccmnc)) $mccmnc = null;
        $asn = $fields['as'] ?? '';
        $asn = preg_match('/^\d{1,10}$/', $asn) && (int)$asn > 0 && (int)$asn <= 4294967295 ? (int)$asn : null;
        $name = $fields['nm'] ?? '';
        $name = preg_match("/^[A-Za-z0-9 .,'()&\\/_+-]{1,64}$/", $name) ? trim($name) : null;
        if ($name === '') $name = null;

        return [
            'transport' => $transport,
            'operator' => $operator,
            'mccmnc' => $transport === 'cellular' ? $mccmnc : null,
            'asn' => $asn,
            'name' => $name,
        ];
    }

    /**
     * The device a report came from: 16 hex of its most stable hash, in the
     * app's own order, or '' for a request without X-Device-Ids.
     */
    public static function deviceKey(string $deviceIdsHeader): string
    {
        $pairs = DeviceIdentityService::parseHeader($deviceIdsHeader);
        if (!$pairs) return '';
        $byKind = [];
        foreach ($pairs as $pair) {
            [$kind, $hash] = explode(':', $pair, 2);
            $byKind[$kind] = $hash;
        }
        foreach (self::DEVICE_KINDS as $kind) {
            if (isset($byKind[$kind])) return substr($byKind[$kind], 0, 16);
        }
        return substr(reset($byKind), 0, 16);
    }

    /**
     * Writes one report. `changed_at` moves only when the network does, so the
     * admin can read "on Irancell for 2 hours" and not just "last seen".
     */
    public static function store(int $userId, string $device, array $network, int $now): void
    {
        $table = DB::table('v2_user_network');
        $row = (clone $table)->where('user_id', $userId)->where('device', $device)->first();
        $values = $network + ['seen_at' => $now];
        if ($row) {
            if (!self::sameNetwork($row, $network)) $values['changed_at'] = $now;
            (clone $table)->where('user_id', $userId)->where('device', $device)->update($values);
            return;
        }
        try {
            (clone $table)->insert($values + ['user_id' => $userId, 'device' => $device, 'changed_at' => $now]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Another request of the same device inserted first.
            (clone $table)->where('user_id', $userId)->where('device', $device)->update($values);
            return;
        }
        // A new device: keep the account's list bounded. The header is a
        // client claim, so invented devices push out only each other and the
        // devices not heard from for longest.
        $keep = (clone $table)->where('user_id', $userId)->orderByDesc('seen_at')->limit(self::MAX_DEVICES)->pluck('device')->all();
        (clone $table)->where('user_id', $userId)->whereNotIn('device', $keep)->delete();
    }

    private static function sameNetwork($row, array $network): bool
    {
        return (string)$row->transport === $network['transport']
            && ($row->operator === null ? null : (string)$row->operator) === $network['operator']
            && ($row->asn === null ? null : (int)$row->asn) === $network['asn']
            && ($row->mccmnc === null ? null : (string)$row->mccmnc) === $network['mccmnc'];
    }

    /**
     * Each account's devices' networks, most recent first, keyed by user id.
     * An install whose database has not been updated yet answers nothing
     * rather than failing the admin screen around it.
     */
    public static function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) return [];
        try {
            $rows = DB::table('v2_user_network')->whereIn('user_id', $userIds)->orderByDesc('seen_at')->get();
        } catch (\Throwable $e) {
            Log::warning('user networks not read', ['error' => $e->getMessage()]);
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row->user_id][] = self::present($row);
        }
        return $out;
    }

    public static function present($row): array
    {
        return [
            'device' => (string)$row->device,
            'transport' => (string)$row->transport,
            'operator' => $row->operator === null ? null : (string)$row->operator,
            'mccmnc' => $row->mccmnc === null ? null : (string)$row->mccmnc,
            'asn' => $row->asn === null ? null : (int)$row->asn,
            'name' => $row->name === null ? null : (string)$row->name,
            'changed_at' => (int)$row->changed_at,
            'seen_at' => (int)$row->seen_at,
        ];
    }
}
