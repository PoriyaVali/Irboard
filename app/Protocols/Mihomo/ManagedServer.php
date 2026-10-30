<?php

namespace App\Protocols\Mihomo;

use App\Models\ServerRoute;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** The server contract is narrower than Mihomo's client outbound catalog. */
class ManagedServer
{
    public const TYPES = ['http', 'socks5', 'mieru', 'shadowquic'];

    /** The route actions V2bX's Mihomo core applies; it refuses a node with DNS or outbound routes. */
    public const ROUTE_ACTIONS = ['block', 'block_ip', 'block_port', 'protocol'];

    public static function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function validate(string $type, array $node): void
    {
        if (empty($node['managed'])) return;
        if (!self::supports($type)) self::fail('managed', 'این پروتکل هنوز ورودی سرور چندکاربره در هستهٔ Mihomo ندارد.');
        // Relays inherit the root's mode; V2bX listens only for the root node.
        if (empty($node['parent_id']) && empty($node['server_port'])) self::fail('server_port', 'پورت شنوندهٔ V2bX الزامی است.');
        $templates = '';
        foreach (['username', 'password'] as $field) {
            $value = $node[$field] ?? '';
            if (strpos(str_replace(['{{uuid}}', '{{user_id}}'], '', (string)$value), '{{') !== false) self::fail($field, 'قالب مشخصات معتبر نیست.');
            if ($value !== '' && $value !== null && strpos($value, '{{uuid}}') === false && strpos($value, '{{user_id}}') === false) {
                self::fail($field, 'در حالت V2bX، مشخصات باید برای هر کاربر یکتا باشند؛ از {{uuid}} استفاده کنید یا فیلد را خالی بگذارید.');
            }
            $templates .= ($value === '' || $value === null) ? '{{uuid}}' : $value;
        }
        // {{user_id}} is a public sequence number: credentials without the
        // subscriber's UUID would let anyone use, and bill, any account.
        if (strpos($templates, '{{uuid}}') === false) self::fail('password', 'شناسهٔ عددی کاربر قابل حدس است؛ نام کاربری یا رمز عبور باید {{uuid}} داشته باشد یا خالی بماند.');
        if ($type === 'mieru' && !empty($node['port_range'])) self::fail('port_range', 'نود مدیریت‌شده از یک پورت سرویس استفاده می‌کند.');
    }

    /** V2bX reads a managed root's routes; a child's reach only subscriptions. */
    public static function validateRoutes(array $node): void
    {
        if (empty($node['managed']) || !empty($node['parent_id']) || empty($node['route_id'])) return;
        $unsupported = ServerRoute::whereIn('id', array_map('intval', (array)$node['route_id']))
            ->whereNotIn('action', self::ROUTE_ACTIONS)->pluck('remarks')->all();
        if ($unsupported) self::fail('route_id', 'هستهٔ Mihomo در V2bX فقط مسیرهای مسدودسازی (block، block_ip، block_port و protocol) را اجرا می‌کند و نودی با مسیر DNS یا خروجی را بالا نمی‌آورد؛ این مسیرها را از نود بردارید: ' . implode('، ', $unsupported));
    }

    /** The managed root nodes using a route, as "type: name". */
    public static function nodesUsingRoute(int $routeId): array
    {
        $names = [];
        foreach (self::TYPES as $type) {
            $table = ProtocolRegistry::table($type);
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'managed')) continue;
            $model = ProtocolRegistry::model($type);
            foreach ($model::where('managed', true)->whereNull('parent_id')->get() as $node) {
                if (in_array($routeId, array_map('intval', (array)$node->route_id), true)) $names[] = $type . ': ' . $node->name;
            }
        }
        return $names;
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
