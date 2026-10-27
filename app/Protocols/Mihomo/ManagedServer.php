<?php

namespace App\Protocols\Mihomo;

use Illuminate\Validation\ValidationException;

/** The server contract is narrower than Mihomo's client outbound catalog. */
class ManagedServer
{
    public const TYPES = ['http', 'socks5', 'mieru', 'shadowquic'];

    public static function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function validate(string $type, array $node): void
    {
        if (empty($node['managed'])) return;
        if (!self::supports($type)) self::fail('managed', 'این پروتکل هنوز ورودی سرور چندکاربره در هستهٔ Mihomo ندارد.');
        if (empty($node['server_port'])) self::fail('server_port', 'پورت شنوندهٔ V2bX الزامی است.');
        foreach (['username', 'password'] as $field) {
            $value = $node[$field] ?? '';
            if (strpos(str_replace(['{{uuid}}', '{{user_id}}'], '', (string)$value), '{{') !== false) self::fail($field, 'قالب مشخصات معتبر نیست.');
            if ($value !== '' && $value !== null && strpos($value, '{{uuid}}') === false && strpos($value, '{{user_id}}') === false) {
                self::fail($field, 'در حالت V2bX، مشخصات باید برای هر کاربر یکتا باشند؛ از {{uuid}} استفاده کنید یا فیلد را خالی بگذارید.');
            }
        }
        if ($type === 'mieru' && !empty($node['port_range'])) self::fail('port_range', 'نود مدیریت‌شده از یک پورت سرویس استفاده می‌کند.');
    }

    public static function credentials(array $settings): array
    {
        foreach (['username', 'password'] as $field) if (empty($settings[$field])) $settings[$field] = '{{uuid}}';
        return $settings;
    }

    public static function config(string $type, array $node): array
    {
        self::validate($type, $node);
        $options = self::credentials(ProtocolRegistry::options($type, $node));
        $response = [
            'server_port' => (int)$node['server_port'], 'host' => $node['host'],
            'username' => $options['username'], 'password' => $options['password'],
        ];
        // Client mTLS keys, routing marks and outgoing proxy options never
        // become a server certificate or a server-side outbound policy.
        $keys = $type === 'mieru' ? ['transport', 'traffic-pattern'] : ($type === 'shadowquic'
            ? ['alpn', 'quic-versions', 'zero-rtt', 'congestion-controller', 'up', 'down', 'cwnd', 'bbr-profile', 'recv-window-conn', 'recv-window', 'disable-mtu-discovery', 'max-datagram-frame-size'] : ['tls']);
        foreach ($keys as $key) if (array_key_exists($key, $options)) $response[str_replace('-', '_', $key)] = $options[$key];
        if (in_array($type, ['http', 'socks5'], true)) $response['server_name'] = $node['sni'] ?? $node['host'];
        return $response;
    }

    private static function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
