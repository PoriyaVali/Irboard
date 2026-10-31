<?php

namespace App\Protocols\Mihomo;

use App\Models\ServerRoute;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** The server contract is narrower than Mihomo's client outbound catalog. */
class ManagedServer
{
    public const TYPES = ['http', 'socks5', 'mieru', 'shadowquic', 'sudoku', 'snell'];

    /** The route actions V2bX's Mihomo core applies; it refuses a node with DNS or outbound routes. */
    public const ROUTE_ACTIONS = ['block', 'block_ip', 'block_port', 'protocol'];

    public static function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * The settings that hold a managed node's per-user templates. A Snell
     * user's PSK is also their ShadowTLS password; a Sudoku user holds a split
     * of the master key, derived per UUID rather than written as a template.
     */
    private static function templateFields(string $type): array
    {
        return ['sudoku' => [], 'snell' => ['psk']][$type] ?? ['username', 'password'];
    }

    /**
     * Fill what a managed root can do without: V2bX listens on the connection
     * port unless told otherwise, and a Sudoku root without a master key gets
     * a new one.
     */
    public static function prepare(string $type, $node): void
    {
        if (empty($node->managed) || !empty($node->parent_id)) return;
        if (empty($node->server_port) && !empty($node->port) && ctype_digit((string)$node->port)) $node->server_port = (int)$node->port;
        if ($type === 'sudoku' && (string)($node->key ?? '') === '') $node->key = SudokuKeys::generate();
    }

    public static function validate(string $type, array $node): void
    {
        if (empty($node['managed'])) return;
        if (!self::supports($type)) self::fail('managed', 'این پروتکل هنوز ورودی سرور چندکاربره در هستهٔ Mihomo ندارد.');
        // Relays inherit the root's mode; V2bX listens only for the root node.
        if (empty($node['parent_id']) && empty($node['server_port'])) self::fail('server_port', 'پورت شنوندهٔ V2bX الزامی است.');
        if ($type === 'sudoku') {
            $problem = SudokuKeys::problem($node['key'] ?? null);
            if ($problem) self::fail('key', $problem);
            return;
        }
        if ($type === 'snell') self::validateSnell($node);
        // A blank template stands for {{uuid}}.
        $templates = '';
        foreach (self::templateFields($type) as $field) {
            $value = $node[$field] ?? '';
            if (strpos(str_replace(['{{uuid}}', '{{user_id}}'], '', (string)$value), '{{') !== false) self::fail($field, 'قالب مشخصات معتبر نیست.');
            if ($value !== '' && $value !== null && strpos($value, '{{uuid}}') === false && strpos($value, '{{user_id}}') === false) {
                self::fail($field, 'در حالت V2bX، مشخصات باید برای هر کاربر یکتا باشند؛ از {{uuid}} استفاده کنید یا فیلد را خالی بگذارید.');
            }
            $templates .= ($value === '' || $value === null) ? '{{uuid}}' : $value;
        }
        // {{user_id}} is a public sequence number: credentials without the
        // subscriber's UUID would let anyone use, and bill, any account.
        if (strpos($templates, '{{uuid}}') === false) self::fail($type === 'snell' ? 'psk' : 'password', 'شناسهٔ عددی کاربر قابل حدس است؛ نام کاربری یا رمز عبور باید {{uuid}} داشته باشد یا خالی بماند.');
        if ($type === 'mieru' && !empty($node['port_range'])) self::fail('port_range', 'نود مدیریت‌شده از یک پورت سرویس استفاده می‌کند.');
    }

    /**
     * Snell has no user identity of its own: V2bX tells users apart by their
     * ShadowTLS v3 handshake, whose password is the user's PSK.
     */
    private static function validateSnell(array $node): void
    {
        $obfs = $node['obfs_opts'] ?? null;
        if (is_string($obfs)) $obfs = json_decode($obfs, true);
        if (!is_array($obfs) || ($obfs['mode'] ?? '') !== 'shadow-tls' || (int)($obfs['version'] ?? 0) !== 3 || trim((string)($obfs['host'] ?? '')) === '') {
            self::fail('obfs_opts', 'Snell مدیریت‌شده کاربران را با ShadowTLS نسخهٔ ۳ از هم جدا می‌کند؛ mode را shadow-tls، version را 3 و host را دامنهٔ یک سایت TLS 1.3 بگذارید، مثلاً {"mode":"shadow-tls","version":3,"host":"www.example.com"}');
        }
        if (array_key_exists('password', $obfs)) self::fail('obfs_opts', 'رمز ShadowTLS هر کاربر همان کلید PSK اوست؛ password را از obfs-opts حذف کنید.');
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

    /** A managed node's settings with each blank template standing for {{uuid}}. */
    public static function credentials(string $type, array $settings): array
    {
        foreach (self::templateFields($type) as $field) if (empty($settings[$field])) $settings[$field] = '{{uuid}}';
        if ($type === 'snell' && is_array($settings['obfs-opts'] ?? null)) $settings['obfs-opts']['password'] = $settings['psk'];
        return $settings;
    }

    /** The settings a subscriber's profile gets from a managed node, before templates. */
    public static function forUser(string $type, array $settings, $user): array
    {
        $settings = self::credentials($type, $settings);
        if ($type === 'sudoku' && SudokuKeys::problem($settings['key'] ?? null) === null) {
            $settings['key'] = SudokuKeys::userKey($settings['key'], (string)$user['uuid']);
        }
        return $settings;
    }

    public static function config(string $type, array $node): array
    {
        self::validate($type, $node);
        $options = self::credentials($type, ProtocolRegistry::options($type, $node));
        // V2bX names each user by the rendered username and keys them by the
        // rendered password: for Snell that is the PSK; for Sudoku the UUID,
        // from which V2bX derives the user's split of the master key itself.
        $response = [
            'server_port' => (int)$node['server_port'], 'host' => $node['host'],
            'username' => $options['username'] ?? '{{uuid}}', 'password' => $options['password'] ?? ($options['psk'] ?? '{{uuid}}'),
        ];
        // Client mTLS keys, routing marks and outgoing proxy options never
        // become a server certificate or a server-side outbound policy.
        $keys = [
            'mieru' => ['transport', 'traffic-pattern'],
            'shadowquic' => ['alpn', 'quic-versions', 'zero-rtt', 'congestion-controller', 'up', 'down', 'cwnd', 'bbr-profile', 'recv-window-conn', 'recv-window', 'disable-mtu-discovery', 'max-datagram-frame-size'],
            // http-mask-tls and http-mask-host describe a TLS terminator in
            // front of the node, and multiplex is each client's own choice.
            'sudoku' => ['key', 'aead-method', 'padding-min', 'padding-max', 'table-type', 'enable-pure-downlink', 'custom-table', 'custom-tables', 'http-mask', 'http-mask-mode', 'path-root'],
            'snell' => ['version'],
        ][$type] ?? ['tls'];
        foreach ($keys as $key) if (array_key_exists($key, $options)) $response[str_replace('-', '_', $key)] = $options[$key];
        if ($type === 'sudoku' && is_array($options['httpmask'] ?? null)) {
            // As in the client, the nested form overrides the flat one.
            $mask = $options['httpmask'];
            if (array_key_exists('disable', $mask)) $response['http_mask'] = !$mask['disable'];
            if (($mask['mode'] ?? '') !== '') $response['http_mask_mode'] = $mask['mode'];
            if (($mask['path-root'] ?? '') !== '') $response['path_root'] = $mask['path-root'];
        }
        if ($type === 'snell') {
            $obfs = $options['obfs-opts'];
            $response['obfs_opts'] = ['mode' => $obfs['mode'], 'host' => $obfs['host'], 'version' => (int)$obfs['version']];
        }
        // ShadowQUIC's is the site V2bX hands probers to, unless config.json
        // names another.
        if (in_array($type, ['http', 'socks5', 'shadowquic'], true)) $response['server_name'] = $node['sni'] ?? $node['host'];
        return $response;
    }

    private static function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
