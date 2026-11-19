<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Serves a node's IP address instead of its host name in subscriptions
 * (setting `subscribe_serve_ips`, off by default).
 *
 * A client must look a host name up before its tunnel exists, and in
 * practice that lookup is plain DNS: the filter sees the node's name and the
 * address it resolves to. For REALITY it also ties the cover site in the
 * TLS handshake to the operator's own domain a moment earlier. With the IP
 * in the subscription (fetched over HTTPS) there is no lookup at all.
 *
 * A swap happens only where nothing else depends on the name: the TLS name
 * (SNI) is pinned to the old host when the node had none, an HTTP-based
 * transport keeps its own Host header or the node is left alone, nodes on
 * Cloudflare addresses keep their name (a CDN edge must be found by name,
 * near the client, not near the panel), and a node tagged `keep-domain` is
 * never touched (the tag itself is not served). Types whose clients need
 * the name for other reasons (MDNS, TrustTunnel, Mihomo-native protocols,
 * v2node) are not touched.
 */
class NodeAddressResolver
{
    public const KEEP_TAG = 'keep-domain';

    /** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/). */
    private const CDN_V4 = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];

    /** Transports that carry an HTTP Host (or authority) header. */
    private const HTTP_NETWORKS = ['ws', 'httpupgrade', 'xhttp', 'splithttp', 'h2', 'http'];

    public static function enabled(): bool
    {
        return (int)config('v2board.subscribe_serve_ips', 0) === 1;
    }

    /** The servers with eligible host names replaced by their address. */
    public static function apply(array $servers, ?callable $resolve = null): array
    {
        if ($resolve === null && !self::enabled()) return $servers;
        $resolve = $resolve ?? [self::class, 'resolveCached'];
        foreach ($servers as $i => $server) {
            if (!is_array($server)) continue;
            $servers[$i] = self::swap($server, $resolve);
        }
        return $servers;
    }

    public static function swap(array $server, callable $resolve): array
    {
        $tags = is_array($server['tags'] ?? null) ? array_values($server['tags']) : null;
        if ($tags !== null && in_array(self::KEEP_TAG, $tags, true)) {
            $server['tags'] = array_values(array_filter($tags, fn ($t) => $t !== self::KEEP_TAG));
            return $server;
        }
        $host = trim((string)($server['host'] ?? ''));
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) return $server;
        $type = (string)($server['type'] ?? '');
        if (!in_array($type, ['vless', 'vmess', 'trojan', 'hysteria', 'tuic', 'anytls', 'shadowsocks'], true)) {
            return $server;
        }
        if (!self::nameFree($server, $type)) return $server;
        $ip = $resolve($host);
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || self::isCdn($ip)) {
            return $server;
        }
        $server = self::pinName($server, $type, $host);
        $server['host'] = $ip;
        return $server;
    }

    /**
     * Whether the node works with an address in place of its name: an HTTP
     * transport must carry its own Host header (clients fall back to the
     * address), and an obfuscated Shadowsocks node is left alone (its
     * plugin's host may fall back too).
     */
    private static function nameFree(array $server, string $type): bool
    {
        if ($type === 'shadowsocks') return empty($server['obfs']);
        $network = strtolower((string)($server['network'] ?? 'tcp'));
        if (!in_array($network, self::HTTP_NETWORKS, true)) return true;
        $settings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
        if (!is_array($settings)) return false;
        $header = $settings['headers']['Host'] ?? ($settings['headers']['host'] ?? ($settings['host'] ?? ''));
        if (is_array($header)) $header = $header[0] ?? '';
        return trim((string)$header) !== '';
    }

    /** The TLS name the node had by default: its host. Kept explicit. */
    private static function pinName(array $server, string $type, string $host): array
    {
        if ($type === 'shadowsocks') return $server;
        if ($type === 'vmess') {
            if ((int)($server['tls'] ?? 0) !== 1) return $server;
            $tls = $server['tlsSettings'] ?? [];
            if (is_string($tls)) $tls = json_decode($tls, true) ?: [];
            if (!is_array($tls)) $tls = [];
            if (trim((string)($tls['serverName'] ?? '')) === '') $tls['serverName'] = $host;
            $server['tlsSettings'] = $tls;
            return $server;
        }
        if (in_array($type, ['vless', 'anytls'], true) && array_key_exists('tls_settings', $server)) {
            $tls = $server['tls_settings'] ?? [];
            if (is_string($tls)) $tls = json_decode($tls, true) ?: [];
            if (!is_array($tls)) $tls = [];
            // REALITY's server_name is the cover site and always set; plain
            // TLS falls back to the host, so it is written out.
            if ((int)($server['tls'] ?? 0) !== 0 && trim((string)($tls['server_name'] ?? '')) === '') {
                $tls['server_name'] = $host;
            }
            $server['tls_settings'] = $tls;
        }
        // A top-level server_name some generators prefer over the nested one:
        // when empty, it takes the name the node already had in effect (the
        // nested TLS name, e.g. REALITY's cover site), else the old host.
        $nested = is_array($server['tls_settings'] ?? null) ? trim((string)($server['tls_settings']['server_name'] ?? '')) : '';
        $effective = $nested !== '' ? $nested : $host;
        if (array_key_exists('server_name', $server) && trim((string)$server['server_name']) === '') {
            $server['server_name'] = $effective;
        } elseif (in_array($type, ['trojan', 'hysteria', 'tuic'], true) && !array_key_exists('server_name', $server)) {
            $server['server_name'] = $effective;
        }
        return $server;
    }

    public static function isCdn(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) return false;
        foreach (self::CDN_V4 as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int)$bits);
            if (($long & $mask) === (ip2long($net) & $mask)) return true;
        }
        return false;
    }

    /** One A record for the name, cached briefly so a subscription stays fast. */
    public static function resolveCached(string $host): ?string
    {
        return Cache::remember('node_address:' . strtolower($host), 600, function () use ($host) {
            $ips = @gethostbynamel($host);
            if (!is_array($ips) || !$ips) return null;
            sort($ips);
            return $ips[0];
        });
    }
}
