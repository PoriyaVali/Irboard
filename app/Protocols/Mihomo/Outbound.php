<?php

namespace App\Protocols\Mihomo;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Client outbounds from MetaCubeX/mihomo v1.19.33 (3f27ef39). */
class Outbound
{
    public const VERSION = '1.19.33';

    public static function catalog(): array
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
            'gost-relay' => ['label' => 'GOST Relay', 'example' => $endpoint + ['username' => '', 'password' => '', 'udp' => true]],
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

    /** Preserve Mihomo's nested/advanced options instead of flattening them. */
    public static function validate(string $protocol, array $settings): array
    {
        if (!isset(self::catalog()[$protocol])) {
            throw ValidationException::withMessages(['protocol' => 'پروتکل Mihomo پشتیبانی نمی‌شود.']);
        }
        if (isset($settings['type']) && $settings['type'] !== $protocol) {
            throw ValidationException::withMessages(['outbound_settings.type' => 'نوع تنظیمات باید با پروتکل انتخاب‌شده یکسان باشد.']);
        }
        $rules = [];
        if (!in_array($protocol, ['wireguard', 'tailscale', 'zerotier', 'easytier'], true)) {
            $rules['server'] = 'required|string|max:255|regex:/^[^\s\x00-\x1f\/]+$/u';
            $rules['port'] = $protocol === 'mieru' && !empty($settings['port-range'])
                ? 'prohibited' : 'required|integer|between:1,65535';
        }
        $required = [
            'ssr' => ['cipher', 'password', 'protocol', 'obfs'],
            'snell' => ['psk'], 'wireguard' => ['private-key'],
            'shadowquic' => ['username', 'password'], 'ssh' => ['username'],
            'mieru' => ['username', 'password', 'transport'], 'sudoku' => ['key'],
            'masque' => ['private-key', 'public-key'], 'openvpn' => ['ca'],
            'zerotier' => ['network'],
        ];
        foreach ($required[$protocol] ?? [] as $field) $rules[$field] = 'required|string';
        foreach (['udp', 'tls', 'skip-cert-verify'] as $field) $rules[$field] = 'sometimes|boolean';
        if ($protocol === 'snell') $rules['version'] = 'sometimes|integer|between:1,5';
        if ($protocol === 'ssh') {
            $rules['password'] = 'required_without:private-key|nullable|string';
            $rules['private-key'] = 'required_without:password|nullable|string';
        }
        if ($protocol === 'wireguard') {
            $rules += [
                'ip' => 'required_without:ipv6|nullable|string', 'ipv6' => 'required_without:ip|nullable|string',
                'server' => 'required_without:peers|nullable|string|max:255',
                'port' => 'required_without:peers|nullable|integer|between:1,65535',
                'public-key' => 'required_without:peers|nullable|string',
                'peers' => 'sometimes|array|min:1', 'peers.*.server' => 'required|string',
                'peers.*.port' => 'required|integer|between:1,65535', 'peers.*.public-key' => 'required|string',
                'peers.*.allowed-ips' => 'required|array|min:1',
            ];
        }
        if ($protocol === 'mieru') {
            $rules['transport'] = 'required|in:TCP,UDP';
            $rules['port-range'] = 'sometimes|string|regex:/^\d{1,5}-\d{1,5}$/';
        }
        if ($protocol === 'sudoku') {
            $rules += ['padding-min' => 'sometimes|integer|between:0,100', 'padding-max' => 'sometimes|integer|between:0,100'];
            if (isset($settings['padding-min'], $settings['padding-max'])) $rules['padding-max'] .= '|gte:padding-min';
        }
        if ($protocol === 'zerotier') $rules['network'] = 'required|string|regex:/^[0-9a-fA-F]{16}$/';
        if ($protocol === 'openvpn') {
            $rules += ['username' => 'required_without_all:cert,key|nullable|string', 'password' => 'required_with:username|nullable|string',
                'cert' => 'required_without:username|nullable|string', 'key' => 'required_with:cert|nullable|string'];
        }
        if ($protocol === 'masque' && ($settings['network'] ?? '') !== 'h3-l4proxy') {
            $rules += ['ip' => 'required_without:ipv6|nullable|string', 'ipv6' => 'required_without:ip|nullable|string'];
        }
        if ($protocol === 'easytier') {
            $rules += ['peers' => 'required_without:listeners|array|min:1', 'peers.*' => 'required|string',
                'listeners' => 'required_without:peers|array|min:1', 'listeners.*' => 'required|string'];
        }
        $validator = Validator::make($settings, $rules);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) $errors['outbound_settings.' . $field] = $messages;
            throw ValidationException::withMessages($errors);
        }
        if ($protocol === 'mieru' && !empty($settings['port-range'])) {
            [$first, $last] = array_map('intval', explode('-', $settings['port-range']));
            if ($first < 1 || $last > 65535 || $last < $first) {
                throw ValidationException::withMessages(['outbound_settings.port-range' => 'بازهٔ پورت معتبر نیست.']);
            }
        }
        unset($settings['name'], $settings['type']);
        return $settings;
    }

    public static function render(array $server, $user): array
    {
        $replace = function ($value) use (&$replace, $user) {
            if (is_array($value)) return array_map($replace, $value);
            if (!is_string($value)) return $value;
            return strtr($value, ['{{uuid}}' => (string)$user['uuid'], '{{user_id}}' => (string)$user['id']]);
        };
        return ['name' => $server['name'], 'type' => $server['protocol']] + $replace($server['outbound_settings']);
    }

    /** Shared by all three Mihomo-based Clash subscription exporters. */
    public static function fromServer($user, array $server): ?array
    {
        if (($server['type'] ?? null) === 'mihomo') return $server['mihomo'];
        if (($server['type'] ?? null) !== 'trusttunnel') return null;
        // V2bX/core/trusttunnel/user.go: credentialsFor uses UUID for both.
        $outbound = [
            'name' => $server['name'], 'type' => 'trusttunnel', 'server' => $server['host'], 'port' => (int)$server['port'],
            'username' => $user['uuid'], 'password' => $user['uuid'], 'udp' => true, 'health-check' => true,
            'sni' => ($server['custom_sni'] ?? '') ?: (($server['hostname'] ?? '') ?: $server['host']),
            'quic' => ($server['upstream_protocol'] ?? '') === 'http3',
            'skip-cert-verify' => ($server['cert_type'] ?? 'self-signed') === 'self-signed',
        ];
        return $outbound;
    }
}
