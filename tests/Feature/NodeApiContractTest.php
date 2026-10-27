<?php

namespace Tests\Feature;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Protocols\Mihomo\ManagedServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/** Actual HTTP/controller/schema contract consumed by V2bX, with fictional data. */
class NodeApiContractTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $types = array_merge(['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'anytls', 'mdns', 'trusttunnel'], ManagedServer::TYPES);
        $this->createInstallTables(array_map(fn ($type) => 'v2_server_' . $type, $types));
        config([
            'v2board.server_token' => 'fictional-contract-token',
            'v2board.server_push_interval' => 41,
            'v2board.server_pull_interval' => 31,
            'v2board.server_node_report_min_traffic' => 7,
            'v2board.server_device_online_min_traffic' => 3,
        ]);
        $common = [
            'id' => 1, 'name' => 'contract node', 'group_id' => '[1]',
            'host' => 'node.example.test', 'port' => 2443, 'server_port' => 2443,
            'rate' => '1', 'created_at' => 1700000000, 'updated_at' => 1700000000,
        ];
        $tls = json_encode(['server_name' => 'cover.example.test', 'server_port' => 443, 'xver' => 2, 'short_id' => 'ab', 'private_key' => 'fictional-key']);
        $extra = [
            'shadowsocks' => ['cipher' => '2022-blake3-aes-128-gcm'],
            'vmess' => ['network' => 'ws', 'tls' => 1, 'networkSettings' => '{"path":"/contract"}'],
            'vless' => ['network' => 'tcp', 'tls' => 2, 'tls_settings' => $tls, 'flow' => 'xtls-rprx-vision'],
            'trojan' => ['network' => 'tcp', 'server_name' => 'node.example.test'],
            'tuic' => ['congestion_control' => 'bbr', 'zero_rtt_handshake' => 1],
            'hysteria' => ['version' => 1, 'up_mbps' => 10, 'down_mbps' => 20, 'obfs_password' => 'fictional-obfs'],
            'anytls' => ['tls' => 2, 'tls_settings' => $tls, 'padding_scheme' => '["stop=8"]'],
            'mdns' => ['domain' => '["tunnel.example.test"]', 'encryption_method' => 2, 'encryption_key' => str_repeat('ab', 32)],
            'trusttunnel' => ['hostname' => 'cert.example.test', 'cert_type' => 'self-signed', 'has_ipv6' => 1],
            'http' => ['managed' => 1, 'username' => '', 'password' => '', 'tls' => 1, 'sni' => 'node.example.test'],
            'socks5' => ['managed' => 1, 'username' => '', 'password' => '', 'tls' => 1],
            'mieru' => ['managed' => 1, 'username' => '', 'password' => '', 'transport' => 'TCP'],
            'shadowquic' => ['managed' => 1, 'username' => '', 'password' => ''],
        ];
        foreach ($types as $type) DB::table('v2_server_' . $type)->insert($common + $extra[$type]);
        DB::table('v2_server_hysteria')->insert(array_replace($common, ['id' => 2, 'version' => 2, 'up_mbps' => 0, 'down_mbps' => 0, 'obfs' => 'salamander', 'obfs_password' => 'fictional-obfs']));
    }

    private function endpoint(string $action, string $type = 'shadowsocks', int $id = 1): string
    {
        return '/api/v1/server/UniProxy/' . $action . '?' . http_build_query([
            'node_type' => $type, 'node_id' => $id, 'token' => 'fictional-contract-token',
        ]);
    }

    private function fixture($response): array
    {
        return [
            'body' => base64_encode($response->getContent()),
            'content_type' => $response->headers->get('Content-Type'),
            'etag' => $response->headers->get('ETag'),
        ];
    }

    public function testActualNodeResponsesAndConditionalRequests(): void
    {
        $out = ['configs' => [], 'responses' => []];
        foreach (array_merge(['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'hysteria2', 'anytls', 'mdns', 'trusttunnel', 'v2ray'], ManagedServer::TYPES) as $type) {
            $path = $this->endpoint('config', $type, $type === 'hysteria2' ? 2 : 1);
            $response = $this->getJson($path)->assertOk()->assertJsonPath('server_port', 2443)
                ->assertJsonPath('base_config.push_interval', 41)->assertJsonPath('base_config.pull_interval', 31);
            $out['configs'][$type] = $this->fixture($response);
            if (ManagedServer::supports($type)) {
                $response->assertJsonPath('host', 'node.example.test')
                    ->assertJsonPath('username', '{{uuid}}')->assertJsonPath('password', '{{uuid}}');
            }
            if (in_array($type, ['vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'hysteria2', 'anytls', 'v2ray'], true)) {
                $response->assertJsonPath('host', 'node.example.test');
            }
            $this->getJson($path, ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        }
        $plan = $this->makePlan();
        $this->makeActiveUser($plan, ['id' => 1, 'uuid' => '11111111-2222-3333-4444-555555555555', 'speed_limit' => 23, 'device_limit' => 2]);
        $this->makeActiveUser($plan, ['banned' => 1]);
        $this->makeActiveUser($plan, ['u' => 107374182400]);
        foreach (['json', 'msgpack'] as $format) {
            $headers = ['X-Response-Format' => $format];
            $response = $this->getJson($this->endpoint('user'), $headers)->assertOk();
            $out['responses']['users_' . $format] = $this->fixture($response);
            $this->getJson($this->endpoint('user'), $headers + ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
            if ($format === 'json') {
                $response->assertJsonCount(1, 'users')->assertJsonPath('users.0.id', 1)->assertJsonPath('users.0.device_limit', 2);
            }
        }
        $out['responses']['alive_empty'] = $this->fixture($this->getJson($this->endpoint('alivelist'))->assertOk());
        $this->assertSame('{"alive":{}}', base64_decode($out['responses']['alive_empty']['body']));
        $out['responses']['ack'] = $this->fixture($this->postJson($this->endpoint('status'), ['cpu' => 1.2])->assertOk()->assertExactJson(['data' => true]));
        DB::table('v2_user')->update(['banned' => 1]);
        foreach (['json', 'msgpack'] as $format) {
            $out['responses']['users_empty_' . $format] = $this->fixture($this->getJson($this->endpoint('user'), ['X-Response-Format' => $format])->assertOk());
        }
        // Optional export is consumed by V2bX's committed fixture contract tests.
        if ($dir = getenv('IRBOARD_NODE_CONTRACT_DIR')) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dir . '/contract.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    public function testCertificateHostIsIndependentOfSniAndRealityTarget(): void
    {
        foreach (['vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'anytls'] as $type) {
            $fields = ['host' => 'owned.example.test'];
            if (in_array($type, ['vless', 'anytls'], true)) {
                $fields += ['tls' => 1, 'tls_settings' => json_encode(['server_name' => 'borrowed.example.test'])];
            } elseif ($type === 'vmess') {
                $fields += ['tls' => 1, 'tlsSettings' => json_encode(['serverName' => 'borrowed.example.test'])];
            }
            if (in_array($type, ['trojan', 'tuic', 'hysteria', 'anytls'], true)) {
                $fields['server_name'] = 'client-sni.example.test';
            }
            DB::table('v2_server_' . $type)->where('id', 1)->update($fields);
            $response = $this->getJson($this->endpoint('config', $type))->assertOk()
                ->assertJsonPath('host', 'owned.example.test');
            if (in_array($type, ['vless', 'anytls'], true)) {
                $response->assertJsonPath('tls_settings.server_name', 'borrowed.example.test');
                DB::table('v2_server_' . $type)->where('id', 1)->update(['tls' => 2]);
                $this->getJson($this->endpoint('config', $type))->assertOk()
                    ->assertJsonPath('host', 'owned.example.test')
                    ->assertJsonPath('tls', 2)
                    ->assertJsonPath('tls_settings.server_name', 'borrowed.example.test');
            }
            if (isset($fields['server_name'])) {
                $response->assertJsonPath('server_name', 'client-sni.example.test');
            } else {
                $this->assertArrayNotHasKey('server_name', $response->json());
            }
            // IP addresses stay addresses; the node must ask for a managed
            // certificate domain instead of replacing host with SNI.
            DB::table('v2_server_' . $type)->where('id', 1)->update(['host' => '192.0.2.10']);
            $ip = $this->getJson($this->endpoint('config', $type))->assertOk()->assertJsonPath('host', '192.0.2.10');
            $this->assertNotSame($response->headers->get('ETag'), $ip->headers->get('ETag'));
        }
    }

    public function testEmptyArrayClearsOnlyTheReportingNodesDevices(): void
    {
        $user = $this->makeActiveUser($this->makePlan(), ['device_limit' => 5]);
        $uid = $user->id;
        $this->postJson($this->endpoint('alive'), [$uid => ['192.0.2.1_1']])->assertOk()->assertExactJson(['data' => true]);
        $this->postJson($this->endpoint('alive', 'mdns'), [$uid => ['192.0.2.2_1']])->assertOk();
        $this->assertSame(2, Cache::get('ALIVE_IP_USER_' . $uid)['alive_ip']);
        $this->postJson($this->endpoint('alive'), [$uid => []])->assertOk()->assertExactJson(['data' => true]);
        $data = Cache::get('ALIVE_IP_USER_' . $uid);
        $this->assertSame(1, $data['alive_ip']);
        $this->assertSame([], $data['shadowsocks1']['aliveips']);
        $this->assertSame(['192.0.2.2_1'], $data['mdns1']['aliveips']);
        Cache::forget('ALIVE_LIST');
        $this->getJson($this->endpoint('alivelist'))->assertOk()->assertJsonPath('alive.' . $uid, 1);
    }

    public function testTrafficRetryWithSameReportIdIsAcknowledgedOnce(): void
    {
        Queue::fake();
        $body = [1 => [105, 209], 2 => [11, 13]];
        $headers = ['X-Report-Id' => 'contract-batch-0001'];
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($this->endpoint('push'), $body, $headers)->assertOk()->assertExactJson(['data' => true]);
        }
        Queue::assertPushed(TrafficFetchJob::class, 1);
        Queue::assertPushed(StatUserJob::class, 1);
        Queue::assertPushed(StatServerJob::class, 1);
        $this->postJson($this->endpoint('push'), $body, ['X-Report-Id' => 'contract-batch-0002'])->assertOk();
        Queue::assertPushed(TrafficFetchJob::class, 2);
    }

    public function testManagedProtocolsExchangeUsersDevicesTrafficAndStatus(): void
    {
        $user = $this->makeActiveUser($this->makePlan(), ['speed_limit' => 23, 'device_limit' => 2]);
        foreach (ManagedServer::TYPES as $type) {
            Queue::fake();
            $this->getJson($this->endpoint('user', $type))->assertOk()->assertJsonCount(1, 'users')
                ->assertJsonPath('users.0.id', $user->id)->assertJsonPath('users.0.speed_limit', 23)
                ->assertJsonPath('users.0.device_limit', 2);
            $user->update(['banned' => 1]);
            $this->getJson($this->endpoint('user', $type))->assertOk()->assertJsonCount(0, 'users');
            $user->update(['banned' => 0]);
            $this->getJson($this->endpoint('user', $type))->assertOk()->assertJsonCount(1, 'users');
            $this->postJson($this->endpoint('alive', $type), [$user->id => ['192.0.2.1_1']])->assertOk();
            $this->getJson($this->endpoint('alivelist', $type))->assertOk()->assertJsonPath('alive.' . $user->id, 1);
            $this->postJson($this->endpoint('alive', $type), [$user->id => []])->assertOk();
            $this->getJson($this->endpoint('alivelist', $type))->assertOk()->assertJsonPath('alive.' . $user->id, 0);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->postJson($this->endpoint('push', $type), [$user->id => [105, 209]], ['X-Report-Id' => 'managed-contract-batch-' . $type])->assertOk();
            }
            Queue::assertPushed(TrafficFetchJob::class, 1);
            Queue::assertPushed(StatServerJob::class, 1);
            $this->postJson($this->endpoint('status', $type), ['cpu' => 1.2, 'mem' => ['total' => 1024, 'used' => 128]])
                ->assertOk()->assertExactJson(['data' => true]);
        }
    }
}
