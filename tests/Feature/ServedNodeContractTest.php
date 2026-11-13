<?php

namespace Tests\Feature;

use App\Protocols\ClashMeta;
use App\Protocols\Loon;
use App\Protocols\QuantumultX;
use App\Protocols\Surfboard;
use App\Protocols\Surge;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Mihomo\ManagedServer;
use App\Protocols\Mihomo\Outbound;
use App\Protocols\Mihomo\ProtocolRegistry as Registry;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * Nodes saved through the admin forms as the browser posts them, and what the
 * panel then serves for each: the node's own config and user list (UniProxy,
 * as V2bX asks for them), and the Clash Meta proxy, sing-box outbound and
 * share link a subscriber gets.
 *
 * V2bX's cmd/irboard_served_test.go starts each node on every core that can
 * serve it, from that config and user list, and connects through it as each
 * client would. Export with IRBOARD_NODE_CONTRACT_DIR set: served.json for
 * V2bX, and meta.yaml, the whole Clash Meta profile as served, for the
 * Doctor Mobile app's test/features/mihomo/fixtures/irboard_meta.yaml.
 */
class ServedNodeContractTest extends TestCase
{
    use PanelFixtures;

    private const UUID = '11111111-2222-3333-4444-555555555555';
    /** The master key of tests/Unit/SudokuKeysTest.php. */
    private const SUDOKU_MASTER = '1a1786c2533e7d5b5b1015d717ea8ce09165858dec91327c66bb958dcbd0050a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(array_map(fn ($type) => 'v2_server_' . $type, array_merge(
            ['shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'v2node', 'mdns', 'trusttunnel', 'route'],
            Registry::types()
        )));
        DB::table('v2_server_group')->insert(['id' => 1, 'name' => 'group 1', 'created_at' => time(), 'updated_at' => time()]);
        config(['v2board.server_token' => 'contract-token', 'app.key' => 'base64:' . base64_encode(str_repeat('t', 32))]);
    }

    /** The admin route the panel's own form posts a node of this kind to. */
    private function savePath(string $controller): string
    {
        $action = 'App\\Http\\Controllers\\V1\\Admin\\Server\\' . $controller . 'Controller@save';
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getActionName() === $action) {
                return '/' . $route->uri();
            }
        }
        $this->fail($action . ' has no route');
    }

    private function auth(): array
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $token = 'served-contract-' . bin2hex(random_bytes(16));
        Cache::put($token, $admin->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    /**
     * What the admin bundle's form encoder sends for $data: every value a
     * string, true and false as words, null as an empty value, and an empty
     * object or list not at all.
     */
    private static function form(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $nested = self::form($value);
                if ($nested !== []) {
                    $out[$key] = $nested;
                }
            } elseif (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } else {
                $out[$key] = $value === null ? '' : (string)$value;
            }
        }
        return $out;
    }

    /** A response as the fixtures keep it; bodies in base64, for MessagePack. */
    private static function response($response): array
    {
        return [
            'body' => base64_encode($response->getContent()),
            'content_type' => $response->headers->get('Content-Type'),
            'etag' => $response->headers->get('ETag'),
        ];
    }

    /** Each protocol with the transports and security its form offers. */
    private static function variants(): array
    {
        $tls = ['server_name' => 'node.example.test'];
        $reality = ['server_name' => 'cover.example.test', 'server_port' => '443'];
        $ws = ['path' => '/ws', 'headers' => ['Host' => 'node.example.test']];
        return [
            // VLESS, with the templates of its form.
            'vless/tcp-reality-vision' => ['vless', 'Vless', ['network' => 'tcp', 'tls' => 2, 'flow' => 'xtls-rprx-vision', 'tls_settings' => $reality]],
            'vless/tcp-tls' => ['vless', 'Vless', ['network' => 'tcp', 'tls' => 1, 'tls_settings' => $tls]],
            'vless/tcp-tls-vision' => ['vless', 'Vless', ['network' => 'tcp', 'tls' => 1, 'flow' => 'xtls-rprx-vision', 'tls_settings' => $tls]],
            'vless/ws-tls' => ['vless', 'Vless', ['network' => 'ws', 'tls' => 1, 'tls_settings' => $tls, 'network_settings' => $ws]],
            'vless/ws-none' => ['vless', 'Vless', ['network' => 'ws', 'tls' => 0, 'network_settings' => $ws]],
            'vless/grpc-reality' => ['vless', 'Vless', ['network' => 'grpc', 'tls' => 2, 'tls_settings' => $reality, 'network_settings' => ['serviceName' => 'GunService']]],
            'vless/grpc-tls' => ['vless', 'Vless', ['network' => 'grpc', 'tls' => 1, 'tls_settings' => $tls, 'network_settings' => ['serviceName' => 'GunService']]],
            'vless/httpupgrade-tls' => ['vless', 'Vless', ['network' => 'httpupgrade', 'tls' => 1, 'tls_settings' => $tls, 'network_settings' => ['path' => '/up', 'host' => 'node.example.test']]],
            'vless/xhttp-reality' => ['vless', 'Vless', ['network' => 'xhttp', 'tls' => 2, 'tls_settings' => $reality, 'network_settings' => ['path' => '/xh', 'host' => '', 'mode' => 'auto', 'extra' => []]]],
            'vless/xhttp-tls-padding' => ['vless', 'Vless', ['network' => 'xhttp', 'tls' => 1, 'tls_settings' => $tls, 'network_settings' => ['path' => '/xh', 'mode' => 'auto', 'extra' => ['xPaddingBytes' => '200-400', 'xPaddingObfsMode' => true, 'noGRPCHeader' => false]]]],
            'vless/tcp-reality-encryption' => ['vless', 'Vless', ['network' => 'tcp', 'tls' => 2, 'tls_settings' => $reality, 'encryption' => 'mlkem768x25519plus', 'encryption_settings' => ['mode' => 'native', 'rtt' => '1rtt']]],
            'vless/kcp' => ['vless', 'Vless', ['network' => 'kcp', 'tls' => 0, 'network_settings' => ['header' => ['type' => 'none'], 'seed' => '']]],
            // VMess, with the templates of its form.
            'vmess/tcp' => ['vmess', 'Vmess', ['network' => 'tcp', 'tls' => 0]],
            'vmess/tcp-http' => ['vmess', 'Vmess', ['network' => 'tcp', 'tls' => 0, 'networkSettings' => ['header' => ['type' => 'http', 'request' => ['path' => ['/'], 'headers' => ['Host' => ['www.baidu.com', 'www.bing.com']]], 'response' => []]]]],
            'vmess/ws' => ['vmess', 'Vmess', ['network' => 'ws', 'tls' => 0, 'networkSettings' => $ws]],
            'vmess/ws-tls' => ['vmess', 'Vmess', ['network' => 'ws', 'tls' => 1, 'tlsSettings' => ['serverName' => 'node.example.test'], 'networkSettings' => $ws]],
            'vmess/grpc' => ['vmess', 'Vmess', ['network' => 'grpc', 'tls' => 0, 'networkSettings' => ['serviceName' => 'GunService']]],
            'vmess/kcp' => ['vmess', 'Vmess', ['network' => 'kcp', 'tls' => 0, 'networkSettings' => ['header' => ['type' => 'none'], 'seed' => '']]],
            'vmess/httpupgrade' => ['vmess', 'Vmess', ['network' => 'httpupgrade', 'tls' => 0, 'networkSettings' => ['path' => '/up', 'host' => 'node.example.test']]],
            'vmess/xhttp' => ['vmess', 'Vmess', ['network' => 'xhttp', 'tls' => 0, 'networkSettings' => ['path' => '/xh', 'host' => '', 'mode' => 'auto', 'extra' => []]]],
            // Trojan.
            'trojan/tcp' => ['trojan', 'Trojan', ['network' => 'tcp', 'server_name' => 'node.example.test']],
            'trojan/ws' => ['trojan', 'Trojan', ['network' => 'ws', 'server_name' => 'node.example.test', 'network_settings' => $ws]],
            'trojan/grpc' => ['trojan', 'Trojan', ['network' => 'grpc', 'server_name' => 'node.example.test', 'network_settings' => ['serviceName' => 'GunService']]],
            // Shadowsocks.
            'shadowsocks/aes-128-gcm' => ['shadowsocks', 'Shadowsocks', ['cipher' => 'aes-128-gcm']],
            'shadowsocks/chacha20-ietf-poly1305' => ['shadowsocks', 'Shadowsocks', ['cipher' => 'chacha20-ietf-poly1305']],
            'shadowsocks/2022-blake3-aes-128-gcm' => ['shadowsocks', 'Shadowsocks', ['cipher' => '2022-blake3-aes-128-gcm']],
            'shadowsocks/2022-blake3-aes-256-gcm' => ['shadowsocks', 'Shadowsocks', ['cipher' => '2022-blake3-aes-256-gcm']],
            // Hysteria, TUIC, AnyTLS.
            'hysteria/v1' => ['hysteria', 'Hysteria', ['version' => 1, 'up_mbps' => 100, 'down_mbps' => 100, 'server_name' => 'node.example.test', 'insecure' => 0]],
            'hysteria2/plain' => ['hysteria', 'Hysteria', ['version' => 2, 'server_name' => 'node.example.test', 'insecure' => 0]],
            'hysteria2/salamander' => ['hysteria', 'Hysteria', ['version' => 2, 'obfs' => 'salamander', 'server_name' => 'node.example.test', 'insecure' => 0]],
            'hysteria2/bandwidth' => ['hysteria', 'Hysteria', ['version' => 2, 'up_mbps' => 100, 'down_mbps' => 200, 'server_name' => 'node.example.test', 'insecure' => 0]],
            'tuic/bbr' => ['tuic', 'Tuic', ['server_name' => 'node.example.test', 'insecure' => 0, 'disable_sni' => 0, 'zero_rtt_handshake' => 0, 'congestion_control' => 'bbr', 'udp_relay_mode' => 'native']],
            'tuic/cubic-0rtt' => ['tuic', 'Tuic', ['server_name' => 'node.example.test', 'insecure' => 0, 'disable_sni' => 0, 'zero_rtt_handshake' => 1, 'congestion_control' => 'cubic', 'udp_relay_mode' => 'quic']],
            'anytls/tls' => ['anytls', 'AnyTLS', ['server_name' => 'node.example.test', 'insecure' => 0]],
            'anytls/reality' => ['anytls', 'AnyTLS', ['tls' => 2, 'tls_settings' => $reality, 'insecure' => 0]],
        ];
    }

    /**
     * The nodes V2bX's Mihomo core serves for the panel, each as its form
     * starts it (the protocol's example, with blank per-user templates) and
     * with what an admin changes most.
     */
    private static function managedVariants(): array
    {
        $shadowTls = ['mode' => 'shadow-tls', 'version' => 3, 'host' => 'cover.example.test'];
        return [
            'http/plain' => ['http', ['tls' => false]],
            'http/tls' => ['http', ['tls' => true, 'sni' => 'node.example.test']],
            'socks5/plain' => ['socks5', []],
            'socks5/tls' => ['socks5', ['tls' => true]],
            'mieru/tcp' => ['mieru', []],
            'mieru/udp' => ['mieru', ['transport' => 'UDP']],
            'shadowquic/default' => ['shadowquic', ['sni' => 'node.example.test']],
            // Datagrams carry no UDP packet larger than a QUIC datagram frame;
            // streams carry any.
            'shadowquic/udp-over-stream' => ['shadowquic', ['sni' => 'node.example.test', 'udp_over_stream' => true]],
            // A fixed master key, so that the export does not change by itself;
            // left blank, the panel generates one.
            'sudoku/default' => ['sudoku', ['key' => self::SUDOKU_MASTER]],
            'sudoku/http-mask' => ['sudoku', ['key' => self::SUDOKU_MASTER, 'http_mask' => true, 'http_mask_mode' => 'stream']],
            'snell/shadow-tls' => ['snell', ['obfs_opts' => $shadowTls]],
        ];
    }

    /** The admin route the native form of a Mihomo protocol posts to. */
    private function nativeSavePath(string $type): string
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\Server\\ProtocolCatalogController@fetch') {
                return preg_replace('~/protocols$~', '/' . $type . '/save', '/' . $route->uri());
            }
        }
        $this->fail('The native protocol routes are missing');
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEveryFormVariantIsServedToItsNodeAndItsClients(): void
    {
        $headers = $this->auth();
        $saved = [];
        $port = 20000;
        foreach (self::variants() as $name => [$table, $controller, $fields]) {
            $common = ['name' => $name, 'group_id' => [1], 'host' => 'node.example.test', 'port' => ++$port,
                'server_port' => 2443, 'rate' => 1, 'show' => 1];
            $this->withHeaders($headers)->post($this->savePath($controller), self::form($common + $fields))
                ->assertOk()->assertJsonPath('data', true);
            $id = DB::table('v2_server_' . $table)->max('id');
            $type = $table === 'hysteria' ? ((int)$fields['version'] === 2 ? 'hysteria2' : 'hysteria') : $table;
            $config = $this->getJson('/api/v1/server/UniProxy/config?' . http_build_query(['node_type' => $type, 'node_id' => $id, 'token' => 'contract-token']))
                ->assertOk();
            $saved[$name] = ['node_type' => $type, 'node_id' => $id, 'config' => self::response($config)];
        }
        // The native forms post JSON. A managed node listens where clients
        // connect unless told otherwise, and its credentials are templates.
        foreach (self::managedVariants() as $name => [$type, $fields]) {
            $node = array_replace(Registry::storage($type, Outbound::catalog()[$type]['example']), [
                'name' => $name, 'group_id' => ['1'], 'route_id' => [], 'parent_id' => null, 'tags' => [],
                'rate' => 1, 'sort' => 0, 'show' => true, 'managed' => true,
                'host' => 'node.example.test', 'port' => ++$port, 'server_port' => 2443,
            ], $fields);
            $this->withHeaders($headers)->postJson($this->nativeSavePath($type), $node)
                ->assertOk()->assertJsonPath('data', true);
            $id = Registry::model($type)::max('id');
            $config = $this->getJson('/api/v1/server/UniProxy/config?' . http_build_query(['node_type' => $type, 'node_id' => $id, 'token' => 'contract-token']))
                ->assertOk();
            $saved[$name] = ['node_type' => $type, 'node_id' => $id, 'config' => self::response($config)];
        }
        $this->assertSame(ManagedServer::TYPES, array_values(array_unique(array_column(self::managedVariants(), 0))), 'a managed protocol has no node in the contract');

        // What a subscriber in the nodes' group gets.
        $user = $this->makeActiveUser($this->makePlan(), ['uuid' => self::UUID]);
        $servers = (new ServerService())->getAvailableServers($user);
        $meta = (new ClashMeta($user, $servers))->handle();
        $proxies = array_column(Yaml::parse($meta)['proxies'], null, 'name');
        $outbounds = array_column(json_decode((new Singbox($user, $servers))->handle()->getContent(), true)['outbounds'], null, 'tag');
        foreach ($servers as $server) {
            $this->assertArrayHasKey($server['name'], $saved, 'a node of the test was not served');
            $saved[$server['name']]['clash'] = $proxies[$server['name']] ?? null;
            $saved[$server['name']]['singbox'] = $outbounds[$server['name']] ?? null;
            $saved[$server['name']]['link'] = trim(Helper::buildUri($user['uuid'], $server));
        }
        foreach ($saved as $name => $variant) {
            $this->assertArrayHasKey('link', $variant, $name . ' is not in the subscription');
            // The subscriber is on the node's user list, as V2bX fetches it.
            $users = $this->withHeaders(['X-Response-Format' => 'msgpack'])
                ->get('/api/v1/server/UniProxy/user?' . http_build_query(['node_type' => $variant['node_type'], 'node_id' => $variant['node_id'], 'token' => 'contract-token']))
                ->assertOk();
            $saved[$name]['users'] = self::response($users);
        }
        // A client is given no profile for a node it cannot reach, rather
        // than one for plain TCP: mihomo has no mKCP and no VMess over XHTTP,
        // and sing-box no mKCP, XHTTP or VLESS encryption.
        foreach (['vmess/xhttp', 'vmess/kcp', 'vless/kcp'] as $name) {
            $this->assertNull($saved[$name]['clash'], $name . ' in the Clash Meta profile');
        }
        foreach (['vless/xhttp-reality', 'vless/xhttp-tls-padding', 'vless/tcp-reality-encryption', 'vmess/xhttp', 'vmess/kcp', 'vless/kcp'] as $name) {
            $this->assertNull($saved[$name]['singbox'], $name . ' in the sing-box profile');
        }
        // HTTPUpgrade is WebSocket with v2ray-http-upgrade to mihomo, and its
        // own transport to sing-box.
        foreach (['vless/httpupgrade-tls', 'vmess/httpupgrade'] as $name) {
            $this->assertSame('ws', $saved[$name]['clash']['network'], $name);
            $this->assertTrue($saved[$name]['clash']['ws-opts']['v2ray-http-upgrade'], $name);
            $this->assertSame(['type' => 'httpupgrade', 'path' => '/up', 'host' => 'node.example.test'], $saved[$name]['singbox']['transport'], $name);
        }
        // sing-box reads "auto" as "zero" over TLS, which Xray 26 refuses.
        $this->assertSame('aes-128-gcm', $saved['vmess/ws-tls']['singbox']['security']);
        // Plain VMess UDP is bound to its first destination: a second one
        // (STUN, games) over the same socket never answered. XUDP is not.
        foreach (['vmess/tcp', 'vmess/tcp-http', 'vmess/ws', 'vmess/ws-tls', 'vmess/grpc', 'vmess/httpupgrade'] as $name) {
            $this->assertSame('xudp', $saved[$name]['clash']['packet-encoding'] ?? null, $name);
            $this->assertSame('xudp', $saved[$name]['singbox']['packet_encoding'] ?? null, $name);
        }
        // mihomo has no padding defaults of its own in obfuscation mode.
        $this->assertSame('queryInHeader', $saved['vless/xhttp-tls-padding']['clash']['xhttp-opts']['x-padding-placement']);

        // Clients with TCP and WebSocket only get no node over anything else:
        // their builders sent gRPC, mKCP and the rest as plain TCP. (Surge and
        // Surfboard name the subscription's host, as a request gives it.)
        $_SERVER['HTTP_HOST'] = 'panel.example.test';
        $surge = (new Surge($user, $servers))->handle();
        $surfboard = (new Surfboard($user, $servers))->handle();
        foreach (['vmess/grpc', 'vmess/kcp', 'vmess/httpupgrade', 'vmess/xhttp', 'vmess/tcp-http', 'trojan/grpc'] as $name) {
            $this->assertStringNotContainsString($name . '=', $surge, $name . ' in the Surge profile');
            $this->assertStringNotContainsString($name . '=', $surfboard, $name . ' in the Surfboard profile');
        }
        foreach (['vmess/tcp', 'vmess/ws', 'vmess/ws-tls', 'trojan/tcp', 'trojan/ws'] as $name) {
            $this->assertStringContainsString($name . '=', $surge, $name . ' in the Surge profile');
        }
        $loon = (new Loon($user, $servers))->handle();
        foreach (['vmess/grpc', 'vmess/kcp', 'vmess/httpupgrade', 'vmess/xhttp'] as $name) {
            $this->assertStringNotContainsString($name . '=', $loon, $name . ' in the Loon profile');
        }
        $this->assertStringContainsString('vmess/tcp-http=', $loon, 'Loon has the HTTP header');
        $this->assertStringNotContainsString('tag=vmess/kcp', (new QuantumultX($user, $servers))->handle());

        if ($dir = getenv('IRBOARD_NODE_CONTRACT_DIR')) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dir . '/served.json', json_encode(['uuid' => self::UUID, 'nodes' => $saved], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            // The Clash Meta profile as its bytes: a client that reads the
            // YAML itself meets the scalars as they are written, not as parsed.
            file_put_contents($dir . '/meta.yaml', $meta);
        }
    }
}
