<?php

namespace App\Protocols\Mihomo;

/** Native node definitions, audited against Mihomo v1.19.33 option structs.
 * Each type has its own model/table and typed protocol columns. Nested protocol
 * structures use JSON columns, as do native Irboard TLS/transport settings.
 */
class ProtocolRegistry
{
    public static function all(): array
    {
        $examples = self::examples();
        $definitions = [
            'ssr' => [
                'label' => 'ShadowsocksR',
                'options' => [
                    'password' => 'string', 'cipher' => 'string', 'obfs' => 'string',
                    'obfs-param' => 'string', 'protocol' => 'string', 'protocol-param' => 'string',
                    'udp' => 'bool', 'tfo' => 'bool', 'mptcp' => 'bool',
                    'interface-name' => 'string', 'routing-mark' => 'int', 'ip-version' => 'string',
                    'dialer-proxy' => 'string',
                ],
            ],
            'socks5' => [
                'label' => 'SOCKS5',
                'options' => [
                    'username' => 'string', 'password' => 'string', 'tls' => 'bool',
                    'udp' => 'bool', 'skip-cert-verify' => 'bool', 'name-cert-verify' => 'string',
                    'fingerprint' => 'string', 'certificate' => 'string', 'private-key' => 'string',
                    'tfo' => 'bool', 'mptcp' => 'bool', 'interface-name' => 'string',
                    'routing-mark' => 'int', 'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'http' => [
                'label' => 'HTTP / HTTPS',
                'options' => [
                    'username' => 'string', 'password' => 'string', 'tls' => 'bool',
                    'sni' => 'string', 'skip-cert-verify' => 'bool', 'name-cert-verify' => 'string',
                    'fingerprint' => 'string', 'certificate' => 'string', 'private-key' => 'string',
                    'headers' => 'json', 'tfo' => 'bool', 'mptcp' => 'bool',
                    'interface-name' => 'string', 'routing-mark' => 'int', 'ip-version' => 'string',
                    'dialer-proxy' => 'string',
                ],
            ],
            'snell' => [
                'label' => 'Snell',
                'options' => [
                    'psk' => 'string', 'udp' => 'bool', 'version' => 'int',
                    'reuse' => 'bool', 'obfs-opts' => 'json', 'client-fingerprint' => 'string',
                    'tfo' => 'bool', 'mptcp' => 'bool', 'interface-name' => 'string',
                    'routing-mark' => 'int', 'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'wireguard' => [
                'label' => 'WireGuard / AmneziaWG',
                'options' => [
                    'public-key' => 'string', 'pre-shared-key' => 'string', 'reserved' => 'json',
                    'allowed-ips' => 'json', 'ip' => 'string', 'ipv6' => 'string',
                    'private-key' => 'string', 'workers' => 'int', 'mtu' => 'int',
                    'udp' => 'bool', 'persistent-keepalive' => 'int', 'ip-stack' => 'json',
                    'amnezia-wg-option' => 'json', 'peers' => 'json', 'remote-dns-resolve' => 'bool',
                    'dns' => 'json', 'refresh-server-ip-interval' => 'int', 'tfo' => 'bool',
                    'mptcp' => 'bool', 'interface-name' => 'string', 'routing-mark' => 'int',
                    'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'shadowquic' => [
                'label' => 'ShadowQUIC',
                'options' => [
                    'username' => 'string', 'password' => 'string', 'sni' => 'string',
                    'alpn' => 'json', 'quic-versions' => 'json', 'udp-over-stream' => 'bool',
                    'zero-rtt' => 'bool', 'keep-alive-interval' => 'int', 'congestion-controller' => 'string',
                    'up' => 'string', 'down' => 'string', 'cwnd' => 'int',
                    'bbr-profile' => 'string', 'recv-window-conn' => 'int', 'recv-window' => 'int',
                    'disable-mtu-discovery' => 'bool', 'max-datagram-frame-size' => 'int', 'max-open-streams' => 'int',
                    'tfo' => 'bool', 'mptcp' => 'bool', 'interface-name' => 'string',
                    'routing-mark' => 'int', 'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'gostrelay' => [
                'label' => 'GOST Relay',
                'options' => [
                    'forward' => 'bool', 'udp' => 'bool', 'tls' => 'bool',
                    'mux' => 'bool', 'sni' => 'string', 'username' => 'string',
                    'password' => 'string', 'skip-cert-verify' => 'bool', 'name-cert-verify' => 'string',
                    'fingerprint' => 'string', 'certificate' => 'string', 'private-key' => 'string',
                    'client-fingerprint' => 'string', 'tfo' => 'bool', 'mptcp' => 'bool',
                    'interface-name' => 'string', 'routing-mark' => 'int', 'ip-version' => 'string',
                    'dialer-proxy' => 'string',
                ],
            ],
            'ssh' => [
                'label' => 'SSH',
                'options' => [
                    'username' => 'string', 'password' => 'string', 'private-key' => 'string',
                    'private-key-passphrase' => 'string', 'host-key' => 'json', 'host-key-algorithms' => 'json',
                    'tfo' => 'bool', 'mptcp' => 'bool', 'interface-name' => 'string',
                    'routing-mark' => 'int', 'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'mieru' => [
                'label' => 'Mieru',
                'options' => [
                    'port-range' => 'string', 'transport' => 'string', 'udp' => 'bool',
                    'username' => 'string', 'password' => 'string', 'multiplexing' => 'string',
                    'handshake-mode' => 'string', 'traffic-pattern' => 'string', 'tfo' => 'bool',
                    'mptcp' => 'bool', 'interface-name' => 'string', 'routing-mark' => 'int',
                    'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'sudoku' => [
                'label' => 'Sudoku',
                'options' => [
                    'key' => 'string', 'aead-method' => 'string', 'padding-min' => 'int',
                    'padding-max' => 'int', 'table-type' => 'string', 'enable-pure-downlink' => 'bool',
                    'http-mask' => 'bool', 'http-mask-mode' => 'string', 'http-mask-tls' => 'bool',
                    'http-mask-host' => 'string', 'path-root' => 'string', 'multiplex' => 'string',
                    'http-mask-multiplex' => 'string', 'httpmask' => 'json', 'custom-table' => 'string',
                    'custom-tables' => 'json', 'tfo' => 'bool', 'mptcp' => 'bool',
                    'interface-name' => 'string', 'routing-mark' => 'int', 'ip-version' => 'string',
                    'dialer-proxy' => 'string',
                ],
            ],
            'masque' => [
                'label' => 'MASQUE',
                'options' => [
                    'private-key' => 'string', 'public-key' => 'string', 'ip' => 'string',
                    'ipv6' => 'string', 'uri' => 'string', 'sni' => 'string',
                    'mtu' => 'int', 'udp' => 'bool', 'handshake-timeout' => 'int',
                    'skip-cert-verify' => 'bool', 'name-cert-verify' => 'string', 'network' => 'string',
                    'congestion-controller' => 'string', 'cwnd' => 'int', 'bbr-profile' => 'string',
                    'ip-stack' => 'json', 'remote-dns-resolve' => 'bool', 'dns' => 'json',
                    'tfo' => 'bool', 'mptcp' => 'bool', 'interface-name' => 'string',
                    'routing-mark' => 'int', 'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'openvpn' => [
                'label' => 'OpenVPN',
                'options' => [
                    'proto' => 'string', 'dev' => 'string', 'cipher' => 'string',
                    'data-ciphers' => 'json', 'data-ciphers-fallback' => 'string', 'auth' => 'string',
                    'comp-lzo' => 'string', 'ca' => 'string', 'cert' => 'string',
                    'key' => 'string', 'tls-auth' => 'string', 'key-direction' => 'string',
                    'tls-crypt' => 'string', 'tls-crypt-v2' => 'string', 'username' => 'string',
                    'password' => 'string', 'peer-info' => 'json', 'ping' => 'int',
                    'ping-restart' => 'int', 'tran-window' => 'int', 'handshake-timeout' => 'int',
                    'mtu' => 'int', 'udp' => 'bool', 'ip-stack' => 'json',
                    'remote-dns-resolve' => 'bool', 'dns' => 'json', 'tfo' => 'bool',
                    'mptcp' => 'bool', 'interface-name' => 'string', 'routing-mark' => 'int',
                    'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'tailscale' => [
                'label' => 'Tailscale / Headscale',
                'options' => [
                    'hostname' => 'string', 'auth-key' => 'string', 'control-url' => 'string',
                    'state-dir' => 'string', 'ephemeral' => 'bool', 'udp' => 'bool',
                    'accept-routes' => 'bool', 'exit-node' => 'string', 'exit-node-allow-lan-access' => 'bool',
                    'tfo' => 'bool', 'mptcp' => 'bool', 'interface-name' => 'string',
                    'routing-mark' => 'int', 'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
            'zerotier' => [
                'label' => 'ZeroTier',
                'options' => [
                    'network' => 'string', 'state-dir' => 'string', 'identity-secret' => 'string',
                    'planet' => 'string', 'mtu' => 'int', 'ip-stack' => 'json',
                    'physical-mtu' => 'int', 'udp' => 'bool', 'remote-dns-resolve' => 'bool',
                    'dns' => 'json', 'low-bandwidth' => 'bool', 'encrypted-hello' => 'bool',
                    'primary-port' => 'int', 'secondary-port' => 'int', 'tcp-fallback-mode' => 'string',
                    'tcp-fallback-relay' => 'string', 'orbit' => 'json', 'remote-trace-target' => 'string',
                    'remote-trace-level' => 'int', 'tfo' => 'bool', 'mptcp' => 'bool',
                    'interface-name' => 'string', 'routing-mark' => 'int', 'ip-version' => 'string',
                    'dialer-proxy' => 'string',
                ],
            ],
            'easytier' => [
                'label' => 'EasyTier',
                'options' => [
                    'network-name' => 'string', 'network-secret' => 'string', 'hostname' => 'string',
                    'ipv4' => 'string', 'dhcp' => 'bool', 'peers' => 'json',
                    'listeners' => 'json', 'no-listener' => 'bool', 'mapped-listeners' => 'json',
                    'exit-nodes' => 'json', 'proxy-networks' => 'json', 'instance-name' => 'string',
                    'state-dir' => 'string', 'udp' => 'bool', 'accept-dns' => 'bool',
                    'enable-exit-node' => 'bool', 'enable-encryption' => 'bool', 'encryption-algorithm' => 'string',
                    'private-mode' => 'bool', 'latency-first' => 'bool', 'disable-p2p' => 'bool',
                    'enable-kcp-proxy' => 'bool', 'disable-kcp-input' => 'bool', 'enable-quic-proxy' => 'bool',
                    'disable-quic-input' => 'bool', 'mtu' => 'int', 'tld-dns-zone' => 'string',
                    'secure-mode' => 'bool', 'local-private-key' => 'string', 'local-public-key' => 'string',
                    'tfo' => 'bool', 'mptcp' => 'bool', 'interface-name' => 'string',
                    'routing-mark' => 'int', 'ip-version' => 'string', 'dialer-proxy' => 'string',
                ],
            ],
        ];
        foreach ($definitions as $type => &$definition) {
            $definition['model'] = 'App\\Models\\Server' . ucfirst($type);
            $definition['table'] = 'v2_server_' . $type;
            $definition['mihomo_type'] = $type === 'gostrelay' ? 'gost-relay' : $type;
            $definition['example'] = $examples[$type]['example'];
            $definition['fields'] = [];
            foreach ($definition['options'] as $option => $kind) {
                $column = $type === 'ssr' && $option === 'protocol' ? 'ssr_protocol' : str_replace('-', '_', $option);
                $definition['fields'][$column] = ['option' => $option, 'kind' => $kind];
            }
            unset($definition['options']);
        }
        return $definitions;
    }

    public static function types(): array { return array_keys(self::all()); }
    public static function has(string $type): bool { return isset(self::all()[$type]); }
    public static function model(string $type): string { return self::all()[$type]['model']; }
    public static function table(string $type): string { return self::all()[$type]['table']; }
    public static function nativeType(string $type): string { return $type === 'gost-relay' ? 'gostrelay' : $type; }

    /** Dedicated columns always own their options; null means omitted, not false. */
    public static function options(string $type, array $row): array
    {
        $settings = [];
        foreach (self::all()[$type]['fields'] as $column => $field) {
            if (array_key_exists($column, $row) && $row[$column] !== null) $settings[$field['option']] = $row[$column];
        }
        if (isset($row['host']) && $row['host'] !== '') $settings['server'] = $row['host'];
        if (isset($row['port']) && $row['port'] !== '') $settings['port'] = (int)$row['port'];
        return $settings;
    }

    public static function storage(string $type, array $settings): array
    {
        unset($settings['name'], $settings['type']);
        $row = ['host' => $settings['server'] ?? null, 'port' => $settings['port'] ?? null];
        unset($settings['server'], $settings['port']);
        foreach (self::all()[$type]['fields'] as $column => $field) {
            $row[$column] = $settings[$field['option']] ?? null;
        }
        return $row;
    }

    private static function examples(): array
    {
        $endpoint = ['server' => 'node.example.com', 'port' => 443];
        return [
            'ssr' => ['label' => 'ShadowsocksR', 'example' => $endpoint + [
                'cipher' => 'chacha20-ietf', 'password' => '', 'protocol' => 'auth_sha1_v4', 'obfs' => 'tls1.2_ticket_auth', 'udp' => true,
            ]],
            'socks5' => ['label' => 'SOCKS5', 'example' => $endpoint + ['username' => '', 'password' => '', 'udp' => true]],
            'http' => ['label' => 'HTTP / HTTPS', 'example' => $endpoint + ['username' => '', 'password' => '', 'tls' => true]],
            'snell' => ['label' => 'Snell', 'example' => $endpoint + ['psk' => '', 'version' => 4, 'udp' => true]],
            'wireguard' => ['label' => 'WireGuard / AmneziaWG', 'example' => [
                'server' => 'node.example.com', 'port' => 51820, 'ip' => '10.0.0.2', 'private-key' => '', 'public-key' => '', 'udp' => true,
            ]],
            'shadowquic' => ['label' => 'ShadowQUIC', 'example' => $endpoint + ['username' => '', 'password' => '', 'sni' => 'node.example.com']],
            'gostrelay' => ['label' => 'GOST Relay', 'example' => $endpoint + ['username' => '', 'password' => '', 'udp' => true]],
            'ssh' => ['label' => 'SSH', 'example' => ['server' => 'node.example.com', 'port' => 22, 'username' => '', 'password' => '']],
            'mieru' => ['label' => 'Mieru', 'example' => $endpoint + ['transport' => 'TCP', 'username' => '', 'password' => '', 'udp' => true]],
            'sudoku' => ['label' => 'Sudoku', 'example' => $endpoint + [
                'key' => '', 'aead-method' => 'chacha20-poly1305', 'padding-min' => 2, 'padding-max' => 7, 'table-type' => 'prefer_ascii',
            ]],
            'masque' => ['label' => 'MASQUE', 'example' => $endpoint + ['private-key' => '', 'public-key' => '', 'ip' => '10.0.0.2', 'udp' => true]],
            'openvpn' => ['label' => 'OpenVPN', 'example' => [
                'server' => 'node.example.com', 'port' => 1194, 'proto' => 'udp', 'ca' => '', 'username' => '', 'password' => '', 'udp' => true,
            ]],
            'tailscale' => ['label' => 'Tailscale / Headscale', 'example' => ['hostname' => 'irboard-{{user_id}}', 'auth-key' => '', 'exit-node' => 'auto:any', 'udp' => true]],
            'zerotier' => ['label' => 'ZeroTier', 'example' => ['network' => '', 'udp' => true]],
            'easytier' => ['label' => 'EasyTier', 'example' => [
                'network-name' => '', 'network-secret' => '', 'peers' => ['tcp://node.example.com:11010'], 'udp' => true,
            ]],
        ];
    }
}
