<?php

namespace App\Services;

/** Read-only client endpoints. This list never controls firewall rules. */
class NodePortTargets
{
    private const TCP_TYPES = [
        'vless', 'vmess', 'trojan', 'shadowsocks', 'anytls', 'v2node',
        'trusttunnel', 'ssr', 'socks5', 'http', 'snell',
        'gostrelay', 'ssh', 'sudoku',
    ];

    public static function fromServers(array $servers): array
    {
        $targets = [];
        foreach ($servers as $server) {
            $type = strtolower((string)($server['type'] ?? ''));
            $id = (string)($server['id'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $type)
                || !ctype_digit($id) || (int)$id < 1) continue;
            $host = self::host((string)($server['host'] ?? ''));
            $port = self::port($server);
            $transport = self::transport($server);
            if ($host === '' || $port === null || strpos($port, '-') !== false) $transport = 'unknown';
            $targets[] = [
                'node' => $type . '#' . (int)$id,
                'host' => $host,
                'port' => $port,
                'transport' => $transport,
                'updated_at' => max(0, (int)($server['updated_at'] ?? 0)),
            ];
        }
        return $targets;
    }

    public static function host(string $host): string
    {
        $host = strtolower(trim($host));
        if (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']') $host = substr($host, 1, -1);
        if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
        if (strlen($host) <= 253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/', $host)) return $host;
        return '';
    }

    /** The client's connection port only; never fall back to server_port. */
    public static function port(array $server): ?string
    {
        $value = ($server['type'] ?? '') === 'mieru' && trim((string)($server['port_range'] ?? '')) !== ''
            ? $server['port_range'] : ($server['port'] ?? null);
        $value = trim((string)$value);
        if (preg_match('/^(\d{1,5})\s*-\s*(\d{1,5})$/', $value, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2];
            if ($a >= 1 && $b <= 65535 && $a <= $b) return $a === $b ? (string)$a : "$a-$b";
            return null;
        }
        if (!preg_match('/^\d{1,5}$/', $value)) return null;
        $port = (int)$value;
        return $port >= 1 && $port <= 65535 ? (string)$port : null;
    }

    public static function transport(array $server): string
    {
        $type = strtolower((string)($server['type'] ?? ''));
        if ($type === 'mieru') {
            $transport = strtolower(trim((string)($server['transport'] ?? '')));
            return in_array($transport, ['tcp', 'udp'], true) ? $transport : 'unknown';
        }
        if ($type === 'openvpn') {
            $proto = strtolower(trim((string)($server['proto'] ?? 'udp')));
            if (strpos($proto, 'tcp') === 0) return 'tcp';
            if (strpos($proto, 'udp') === 0) return 'udp';
            return 'unknown';
        }
        if (in_array($type, ['mdns', 'hysteria', 'tuic', 'shadowquic', 'wireguard', 'masque'], true)) return 'udp';
        if (in_array($type, self::TCP_TYPES, true)) {
            if (in_array(strtolower((string)($server['network'] ?? '')), ['kcp', 'mkcp', 'quic'], true)) return 'udp';
            return 'tcp';
        }
        return 'unknown';
    }
}
