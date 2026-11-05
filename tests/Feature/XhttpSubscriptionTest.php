<?php

namespace Tests\Feature;

use App\Protocols\ClashMeta;
use App\Protocols\ClashNyanpasu;
use App\Protocols\ClashVerge;
use App\Protocols\Hiddify\Hiddify;
use App\Protocols\Mihomo;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\Stash;
use App\Utils\Helper;
use App\Utils\Xhttp;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * VLESS over XHTTP, with REALITY and with TLS, in every format a subscription
 * is served in. V2bX's core/xray/xhttp_e2e_test.go dials such nodes with Xray
 * and with mihomo, using the options exported here.
 */
class XhttpSubscriptionTest extends TestCase
{
    private const USER = ['id' => 7, 'uuid' => '11111111-2222-3333-4444-555555555555', 'u' => 0, 'd' => 0,
        'transfer_enable' => 1073741824, 'expired_at' => 1900000000];

    // Settings as the admin form stores them: posted urlencoded, every value
    // is a string.
    private const PADDED = ['path' => '/xh', 'host' => 'cdn.example.test', 'mode' => 'auto', 'extra' => [
        'xPaddingBytes' => '1200-1500', 'xPaddingObfsMode' => 'true', 'noGRPCHeader' => 'false',
        'scMaxEachPostBytes' => '500000', 'scMaxBufferedPosts' => '20', 'headers' => ['User-Agent' => 'fictional'],
        'xmux' => ['maxConcurrency' => '16-32', 'hKeepAlivePeriod' => '30'],
    ]];

    private static function reality(string $name, array $settings): array
    {
        return ['type' => 'vless', 'name' => $name, 'host' => 'node.example.test', 'port' => 443,
            'tls' => 2, 'flow' => null, 'network' => 'xhttp', 'network_settings' => $settings,
            'tls_settings' => ['server_name' => 'cover.example.test', 'public_key' => 'wCAFe8W0VWbAFB9WzigdzH68oJ3pL2p2xuZ9suP08P0',
                'short_id' => '0123abcd', 'fingerprint' => 'chrome']];
    }

    private static function tls(string $name, array $settings): array
    {
        return ['type' => 'vless', 'name' => $name, 'host' => 'node.example.test', 'port' => 443,
            'tls' => 1, 'flow' => null, 'network' => 'xhttp', 'network_settings' => $settings,
            'tls_settings' => ['server_name' => 'node.example.test', 'allow_insecure' => 0]];
    }

    private static function vmess(string $name): array
    {
        return ['type' => 'vmess', 'name' => $name, 'host' => 'node.example.test', 'port' => 443, 'tls' => 1,
            'network' => 'xhttp', 'networkSettings' => ['path' => '/vm', 'mode' => 'auto']];
    }

    private static function websocket(string $name): array
    {
        return ['type' => 'vless', 'name' => $name, 'host' => 'node.example.test', 'port' => 443, 'tls' => 1,
            'flow' => null, 'network' => 'ws', 'network_settings' => ['path' => '/ws'],
            'tls_settings' => ['server_name' => 'node.example.test']];
    }

    public function testFormValuesTakeXraysTypesAndEmptyObjectsStayObjects(): void
    {
        $stored = Xhttp::normalize(self::PADDED + ['downloadSettings' => []]);
        $this->assertTrue($stored['extra']['xPaddingObfsMode']);
        $this->assertFalse($stored['extra']['noGRPCHeader']);
        $this->assertSame(20, $stored['extra']['scMaxBufferedPosts']);
        $this->assertSame(30, $stored['extra']['xmux']['hKeepAlivePeriod']);
        $this->assertSame('1200-1500', $stored['extra']['xPaddingBytes'], 'ranges stay strings');
        $this->assertArrayNotHasKey('downloadSettings', $stored);

        // What PHP made of the default template's {"extra":{}}, and of empty
        // maps inside it: Xray rejects [] for each of these, and the node
        // never comes up.
        $node = json_encode(Xhttp::forNode(['path' => '/', 'host' => 'xtls.github.io', 'mode' => 'auto',
            'extra' => ['headers' => [], 'xmux' => [], 'downloadSettings' => []]]));
        $this->assertSame('{"path":"\/","host":"xtls.github.io","mode":"auto","extra":{}}', $node);
        $this->assertSame('{}', json_encode(Xhttp::forNode([])));

        $download = Xhttp::normalize(['extra' => ['downloadSettings' => [
            'address' => 'dl.example.test', 'port' => '8443', 'security' => 'reality',
            'realitySettings' => ['show' => 'false', 'shortId' => '12345678'],
            'sockopt' => ['tcpFastOpen' => 'true', 'mark' => '255'],
            'xhttpSettings' => ['path' => '/dl', 'noGRPCHeader' => 'true', 'headers' => []],
        ]]])['extra']['downloadSettings'];
        $this->assertSame(8443, $download['port']);
        $this->assertFalse($download['realitySettings']['show']);
        $this->assertSame('12345678', $download['realitySettings']['shortId'], 'a short id of digits stays a string');
        $this->assertTrue($download['sockopt']['tcpFastOpen']);
        $this->assertSame(255, $download['sockopt']['mark']);
        $this->assertTrue($download['xhttpSettings']['noGRPCHeader']);
        $this->assertArrayNotHasKey('headers', $download['xhttpSettings']);
    }

    public function testShareLinksCarryWhatTheNodeIsConfiguredWith(): void
    {
        $query = function (string $uri): array {
            parse_str(parse_url(trim($uri), PHP_URL_QUERY), $params);
            return $params;
        };

        $padded = $query(Helper::buildVlessUri(self::USER['uuid'], self::reality('padded', self::PADDED)));
        $this->assertSame('xhttp', $padded['type']);
        $this->assertSame('reality', $padded['security']);
        $this->assertSame('/xh', $padded['path']);
        $extra = json_decode($padded['extra'], true);
        $this->assertSame('1200-1500', $extra['xPaddingBytes']);
        $this->assertTrue($extra['xPaddingObfsMode']);

        // Without extra the node takes the settings beside path, host and
        // mode, and a link that dropped them left its client to the defaults.
        $flat = $query(Helper::buildVlessUri(self::USER['uuid'], self::tls('flat', ['path' => '/xh', 'mode' => 'packet-up', 'uplinkHTTPMethod' => 'PUT'])));
        $this->assertSame(['uplinkHTTPMethod' => 'PUT'], json_decode($flat['extra'], true));

        // Nothing to say, nothing said: no "extra=[]" for the client to reject.
        $plain = $query(Helper::buildVlessUri(self::USER['uuid'], self::tls('plain', ['path' => '/xh', 'mode' => 'auto', 'extra' => []])));
        $this->assertArrayNotHasKey('extra', $plain);

        $vmess = json_decode(base64_decode(substr(trim(Helper::buildVmessUri(self::USER['uuid'],
            ['networkSettings' => ['path' => '/vm', 'extra' => ['xPaddingBytes' => '100-200']]] + self::vmess('vm'))), 8)), true);
        $this->assertSame('{"xPaddingBytes":"100-200"}', $vmess['extra']);
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMihomoProfilesCarryTheNodesXhttpSettings(): void
    {
        $download = ['address' => 'dl.example.test', 'port' => '443', 'network' => 'xhttp', 'security' => 'tls',
            'tlsSettings' => ['serverName' => 'dl.example.test', 'alpn' => ['h2'], 'fingerprint' => 'chrome'],
            'xhttpSettings' => ['path' => '/dl', 'host' => 'dl.example.test']];
        // A REALITY node whose download leg is TLS, with the leg's headers
        // and XMUX in its own extra, and one whose leg is plain.
        $tlsLeg = ['address' => 'dl.example.test', 'port' => '443', 'network' => 'xhttp', 'security' => 'tls',
            'xhttpSettings' => ['path' => '/dl', 'extra' => ['headers' => ['X-Leg' => 'down'], 'xmux' => ['maxConnections' => '2']]]];
        $plainLeg = ['address' => '192.0.2.20', 'port' => '80', 'network' => 'xhttp', 'xhttpSettings' => ['host' => 'plain.example.test']];
        $servers = [
            self::reality('xhttp reality', self::PADDED),
            self::tls('xhttp tls', ['path' => '/xh', 'mode' => 'packet-up', 'extra' => [
                'uplinkHTTPMethod' => 'PUT', 'sessionIDPlacement' => 'header', 'seqPlacement' => 'query',
                'uplinkDataPlacement' => 'body', 'downloadSettings' => $download]]),
            self::reality('reality tls leg', ['path' => '/xh', 'host' => 'up.example.test', 'mode' => 'packet-up',
                'extra' => ['headers' => ['X-Leg' => 'up'], 'downloadSettings' => $tlsLeg]]),
            self::reality('reality plain leg', ['path' => '/xh', 'mode' => 'packet-up', 'extra' => ['downloadSettings' => $plainLeg]]),
            self::vmess('vmess xhttp'),
            self::websocket('vless ws'),
        ];
        $exported = [];
        foreach ([ClashMeta::class, Mihomo::class, ClashVerge::class, ClashNyanpasu::class] as $class) {
            $proxies = Yaml::parse((new $class(self::USER, $servers))->handle())['proxies'];
            $byName = array_column($proxies, null, 'name');
            $this->assertArrayNotHasKey('vmess xhttp', $byName, $class . ': mihomo has no XHTTP for VMess');
            $this->assertArrayHasKey('vless ws', $byName, $class);

            $reality = $byName['xhttp reality'];
            $this->assertSame('xhttp', $reality['network'], $class);
            $this->assertSame(['public-key' => 'wCAFe8W0VWbAFB9WzigdzH68oJ3pL2p2xuZ9suP08P0', 'short-id' => '0123abcd'], $reality['reality-opts'], $class);
            $this->assertSame([
                'path' => '/xh', 'host' => 'cdn.example.test', 'mode' => 'auto',
                'headers' => ['User-Agent' => 'fictional'], 'no-grpc-header' => false,
                'x-padding-bytes' => '1200-1500', 'x-padding-obfs-mode' => true,
                'sc-max-each-post-bytes' => '500000',
                'reuse-settings' => ['max-concurrency' => '16-32', 'h-keep-alive-period' => 30],
            ], $reality['xhttp-opts'], $class);

            $tls = $byName['xhttp tls']['xhttp-opts'];
            $this->assertSame('PUT', $tls['uplink-http-method'], $class);
            $this->assertSame('header', $tls['session-placement'], $class);
            $this->assertSame('query', $tls['seq-placement'], $class);
            $this->assertSame(['path' => '/dl', 'host' => 'dl.example.test', 'server' => 'dl.example.test', 'port' => 443,
                'tls' => true, 'servername' => 'dl.example.test', 'client-fingerprint' => 'chrome', 'alpn' => ['h2'],
                'skip-cert-verify' => false, 'ech-opts' => ['enable' => false]],
                $tls['download-settings'], $class);

            // mihomo fills in whatever download-settings leaves out from the
            // node's own connection; Xray builds the leg from its own settings.
            // So the leg's host and server name are its address, its headers
            // and XMUX those of its extra, and it is not the node's REALITY.
            $this->assertSame(['path' => '/dl', 'host' => 'dl.example.test', 'headers' => ['X-Leg' => 'down'],
                'reuse-settings' => ['max-connections' => '2'], 'server' => 'dl.example.test', 'port' => 443,
                'tls' => true, 'servername' => 'dl.example.test', 'skip-cert-verify' => false,
                'reality-opts' => ['public-key' => ''], 'ech-opts' => ['enable' => false]],
                $byName['reality tls leg']['xhttp-opts']['download-settings'], $class);
            $this->assertSame(['path' => '/', 'host' => 'plain.example.test', 'server' => '192.0.2.20', 'port' => 80,
                'tls' => false, 'reality-opts' => ['public-key' => ''], 'ech-opts' => ['enable' => false]],
                $byName['reality plain leg']['xhttp-opts']['download-settings'], $class);
            if ($class === ClashMeta::class) {
                $exported = [$reality, $byName['xhttp tls'], $byName['reality tls leg'], $byName['reality plain leg']];
            }
        }
        // Checked by tests/ValidateMihomoConfig.py with the pinned Mihomo.
        if ($dir = getenv('IRBOARD_MIHOMO_CONTRACT_DIR')) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dir . '/Xhttp.json', json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        }
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testStashGetsXhttpNodesItCanReachAndNoOthers(): void
    {
        $servers = [
            self::reality('xhttp reality', self::PADDED),
            self::tls('xhttp put', ['path' => '/xh', 'mode' => 'packet-up', 'extra' => ['uplinkHTTPMethod' => 'PUT']]),
            // Stash documents no way to turn the node's REALITY off for it.
            self::reality('reality tls leg', ['path' => '/xh', 'mode' => 'packet-up', 'extra' => ['downloadSettings' => [
                'address' => 'dl.example.test', 'port' => 443, 'security' => 'tls', 'xhttpSettings' => ['path' => '/dl']]]]),
            self::vmess('vmess xhttp'),
            self::websocket('vless ws'),
        ];
        $_SERVER['HTTP_HOST'] = 'panel.example.test';
        $config = Yaml::parse((new Stash(self::USER, $servers))->handle());
        $byName = array_column($config['proxies'], null, 'name');
        $this->assertSame(['xhttp reality', 'vless ws'], array_keys($byName));
        $opts = $byName['xhttp reality']['xhttp-opts'];
        $this->assertSame('xhttp', $byName['xhttp reality']['network']);
        $this->assertSame('1200-1500', $opts['x-padding-bytes']);
        $this->assertTrue($opts['x-padding-obfs-mode']);
        $this->assertArrayNotHasKey('reuse-settings', $opts, 'not a Stash option');
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSingBoxProfilesLeaveXhttpNodesOut(): void
    {
        $servers = [self::reality('xhttp reality', self::PADDED), self::vmess('vmess xhttp'), self::websocket('vless ws')];
        foreach ([Singbox::class, SingboxOld::class, Hiddify::class] as $class) {
            $config = json_decode((new $class(self::USER, $servers))->handle()->getContent(), true);
            $tags = array_column($config['outbounds'], 'tag');
            $this->assertNotContains('xhttp reality', $tags, $class . ': sent as plain VLESS, which the node refuses');
            $this->assertNotContains('vmess xhttp', $tags, $class);
            $this->assertContains('vless ws', $tags, $class);
        }
    }
}
