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
        $catalog = ProtocolRegistry::all();
        foreach ($catalog as $type => &$definition) {
            unset($definition['model'], $definition['table']);
            $definition['managed_server'] = ManagedServer::supports($type);
        }
        return $catalog;
    }

    /** Preserve Mihomo's nested/advanced options instead of flattening them. */
    public static function validate(string $protocol, array $settings): array
    {
        if (!ProtocolRegistry::has($protocol)) {
            throw ValidationException::withMessages(['protocol' => 'پروتکل Mihomo پشتیبانی نمی‌شود.']);
        }
        if (isset($settings['type']) && $settings['type'] !== self::catalog()[$protocol]['mihomo_type']) {
            throw ValidationException::withMessages(['type' => 'نوع تنظیمات باید با پروتکل انتخاب‌شده یکسان باشد.']);
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
                'peers.*.allowed-ips' => 'required|array|min:1', 'peers.*.allowed-ips.*' => 'required|string',
                'peers.*.reserved' => 'sometimes|array|size:3', 'peers.*.reserved.*' => 'integer|between:0,255',
                'peers.*.pre-shared-key' => 'sometimes|string',
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
            $rules += ['network-name' => 'required|string', 'peers' => 'sometimes|array', 'peers.*' => 'required|string',
                'listeners' => 'sometimes|array', 'listeners.*' => 'required|string'];
        }
        $validator = Validator::make($settings, $rules);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) $errors[$field] = $messages;
            throw ValidationException::withMessages($errors);
        }
        if ($protocol === 'mieru' && !empty($settings['port-range'])) {
            [$first, $last] = array_map('intval', explode('-', $settings['port-range']));
            if ($first < 1 || $last > 65535 || $last < $first) {
                throw ValidationException::withMessages(['port-range' => 'بازهٔ پورت معتبر نیست.']);
            }
        }
        unset($settings['name'], $settings['type']);
        ProtocolValidator::validate($protocol, $settings);
        return $settings;
    }

    public static function inherit(string $type, array $row, array $rows): array
    {
        $source = $row;
        $seen = [($row['id'] ?? 0) => true];
        while (!empty($source['parent_id'])) {
            $id = (int)$source['parent_id'];
            if (isset($seen[$id]) || !isset($rows[$id])) {
                throw ValidationException::withMessages(['parent_id' => 'والد باید نودی از همین پروتکل باشد و چرخه ایجاد نکند.']);
            }
            $seen[$id] = true;
            $source = $rows[$id];
        }
        if (!empty($row['parent_id'])) {
            foreach (self::catalog()[$type]['fields'] as $column => $field) $row[$column] = $source[$column] ?? null;
            $row['managed'] = $source['managed'] ?? false;
            $row['created_at'] = $source['created_at'] ?? $row['created_at'] ?? null;
        }
        return $row;
    }

    public static function render(array $server, $user): array
    {
        $settings = ProtocolRegistry::options($server['type'], $server);
        if (!empty($server['managed'])) $settings = ManagedServer::forUser($server['type'], $settings, $user);
        if ($server['type'] === 'ssh' && !empty($settings['host-key']) && empty($settings['host-key-algorithms'])) {
            $algorithms = SshKeys::hostKeyAlgorithms($settings['host-key']);
            if ($algorithms) $settings['host-key-algorithms'] = $algorithms;
        }
        $replace = function ($value) use (&$replace, $user) {
            if (is_array($value)) return array_map($replace, $value);
            if (!is_string($value)) return $value;
            return strtr($value, ['{{uuid}}' => (string)$user['uuid'], '{{user_id}}' => (string)$user['id']]);
        };
        return ['name' => $server['name'], 'type' => self::catalog()[$server['type']]['mihomo_type']] + $replace($settings);
    }

    /** Shared by all three Mihomo-based Clash subscription exporters. */
    public static function fromServer($user, array $server): ?array
    {
        if (ProtocolRegistry::has($server['type'] ?? '')) {
            $outbound = $server['mihomo'] ?? self::render($server, $user);
            // Subscription-info aliases change the current row's name after
            // rendering. Keep the proxy name and group references in sync.
            $outbound['name'] = $server['name'];
            return $outbound;
        }
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
