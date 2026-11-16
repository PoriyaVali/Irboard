<?php

namespace App\Services;

/**
 * Which firewall ports the nodes running on THIS server need.
 *
 * A node can run on the panel's own server (its V2bX next to the panel). Its
 * listening port then has to be open in this server's firewall, and closed
 * again once no node needs it. The server-config agent (root) applies that;
 * this class only decides it, so the decision can be tested without a
 * firewall, a database or a framework.
 *
 * A node counts as local when either
 *  - the V2bX serving it last reported from one of this server's own
 *    addresses (not loopback: see normalizedIps), or
 *  - its host is one of those addresses, or a name resolving to one.
 * The first covers a node behind a CDN or a relay, whose host does not point
 * here although its listener does; the second opens the port before V2bX has
 * reported for the first time.
 *
 * Several nodes may share a port: the result is the union, each entry naming
 * every node that needs it, so a port stays open until the last of them goes.
 */
class LocalNodePorts
{
    /** Node types V2bX itself serves (the protocol nodes only when managed). */
    public const NATIVE_TYPES = [
        'vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria', 'tuic',
        'anytls', 'v2node', 'mdns', 'trusttunnel',
    ];

    /**
     * Protocols each type listens with. Types not listed listen with both,
     * which only ever opens a port no one answers on - never closes one that
     * is needed.
     */
    private const PROTOCOLS = [
        'vless' => ['tcp'], 'vmess' => ['tcp'], 'trojan' => ['tcp'], 'anytls' => ['tcp'],
        'v2node' => ['tcp'], 'sudoku' => ['tcp'], 'ssh' => ['tcp'], 'http' => ['tcp'],
        'hysteria' => ['udp'], 'tuic' => ['udp'], 'shadowquic' => ['udp'],
        'wireguard' => ['udp'], 'masque' => ['udp'],
    ];

    /** Transports carried over UDP when a TCP-type node uses them. */
    private const UDP_NETWORKS = ['kcp', 'mkcp', 'quic'];

    /** The models of every node type: a change to one may move a port. */
    public const NODE_MODELS = [
        \App\Models\ServerVless::class, \App\Models\ServerVmess::class, \App\Models\ServerTrojan::class,
        \App\Models\ServerShadowsocks::class, \App\Models\ServerHysteria::class, \App\Models\ServerTuic::class,
        \App\Models\ServerAnytls::class, \App\Models\ServerV2node::class, \App\Models\ServerMdns::class,
        \App\Models\ServerTrusttunnel::class,
    ];

    /**
     * The file whose presence asks the agent to reconcile at once rather than
     * at its next minute. Written by the panel (web user), removed by the
     * agent (root).
     */
    public static function flagPath(): string
    {
        return storage_path('node_ports_dirty');
    }

    /** A node was saved or deleted: ask the agent to look again now. */
    public static function markDirty(): void
    {
        @touch(self::flagPath());
    }

    /**
     * The ports the local nodes need, sorted, as
     * [['port' => '2053', 'proto' => 'tcp', 'nodes' => ['vless#5']], ...].
     * A port range is written "2000-2010".
     *
     * @param array    $servers     rows as ServerService::getAllServers() gives them
     * @param string[] $localIps    this server's addresses
     * @param array    $reporterIps "type#id" => the address its V2bX last reported from
     * @param callable $resolve     host name => list of its addresses
     */
    public static function desired(array $servers, array $localIps, array $reporterIps, callable $resolve): array
    {
        $local = self::normalizedIps($localIps);
        $resolved = [];
        $ports = [];
        foreach ($servers as $server) {
            if (!self::servedByV2bx($server)) continue;
            $ref = ($server['type'] ?? '?') . '#' . ($server['id'] ?? '?');
            $reporter = $reporterIps[$ref] ?? null;
            $here = ($reporter !== null && isset($local[self::normalizeIp($reporter)]))
                || self::hostIsLocal((string)($server['host'] ?? ''), $local, $resolve, $resolved);
            if (!$here) continue;
            $spec = self::portSpec($server);
            if ($spec === null) continue;
            foreach (self::protocols($server) as $proto) {
                $key = $spec . '/' . $proto;
                $ports[$key] ??= ['port' => $spec, 'proto' => $proto, 'nodes' => []];
                $ports[$key]['nodes'][] = $ref;
            }
        }
        uksort($ports, function ($a, $b) {
            return [(int)$a, $a] <=> [(int)$b, $b];
        });
        return array_values($ports);
    }

    /**
     * Whether this row is a listener V2bX runs: a native node, or a managed
     * protocol node. A child node (parent_id) shares its parent's listener;
     * an unmanaged protocol node is someone else's server the panel only
     * lists.
     */
    public static function servedByV2bx(array $server): bool
    {
        if (!empty($server['parent_id'])) return false;
        $type = (string)($server['type'] ?? '');
        if (in_array($type, self::NATIVE_TYPES, true)) return true;
        return !empty($server['managed']);
    }

    /** "2053", a Mieru range "2000-2010", or null when there is no valid port. */
    public static function portSpec(array $server): ?string
    {
        if (($server['type'] ?? '') === 'mieru') {
            $range = trim((string)($server['port_range'] ?? ''));
            if (preg_match('/^(\d{1,5})\s*-\s*(\d{1,5})$/', $range, $m)) {
                $from = (int)$m[1];
                $to = (int)$m[2];
                if ($from >= 1 && $to <= 65535 && $from <= $to) return $from === $to ? (string)$from : "$from-$to";
            }
        }
        $port = $server['server_port'] ?? null;
        if (!is_numeric($port)) $port = $server['port'] ?? null;
        if (!is_numeric($port)) return null;
        $port = (int)$port;
        return ($port >= 1 && $port <= 65535) ? (string)$port : null;
    }

    /** The protocols this node listens with. */
    public static function protocols(array $server): array
    {
        $type = (string)($server['type'] ?? '');
        if ($type === 'mieru') {
            $transport = strtoupper(trim((string)($server['transport'] ?? '')));
            if ($transport === 'TCP') return ['tcp'];
            if ($transport === 'UDP') return ['udp'];
            return ['tcp', 'udp'];
        }
        $protos = self::PROTOCOLS[$type] ?? ['tcp', 'udp'];
        if ($protos === ['tcp'] && in_array(strtolower((string)($server['network'] ?? '')), self::UDP_NETWORKS, true)) {
            return ['udp'];
        }
        return $protos;
    }

    private static function hostIsLocal(string $host, array $local, callable $resolve, array &$resolved): bool
    {
        $host = trim($host, " \t[]");
        if ($host === '') return false;
        if (filter_var($host, FILTER_VALIDATE_IP)) return isset($local[self::normalizeIp($host)]);
        $resolved[$host] ??= (array)$resolve($host);
        foreach ($resolved[$host] as $ip) {
            if (isset($local[self::normalizeIp((string)$ip)])) return true;
        }
        return false;
    }

    /**
     * The addresses as a lookup set, loopback left out on purpose. The panel
     * is also reached through SSH tunnels, whose requests arrive from
     * 127.0.0.1 whichever server the V2bX behind them runs on: counting
     * loopback would call every tunnelled node local.
     */
    private static function normalizedIps(array $ips): array
    {
        $set = [];
        foreach ($ips as $ip) {
            $ip = self::normalizeIp((string)$ip);
            if ($ip === '' || $ip === '::1' || str_starts_with($ip, '127.')) continue;
            $set[$ip] = true;
        }
        return $set;
    }

    /** "::ffff:1.2.3.4" and "1.2.3.4" alike; IPv6 in its canonical form. */
    private static function normalizeIp(string $ip): string
    {
        $ip = trim($ip, " \t[]");
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($ip, 7);
        }
        $packed = @inet_pton($ip);
        return $packed === false ? '' : (string)inet_ntop($packed);
    }
}
