<?php

namespace App\Protocols\Mihomo;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Protocol checks that cannot be expressed by a flat form validator. */
class ProtocolValidator
{
    private static function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private static function address(string $field, string $value, int $family, bool $cidr = false): void
    {
        $parts = explode('/', $value);
        if (!filter_var($parts[0], FILTER_VALIDATE_IP, $family === 4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)
            || count($parts) > ($cidr ? 2 : 1)
            || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int)$parts[1] > ($family === 4 ? 32 : 128)))) {
            self::fail($field, 'آدرس IP یا پیشوند شبکه معتبر نیست.');
        }
    }

    private static function wireguardKey(string $field, string $key): void
    {
        if (strlen((string)base64_decode($key, true)) !== 32 || base64_encode((string)base64_decode($key, true)) !== str_replace(["\r", "\n"], '', $key)) self::fail($field, 'کلید WireGuard باید ۳۲ بایت با قالب Base64 باشد.');
    }

    public static function validate(string $type, array $settings): void
    {
        $rules = [];
        $allowed = ['server', 'port'];
        foreach (ProtocolRegistry::all()[$type]['fields'] as $field) {
            $option = $field['option']; $allowed[] = $option;
            $rules[$option] = 'sometimes|' . ['string' => 'string', 'int' => 'integer', 'bool' => 'boolean', 'json' => 'array'][$field['kind']];
        }
        foreach (array_diff(array_keys($settings), $allowed) as $key) self::fail($key, 'این گزینه در ساختار این پروتکل وجود ندارد.');
        foreach (['handshake-timeout','tran-window','ping','ping-restart','persistent-keepalive','workers','mtu','physical-mtu'] as $field) if (isset($settings[$field])) $rules[$field] = 'integer|min:0';
        Validator::make($settings, $rules)->validate();
        foreach (['ip', 'ipv6'] as $field) {
            if (!empty($settings[$field]) && in_array($type, ['wireguard', 'masque'], true)) {
                self::address($field, $settings[$field], $field === 'ip' ? 4 : 6, $type === 'wireguard');
            }
        }
        $enums = [
            'ssr' => [
                'cipher' => ['none', 'dummy', 'aes-128-ctr', 'aes-192-ctr', 'aes-256-ctr', 'aes-128-cfb', 'aes-192-cfb', 'aes-256-cfb', 'rc4-md5', 'chacha20', 'chacha20-ietf', 'xchacha20'],
                'protocol' => ['origin', 'auth_sha1_v4', 'auth_aes128_md5', 'auth_aes128_sha1', 'auth_chain_a', 'auth_chain_b'],
                'obfs' => ['plain', 'http_simple', 'http_post', 'random_head', 'tls1.2_ticket_auth', 'tls1.2_ticket_fastauth'],
            ],
            'mieru' => ['multiplexing' => ['MULTIPLEXING_DEFAULT', 'MULTIPLEXING_OFF', 'MULTIPLEXING_LOW', 'MULTIPLEXING_MIDDLE', 'MULTIPLEXING_HIGH'], 'handshake-mode' => ['HANDSHAKE_DEFAULT', 'HANDSHAKE_STANDARD', 'HANDSHAKE_NO_WAIT']],
            'sudoku' => ['aead-method' => ['aes-128-gcm', 'chacha20-poly1305', 'none'], 'table-type' => ['prefer_ascii', 'prefer_entropy', 'up_ascii_down_entropy', 'up_entropy_down_ascii'], 'http-mask-mode' => ['legacy', 'stream', 'poll', 'auto', 'ws'], 'multiplex' => ['off', 'on', 'auto'], 'http-mask-multiplex' => ['off', 'on', 'auto']],
            'openvpn' => ['proto' => ['udp', 'udp4', 'tcp', 'tcp-client', 'tcp4', 'tcp4-client'], 'dev' => ['tun'], 'key-direction' => ['0', '1']],
            'masque' => ['network' => ['h2', 'h3', 'h3-l4proxy']],
        ];
        foreach ($enums[$type] ?? [] as $key => $choices) {
            if (($settings[$key] ?? '') !== '' && !in_array($type === 'ssr' && $key === 'cipher' ? strtolower($settings[$key]) : $settings[$key], $choices, true)) self::fail($key, 'گزینهٔ انتخاب‌شده برای این پروتکل معتبر نیست.');
        }
        foreach (['dns', 'alpn', 'quic-versions', 'host-key', 'host-key-algorithms', 'data-ciphers', 'custom-tables', 'listeners', 'mapped-listeners', 'exit-nodes', 'proxy-networks'] as $field) {
            if (!isset($settings[$field])) continue;
            if (!array_is_list($settings[$field])) self::fail($field, 'این گزینه باید آرایه باشد.');
            foreach ($settings[$field] as $value) if (!is_string($value)) self::fail($field, 'اعضای آرایه باید متنی باشند.');
        }
        foreach (['headers', 'obfs-opts', 'ip-stack', 'amnezia-wg-option', 'httpmask', 'peer-info'] as $field) {
            if (!empty($settings[$field]) && array_is_list($settings[$field])) self::fail($field, 'این گزینه باید یک شیء JSON باشد.');
        }
        if ($type === 'shadowquic') {
            foreach ($settings['quic-versions'] ?? [] as $version) {
                if (!in_array(str_replace('_', '-', strtolower(trim($version))), ['v1','1','rfc9000','rfc-9000','v2','2','rfc9369','rfc-9369'], true)) self::fail('quic_versions', 'نسخهٔ QUIC معتبر نیست.');
            }
        }
        if ($type === 'sudoku') {
            $httpmask = $settings['httpmask'] ?? [];
            Validator::make($httpmask, ['disable' => 'sometimes|boolean', 'mode' => 'sometimes|in:legacy,stream,poll,auto,ws', 'tls' => 'sometimes|boolean', 'host' => 'sometimes|string', 'path-root' => 'sometimes|string', 'multiplex' => 'sometimes|in:off,on,auto'])->validate();
            $path = trim($httpmask['path-root'] ?? $settings['path-root'] ?? '', '/');
            if ($path !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $path)) self::fail('path_root', 'مسیر HTTP mask باید یک قطعهٔ مسیر باشد.');
        }
        if ($type === 'snell') {
            if (in_array((int)($settings['version'] ?? 3), [1, 2], true) && !empty($settings['udp'])) self::fail('udp', 'نسخهٔ ۱ و ۲ Snell از UDP پشتیبانی نمی‌کند.');
            $mode = $settings['obfs-opts']['mode'] ?? '';
            if (!in_array($mode, ['', 'tls', 'http', 'shadow-tls', 'restls', 'jls'], true)) self::fail('obfs_opts', 'حالت استتار Snell معتبر نیست.');
        }
        if ($type === 'wireguard') {
            self::wireguardKey('private_key', $settings['private-key']);
            $peers = $settings['peers'] ?? [$settings];
            if (array_keys($peers) !== range(0, count($peers) - 1)) self::fail('peers', 'همتاها باید آرایه‌ای از اشیاء باشند.');
            foreach ($peers as $i => $peer) {
                foreach (array_diff(array_keys($peer), ['server','port','public-key','pre-shared-key','reserved','allowed-ips']) as $key) {
                    if (isset($settings['peers'])) self::fail('peers.' . $i . '.' . $key, 'گزینهٔ همتا معتبر نیست.');
                }
                self::wireguardKey('peers.' . $i . '.public-key', $peer['public-key']);
                if (!empty($peer['pre-shared-key'])) self::wireguardKey('peers.' . $i . '.pre-shared-key', $peer['pre-shared-key']);
                if (isset($peer['reserved']) && (count($peer['reserved']) !== 3 || count(array_filter($peer['reserved'], function ($v) { return !is_int($v) || $v < 0 || $v > 255; })))) self::fail('reserved', 'Reserved باید دقیقاً سه بایت باشد.');
                foreach ($peer['allowed-ips'] ?? [] as $prefix) {
                    if (!is_string($prefix) || strpos($prefix, '/') === false) self::fail('allowed_ips', 'هر شبکه باید دارای طول پیشوند باشد.');
                    self::address('allowed_ips', $prefix, strpos($prefix, ':') === false ? 4 : 6, true);
                }
            }
        }
        if (isset($settings['ip-stack'])) {
            Validator::make($settings['ip-stack'], ['mode' => 'sometimes|in:auto,gvisor,mips,AUTO,GVISOR,MIPS', 'congestion-controller' => 'sometimes|in:cubic,reno,bbr,bbr3'])->validate();
        }
        if ($type === 'masque') {
            foreach (['private-key' => 'EC PRIVATE KEY', 'public-key' => 'PUBLIC KEY'] as $field => $label) {
                $der = base64_decode($settings[$field], true);
                $pem = '-----BEGIN ' . $label . "-----\n" . chunk_split($settings[$field], 64, "\n") . '-----END ' . $label . "-----\n";
                $key = $der === false ? false : ($field === 'private-key' ? @openssl_pkey_get_private($pem) : @openssl_pkey_get_public($pem));
                if (!$key || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_EC) self::fail(str_replace('-', '_', $field), 'کلید MASQUE باید کلید EC در قالب DER و Base64 باشد.');
            }
        }
        if ($type === 'openvpn') {
            $tlsKeys = array_filter(['tls-auth', 'tls-crypt', 'tls-crypt-v2'], function ($field) use ($settings) { return !empty($settings[$field]); });
            if (count($tlsKeys) > 1) self::fail('tls_auth', 'tls-auth، tls-crypt و tls-crypt-v2 هم‌زمان قابل استفاده نیستند.');
            foreach (['cipher', 'data-ciphers-fallback'] as $field) {
                if (!empty($settings[$field]) && !in_array(strtoupper($settings[$field]), ['AES-128-GCM','AES-192-GCM','AES-256-GCM','AES-128-CBC','AES-192-CBC','AES-256-CBC','AES-CBC','CHACHA20-POLY1305'], true)) self::fail($field, 'روش رمزگذاری OpenVPN پشتیبانی نمی‌شود.');
            }
            if (!empty($settings['auth']) && !in_array(strtoupper($settings['auth']), ['MD5','SHA1','SHA-1','SHA256','SHA384','SHA512'], true)) self::fail('auth', 'روش احراز هویت OpenVPN معتبر نیست.');
            if (!@openssl_x509_read($settings['ca'])) self::fail('ca', 'گواهی CA با قالب PEM معتبر نیست.');
            if (!empty($settings['cert'])) {
                if (!@openssl_x509_read($settings['cert']) || !@openssl_pkey_get_private($settings['key'] ?? '')) self::fail('cert', 'گواهی و کلید کلاینت معتبر نیست.');
                if (!@openssl_x509_check_private_key($settings['cert'], $settings['key'])) self::fail('key', 'کلید خصوصی با گواهی کلاینت مطابقت ندارد.');
            }
        }
        if ($type === 'http' && isset($settings['headers'])) {
            foreach ($settings['headers'] as $key => $value) {
                if (!is_string($key) || !is_string($value) || preg_match('/[\r\n]/', $key . $value)) self::fail('headers', 'هدرها باید کلید و مقدار متنی و بدون خط جدید داشته باشند.');
            }
        }
        if ($type === 'easytier') {
            $defaultListener = isset($settings['no-listener']) && !$settings['no-listener'];
            if (empty($settings['peers']) && empty($settings['listeners']) && !$defaultListener) self::fail('peers', 'حداقل یک همتا یا ورودی تعریف کنید.');
            if (!empty($settings['no-listener']) && !empty($settings['listeners'])) self::fail('listeners', 'ورودی با گزینهٔ no-listener هم‌زمان قابل استفاده نیست.');
            if (!empty($settings['local-public-key']) && empty($settings['local-private-key'])) self::fail('local_private_key', 'کلید عمومی به کلید خصوصی نیاز دارد.');
            if (isset($settings['secure-mode']) && !$settings['secure-mode'] && (!empty($settings['local-public-key']) || !empty($settings['local-private-key']))) self::fail('secure_mode', 'برای کلیدهای محلی secure-mode باید فعال باشد.');
            if (!empty($settings['ipv4'])) self::address('ipv4', $settings['ipv4'], 4, true);
            foreach (['peers', 'listeners', 'mapped-listeners'] as $field) {
                foreach ($settings[$field] ?? [] as $uri) {
                    if (!is_string($uri) || !preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://[^\s]+$~', $uri)) self::fail($field, 'نشانی انتقال همتا معتبر نیست.');
                }
            }
        }
    }
}
