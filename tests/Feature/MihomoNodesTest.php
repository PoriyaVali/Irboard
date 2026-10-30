<?php

namespace Tests\Feature;

use App\Protocols\Mihomo\Outbound;
use App\Protocols\Mihomo\ProtocolRegistry as Registry;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\Fixtures\MihomoProfiles;
use Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class MihomoNodesTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpPanel();
        $this->createInstallTables(array_map(function ($type) { return 'v2_server_' . $type; }, array_merge([
            'shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'v2node', 'mdns', 'trusttunnel', 'route',
        ], Registry::types())));
        foreach ([1, 2] as $id) DB::table('v2_server_group')->insert(['id' => $id, 'name' => 'group ' . $id, 'created_at' => time(), 'updated_at' => time()]);
        config(['app.key' => 'base64:' . base64_encode(str_repeat('t', 32))]);
    }

    private function path(string $type = 'protocols', string $action = ''): string
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\Server\\ProtocolCatalogController@fetch') {
                return preg_replace('~/protocols$~', '/' . $type . ($action ? '/' . $action : ''), '/' . $route->uri());
            }
        }
        $this->fail('Native protocol catalog route is missing');
    }

    private function auth(array $attrs = ['is_admin' => 1]): array
    {
        $user = $this->makeUser($attrs); $token = 'protocol-test-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function node(string $type = 'socks5', array $attrs = [], ?array $settings = null): array
    {
        return array_replace(Registry::storage($type, $settings ?? MihomoProfiles::settings($type)), [
            'name' => 'native ' . $type, 'group_id' => ['1'], 'route_id' => [], 'parent_id' => null,
            'tags' => ['fixture'], 'rate' => 1, 'sort' => 0, 'show' => true,
        ], $attrs);
    }

    public function testManagedServerContractAndUniqueSubscriptionCredentials(): void
    {
        config(['v2board.server_token' => 'managed-test-key']);
        $headers = $this->auth();
        foreach (\App\Protocols\Mihomo\ManagedServer::TYPES as $type) {
            $node = $this->node($type, ['managed' => true, 'username' => '', 'password' => '', 'server_port' => 32001]);
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $node)->assertOk();
            $stored = Registry::model($type)::first();
            $api = '/api/v1/server/UniProxy/config?token=managed-test-key&node_type=' . $type . '&node_id=' . $stored->id;
            $body = $this->getJson($api)->assertOk()->assertJsonPath('server_port', 32001)
                ->assertJsonPath('username', '{{uuid}}')->assertJsonPath('password', '{{uuid}}')->json();
            $this->assertArrayNotHasKey('private_key', $body);
            $this->assertArrayNotHasKey('certificate', $body);
            Cache::put(CacheKey::get('SERVER_' . strtoupper($type) . '_LAST_CHECK_AT', $stored->id), time(), 3600);
            Cache::put(CacheKey::get('SERVER_' . strtoupper($type) . '_LAST_PUSH_AT', $stored->id), time(), 3600);
            $managed = array_values(array_filter((new ServerService())->getAllProtocolNodes(), function ($row) use ($type) { return $row['type'] === $type; }));
            $this->assertTrue($managed[0]['managed']);
            $runtimeNodes = (new ServerService())->getAllServers(false);
            $matches = array_values(array_filter($runtimeNodes, function ($row) use ($type) { return $row['type'] === $type; }));
            $this->assertSame(2, $matches[0]['available_status']);

            $outbound = Outbound::render($stored->toArray() + ['type' => $type], ['id' => 17, 'uuid' => 'individual-secret']);
            $this->assertSame('individual-secret', $outbound['username']);
            $this->assertSame('individual-secret', $outbound['password']);
            $node['id'] = $stored->id; $node['password'] = 'shared-password';
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $node)->assertUnprocessable()->assertJsonValidationErrors('password');
            $stored->update(['managed' => false]);
            $this->getJson($api)->assertStatus(501);
        }
    }

    // V2bX's Mihomo core applies only blocking routes and refuses to start a
    // node with DNS or outbound routes, so the panel must not assign them.
    public function testManagedNodesTakeOnlyRoutesTheMihomoCoreApplies(): void
    {
        $headers = $this->auth();
        $route = function (string $action, array $match = ['example.com'], string $value = '') {
            return DB::table('v2_server_route')->insertGetId(['remarks' => $action . ' route', 'match' => json_encode($match), 'action' => $action, 'action_value' => $value, 'created_at' => time(), 'updated_at' => time()]);
        };
        $block = $route('protocol', ['bittorrent']);
        $dns = $route('dns', ['example.com'], '1.1.1.1');
        $outbound = $route('route', ['example.com'], '{"type":"direct"}');
        foreach (\App\Protocols\Mihomo\ManagedServer::TYPES as $type) {
            $model = Registry::model($type);
            foreach ([$dns, $outbound] as $refused) {
                $node = $this->node($type, ['managed' => true, 'username' => '', 'password' => '', 'server_port' => 32001, 'route_id' => [$block, $refused]]);
                $this->withHeaders($headers)->postJson($this->path($type, 'save'), $node)->assertUnprocessable()->assertJsonValidationErrors('route_id');
                $this->assertSame(0, $model::count());
            }
            // An external service never reaches V2bX; its routes are its own.
            $external = $this->node($type, ['managed' => false, 'route_id' => [$dns]]);
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $external)->assertOk();
            $managed = $this->node($type, ['name' => 'managed ' . $type, 'managed' => true, 'username' => '', 'password' => '', 'server_port' => 32001, 'route_id' => [$block]]);
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $managed)->assertOk();
            $root = $model::where('managed', true)->first();
            // A relay's routes reach only subscriptions.
            $relay = $this->node($type, ['name' => 'relay ' . $type, 'parent_id' => $root->id, 'server_port' => null, 'route_id' => [$outbound]]);
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $relay)->assertOk();
        }
        // The route a managed root uses cannot become one the core refuses.
        $edit = ['id' => $block, 'remarks' => 'now dns', 'match' => ['example.com'], 'action' => 'dns', 'action_value' => '1.1.1.1'];
        $this->withHeaders($headers)->postJson($this->path('route', 'save'), $edit)->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertSame('protocol', DB::table('v2_server_route')->where('id', $block)->value('action'));
        $edit = ['id' => $block, 'remarks' => 'block ip', 'match' => ['10.0.0.0/8'], 'action' => 'block_ip'];
        $this->withHeaders($headers)->postJson($this->path('route', 'save'), $edit)->assertOk();
        $edit = ['id' => $dns, 'remarks' => 'dns for externals', 'match' => ['example.org'], 'action' => 'dns', 'action_value' => '8.8.8.8'];
        $this->withHeaders($headers)->postJson($this->path('route', 'save'), $edit)->assertOk();
    }

    public function testManagedCredentialsNeedTheUuidAndRelaysNeedNoListenPort(): void
    {
        $headers = $this->auth();
        foreach (\App\Protocols\Mihomo\ManagedServer::TYPES as $type) {
            $model = Registry::model($type);
            // {{user_id}} is a public sequence number, not a per-user secret.
            $guessable = $this->node($type, ['managed' => true, 'username' => '{{user_id}}', 'password' => 'pass-{{user_id}}', 'server_port' => 32001]);
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $guessable)->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->assertSame(0, $model::count());
            $readable = $this->node($type, ['managed' => true, 'username' => 'user-{{user_id}}', 'password' => '', 'server_port' => 32001]);
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $readable)->assertOk();
            $root = $model::first();
            // A relay inherits the root's mode; V2bX listens only for the root.
            $relay = $this->node($type, ['name' => 'relay ' . $type, 'parent_id' => $root->id, 'server_port' => null]);
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $relay)->assertOk();
            $this->assertNull($model::where('parent_id', $root->id)->value('server_port'));
            $readable['id'] = $root->id; $readable['rate'] = 2;
            $this->withHeaders($headers)->postJson($this->path($type, 'save'), $readable)->assertOk();
        }
    }

    public function testEveryProtocolHasItsOwnRealTableModelColumnsAndProtectedCrud(): void
    {
        $this->assertCount(15, Registry::types());
        $this->assertFalse(Schema::hasTable('v2_server_mihomo'));
        $staff = $this->auth(['is_staff' => 1]); $ordinary = $this->auth([]);
        foreach (Registry::all() as $type => $definition) {
            $model = $definition['model'];
            $this->assertSame($definition['table'], (new $model())->getTable());
            $this->assertFalse(Schema::hasColumn($definition['table'], 'outbound_settings'));
            $this->assertTrue(Schema::hasColumns($definition['table'], array_merge(['parent_id', 'server_port', 'rate'], array_keys($definition['fields']))));
            foreach (['save', 'update', 'copy', 'drop'] as $action) {
                foreach ([[], $ordinary, $staff] as $headers) $this->flushHeaders()->withHeaders($headers)->postJson($this->path($type, $action))->assertForbidden();
            }
            $this->assertSame(0, $model::count());
        }
        $this->flushHeaders()->getJson($this->path())->assertForbidden();
    }

    public function testAllFifteenProtocolsSaveAndEditDedicatedTypedColumns(): void
    {
        $this->withHeaders($this->auth());
        $this->getJson($this->path())->assertOk()->assertJsonCount(15, 'data.protocols')->assertJsonPath('data.version', '1.19.33');
        foreach (Registry::all() as $type => $definition) {
            $this->postJson($this->path($type, 'save'), $this->node($type))->assertOk()->assertExactJson(['data' => true]);
            $model = $definition['model']; $saved = $model::firstOrFail();
            $this->assertSame(1, $saved->id, 'Each table owns its id sequence');
            $this->assertEquals(MihomoProfiles::settings($type), Registry::options($type, $saved->toArray()));
            $this->assertSame(['1'], $saved->group_id);
            foreach ($definition['fields'] as $column => $field) {
                if ($field['kind'] === 'bool' && $saved->$column !== null) $this->assertIsBool($saved->$column);
                if ($field['kind'] === 'json' && $saved->$column !== null) $this->assertIsArray($saved->$column);
            }
            $this->postJson($this->path($type, 'save'), $this->node($type, ['id' => 1, 'sort' => 15, 'name' => 'edited ' . $type]))->assertOk();
            $this->assertSame(15, $saved->fresh()->sort);
            $this->assertSame('edited ' . $type, $saved->fresh()->name);
        }
        $rows = $this->getJson($this->path('manage', 'getNodes'))->assertOk()->assertJsonCount(15, 'data')->json('data');
        $types = array_column($rows, 'type'); sort($types); $expected = Registry::types(); sort($expected);
        $this->assertSame($expected, $types);
    }

    public function testIndependentCopyVisibilityDeleteAndSharedSortWithOverlappingIds(): void
    {
        $this->withHeaders($this->auth());
        foreach (Registry::all() as $type => $d) {
            $model = $d['model']; $model::create($this->node($type));
            $this->postJson($this->path($type, 'copy'), ['id' => 1])->assertOk();
            $copy = $model::findOrFail(2); $this->assertSame(0, $copy->show);
            $this->assertEquals(Registry::options($type, $model::find(1)->toArray()), Registry::options($type, $copy->toArray()));
            $this->postJson($this->path($type, 'update'), ['id' => 1, 'show' => false])->assertOk();
            $this->assertSame(0, $model::find(1)->show);
            $this->postJson($this->path($type, 'drop'), ['id' => 2])->assertOk();
            $this->postJson($this->path($type, 'drop'), ['id' => 2])->assertNotFound();
            $this->assertSame(1, $model::count());
        }
        $positions = []; foreach (Registry::types() as $index => $type) $positions[$type] = [1 => $index + 10];
        $this->postJson($this->path('manage', 'sort'), $positions)->assertOk();
        foreach (Registry::types() as $index => $type) $this->assertSame($index + 10, Registry::model($type)::find(1)->sort);
        $this->postJson($this->path('manage', 'sort'), ['socks5' => [1 => 0], 'http' => [999 => 1]])->assertNotFound();
        $this->assertSame(11, Registry::model('socks5')::find(1)->sort);
        $this->postJson($this->path('manage', 'sort'), ['socks5' => ['invalid' => 0]])->assertStatus(422);
    }

    public function testParentsInheritSettingsButKeepRelayAddressGroupsAndRate(): void
    {
        $this->withHeaders($this->auth());
        foreach (Registry::types() as $type) {
            $model = Registry::model($type); $parent = $model::create($this->node($type, ['show' => 0]));
            $child = $this->node($type, ['name' => 'relay ' . $type, 'parent_id' => $parent->id, 'host' => $parent->host ? 'relay.example.test' : null, 'rate' => 2.5, 'group_id' => ['2']]);
            foreach (Registry::all()[$type]['fields'] as $column => $field) unset($child[$column]);
            $this->postJson($this->path($type, 'save'), $child)->assertOk();
            $row = $model::findOrFail(2)->toArray();
            $effective = Outbound::inherit($type, $row, [1 => $parent->toArray(), 2 => $row]);
            $this->assertSame($child['host'], $effective['host']);
            $this->assertSame(2.5, (float)$effective['rate']);
            $this->assertSame(['2'], $effective['group_id']);
            foreach (Registry::all()[$type]['fields'] as $column => $field) $this->assertEquals($parent->$column, $effective[$column] ?? null);
            $this->postJson($this->path($type, 'save'), $this->node($type, ['id' => 1, 'parent_id' => 2]))->assertStatus(422);
            $this->postJson($this->path($type, 'save'), $this->node($type, ['id' => 2, 'parent_id' => 2]))->assertStatus(422);
            $this->postJson($this->path($type, 'drop'), ['id' => '1'])->assertStatus(422);
            $this->assertNotNull($model::find(1));
        }
        $user = $this->makeActiveUser($this->makePlan(['group_id' => 2]));
        $available = (new ServerService())->getAvailableServers($user); $this->assertCount(15, $available);
        foreach ($available as $row) if ($row['host']) $this->assertSame('relay.example.test', $row['mihomo']['server']);
    }

    public function testInvalidCommonFieldsAndForeignProtocolFieldsNeverWrite(): void
    {
        $this->withHeaders($this->auth());
        foreach ([['group_id' => []], ['group_id' => [999]], ['group_id' => [1,1]], ['route_id' => [999]], ['port' => 0], ['port' => 65536], ['rate' => -1], ['parent_id' => 999], ['type' => 'http'], ['type' => null], ['protocol' => null], ['outbound_settings' => []], ['protocol' => 'http'], ['outbound_settings' => ['password' => 'bad']]] as $attrs) {
            $this->postJson($this->path('socks5', 'save'), $this->node('socks5', $attrs))->assertStatus(422);
        }
        $this->assertSame(0, Registry::model('socks5')::count());
    }

    public function testGroupVisibilityGrantsTemplatesAndCredentialBoundary(): void
    {
        $one = $this->makeActiveUser($this->makePlan()); $two = $this->makeActiveUser($this->makePlan());
        foreach (['socks5', 'http', 'gostrelay'] as $type) {
            $model = Registry::model($type); $settings = MihomoProfiles::settings($type);
            $settings['username'] = '{{user_id}}'; $settings['password'] = '{{uuid}}';
            if ($type === 'http') $settings['headers'] = ['X-Test' => 'subscriber-{{user_id}}'];
            $model::create($this->node($type, [], $settings));
        }
        Registry::model('ssh')::create($this->node('ssh', ['show' => false]));
        Registry::model('snell')::create($this->node('snell', ['group_id' => ['2']]));
        $available = (new ServerService())->getAvailableServers($one); $this->assertCount(3, $available);
        foreach ($available as $server) {
            $this->assertArrayNotHasKey('password', $server); $this->assertArrayNotHasKey('private_key', $server);
            $this->assertSame($one->uuid, $server['mihomo']['password']); $this->assertSame((string)$one->id, $server['mihomo']['username']);
            $this->assertNull($server['is_online']); $this->assertFalse($server['managed']);
            $this->assertStringStartsWith($server['type'] . '-', $server['cache_key']);
        }
        $other = (new ServerService())->getAvailableServers($two);
        $this->assertSame($two->uuid, $other[0]['mihomo']['password']); $this->assertNotSame($available[0]['cache_key'], $other[0]['cache_key']);
        DB::table('v2_user_group')->insert(['user_id' => $one->id, 'group_id' => 2, 'is_paid' => 0, 'created_at' => time(), 'updated_at' => time()]);
        $this->assertCount(4, (new ServerService())->getAvailableServers($one));
        DB::table('v2_user_group')->where('user_id', $one->id)->update(['expired_at' => time() - 1]);
        $this->assertCount(3, (new ServerService())->getAvailableServers($one));
    }

    public function testMissingUpgradeDoesNotBreakExistingSubscriptionsAndSaveReports503(): void
    {
        Schema::drop(Registry::table('socks5'));
        $user = $this->makeActiveUser($this->makePlan()); $this->assertSame([], (new ServerService())->getAvailableServers($user));
        $this->withHeaders($this->auth())->postJson($this->path('socks5', 'save'), $this->node())->assertStatus(503);
        DB::statement('ALTER TABLE v2_server_http DROP COLUMN server_port');
        $this->postJson($this->path('http', 'save'), $this->node('http'))->assertStatus(503);
    }

    public function testNodeGroupsAndOfflineMonitoringUseNativeTypesWithoutClaimingCoreSupport(): void
    {
        Registry::model('socks5')::create($this->node());
        $this->withHeaders($this->auth());
        $this->getJson($this->path('group', 'fetch'))->assertOk()->assertJsonPath('data.0.server_count', 1);
        $this->postJson($this->path('group', 'drop'), ['id' => 1])->assertStatus(500);
        $this->assertSame([], (new ServerService())->getAllServers(false)); $this->artisan('check:server')->assertExitCode(0);
        foreach (Registry::types() as $type) {
            foreach (['LAST_CHECK_AT','LAST_PUSH_AT','ONLINE_USER','LOAD_STATUS'] as $suffix) $this->assertSame('SERVER_' . strtoupper($type) . '_' . $suffix . '_1', CacheKey::get('SERVER_' . strtoupper($type) . '_' . $suffix, 1));
        }
        config(['v2board.server_token' => 'test-core-token']);
        $this->getJson('/api/v1/server/UniProxy/config?' . http_build_query(['token' => 'test-core-token', 'node_id' => 1, 'node_type' => 'socks5']))->assertStatus(501);
    }

    public function testRangePeerAndWhitespaceSettingsRoundTripThroughOwnColumns(): void
    {
        $this->withHeaders($this->auth());
        $mieru = MihomoProfiles::settings('mieru'); unset($mieru['port']); $mieru['port-range'] = '2090-2099';
        $this->postJson($this->path('mieru', 'save'), $this->node('mieru', [], $mieru))->assertOk();
        $this->assertNull(Registry::model('mieru')::first()->port);
        $wg = MihomoProfiles::settings('wireguard');
        $wg['peers'] = [['server' => $wg['server'], 'port' => $wg['port'], 'public-key' => $wg['public-key'], 'allowed-ips' => ['0.0.0.0/0','::/0']]];
        unset($wg['server'], $wg['port'], $wg['public-key']);
        $this->postJson($this->path('wireguard', 'save'), $this->node('wireguard', [], $wg))->assertOk();
        $this->assertEquals($wg, Registry::options('wireguard', Registry::model('wireguard')::first()->toArray()));
        $settings = ['server' => '192.0.2.10', 'port' => 1080, 'username' => '', 'password' => "  test\npassword  "];
        $this->postJson($this->path('socks5', 'save'), $this->node('socks5', [], $settings))->assertOk();
        $this->assertEquals($settings, Registry::options('socks5', Registry::model('socks5')::first()->toArray()));
    }

    public function testProtocolSpecificErrorsAreRejectedBeforeAnyWrite(): void
    {
        $this->withHeaders($this->auth());
        $cases = [
            ['ssr', ['cipher' => 'aes-128-gcm']], ['ssr', ['ssr_protocol' => 'invented']], ['ssr', ['obfs' => 'invented']],
            ['socks5', ['tls' => 'invalid']], ['socks5', ['psk' => 'foreign-protocol-field']],
            ['http', ['headers' => ['X-Test' => ['nested-not-supported']]]], ['http', ['headers' => ['X-Test' => "header\ninjection"]]],
            ['snell', ['version' => 2, 'udp' => true]], ['snell', ['version' => 9]], ['snell', ['obfs_opts' => ['mode' => 'invalid']]],
            ['wireguard', ['private_key' => 'invalid']], ['wireguard', ['ip' => '999.0.0.2']], ['wireguard', ['ipv6' => '10.0.0.1']], ['wireguard', ['reserved' => [0, 1]]],
            ['wireguard', ['peers' => [['server' => 'peer.test', 'port' => 51820, 'public-key' => base64_encode(str_repeat('a',32)), 'reserved' => 'bad', 'allowed-ips' => ['0.0.0.0/0']]]]],
            ['shadowquic', ['username' => '']], ['shadowquic', ['quic_versions' => ['v3']]], ['shadowquic', ['alpn' => [true]]],
            ['gostrelay', ['tls' => 'invalid']], ['gostrelay', ['ssr_protocol' => 'foreign-field']],
            ['ssh', ['username' => '']], ['ssh', ['password' => '', 'private_key' => '']],
            ['ssh', ['private_key' => '/root/.ssh/id_ed25519']], ['ssh', ['private_key_passphrase' => 'not-encrypted']],
            ['ssh', ['host_key' => ['SHA256:2ZlRt7qXAvE3DNk7mq0Vw4m0kdz3jXZ5x0Qn5UeTq3c']]], ['ssh', ['host_key_algorithms' => ['ecdsa-sha2-nistp256']]],
            ['socks5', ['fingerprint' => 'chrome']], ['socks5', ['fingerprint' => str_repeat('ab', 31)]], ['http', ['certificate' => 'client.crt', 'private_key' => 'client.key']],
            ['mieru', ['traffic_pattern' => 'GgIQZQ==']], ['zerotier', ['state_dir' => '/var/lib/zerotier']], ['zerotier', ['planet' => '../planet']], ['easytier', ['state_dir' => 'nodes/../../x']],
            ['mieru', ['port_range' => '8000-7000']], ['mieru', ['port_range' => '0-7000', 'port' => null]], ['mieru', ['transport' => 'udp']], ['mieru', ['handshake_mode' => 'invalid']],
            ['mieru', ['username' => str_repeat('u', 65)]], ['mieru', ['password' => '{{uuid}}-{{uuid}}']], ['socks5', ['username' => str_repeat('u', 220) . '{{uuid}}']],
            ['sudoku', ['aead_method' => 'invalid']], ['sudoku', ['padding_min' => 8, 'padding_max' => 7]], ['sudoku', ['table_type' => 'invalid']], ['sudoku', ['httpmask' => ['path-root' => 'two/segments']]],
            ['masque', ['private_key' => base64_encode(str_repeat('a',32))]], ['masque', ['public_key' => base64_encode(str_repeat('b',32))]], ['masque', ['ip' => '10.0.0.2/32']],
            ['openvpn', ['ca' => 'not-a-certificate']], ['openvpn', ['cert' => 'not-a-certificate']], ['openvpn', ['cipher' => 'invalid']], ['openvpn', ['tls_auth' => 'a', 'tls_crypt' => 'b']],
            ['tailscale', ['auth_key' => ['invalid']]], ['tailscale', ['accept_routes' => 'invalid']],
            ['zerotier', ['network' => 'invalid']], ['zerotier', ['ip_stack' => ['mode' => 'invalid']]],
            ['easytier', ['network_name' => '']], ['easytier', ['no_listener' => true, 'listeners' => ['tcp://0.0.0.0:11010']]], ['easytier', ['peers' => ['bad-uri']]],
        ];
        foreach ($cases as [$type, $attrs]) {
            $response = $this->postJson($this->path($type, 'save'), $this->node($type, $attrs));
            $this->assertSame(422, $response->status(), $type . ' ' . json_encode(array_keys($attrs)) . ' ' . $response->getContent());
            $this->assertSame(0, Registry::model($type)::count());
        }
    }

    public function testStatisticsAndPricingDistinguishTypesWithTheSameId(): void
    {
        $this->createInstallTables(['v2_stat_server']);
        foreach (['socks5', 'http'] as $type) {
            Registry::model($type)::create($this->node($type));
            foreach ([strtotime(date('Y-m-d')), strtotime('-1 day', strtotime(date('Y-m-d')))] as $at) {
                DB::table('v2_stat_server')->insert(['server_id' => 1, 'server_type' => $type, 'u' => 100, 'd' => 200, 'record_type' => 'd', 'record_at' => $at, 'created_at' => time(), 'updated_at' => time()]);
            }
        }
        $this->withHeaders($this->auth());
        foreach (['getServerTodayRank', 'getServerLastRank'] as $action) {
            $url = str_replace('/server/protocols', '/stat/' . $action, $this->path());
            $rows = $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')->json('data');
            foreach ($rows as $row) $this->assertSame('native ' . $row['server_type'], $row['server_name']);
        }
        $this->getJson(str_replace('/server/protocols', '/group-pricing/fetch', $this->path()))->assertOk()->assertJsonPath('data.0.nodes', 2);
    }

    public function testBrowserMetadataMatchesTheProtectedBackendCatalog(): void
    {
        $this->assertSame(\App\Protocols\Mihomo\AdminForm::catalog(Outbound::catalog()), json_decode(file_get_contents(base_path('tests/Fixtures/MihomoCatalog.json')), true));
        $this->withHeaders($this->auth())->getJson($this->path())->assertOk()
            ->assertJsonPath('data.protocols.http.form.defaults.managed', true)
            ->assertJsonPath('data.protocols.http.form.defaults.tls', true);
    }

    public function testReadingNewNodeDefaultsDoesNotConvertAnExistingExternalNode(): void
    {
        $this->withHeaders($this->auth());
        $this->postJson($this->path('http', 'save'), $this->node('http', ['managed' => false, 'tls' => false]))->assertOk();
        $model = Registry::model('http');
        $before = $model::first()->toArray();
        $this->getJson($this->path())->assertOk()
            ->assertJsonPath('data.protocols.http.form.defaults.managed', true)
            ->assertJsonPath('data.protocols.http.form.defaults.tls', true);
        $this->assertSame($before, $model::first()->toArray());
        $this->assertFalse($model::first()->managed);
        $this->assertFalse($model::first()->tls);
    }

    public function testChangingParentOptionsCannotInvalidateAnExistingRelay(): void
    {
        $model = Registry::model('mieru');
        $parent = $model::create($this->node('mieru'));
        $model::create($this->node('mieru', ['parent_id' => $parent->id, 'port' => 1234]));
        $this->withHeaders($this->auth());
        $this->postJson($this->path('mieru','save'), $this->node('mieru', ['id' => $parent->id, 'port' => null, 'port_range' => '2090-2099']))->assertStatus(422);
        $this->assertNull($parent->fresh()->port_range);
        $this->assertSame(443, $parent->fresh()->port);
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSubscriptionRouteSelectsMihomoAndChecksAccountAvailability(): void
    {
        config(['v2board.show_subscribe_method' => 0, 'v2board.show_info_to_server_enable' => 0]);
        $one = $this->makeActiveUser($this->makePlan()); $two = $this->makeActiveUser($this->makePlan());
        Registry::model('socks5')::create($this->node('socks5', ['password' => '{{uuid}}']));
        foreach ([$one, $two] as $user) foreach (['mihomo', 'meta'] as $flag) {
            $response = $this->get('/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => $flag]))->assertOk();
            $config = Yaml::parse($response->getContent()); $this->assertCount(1, $config['proxies']); $this->assertSame($user->uuid, $config['proxies'][0]['password']);
        }
        $url = '/api/v1/client/subscribe?' . http_build_query(['token' => $one->token, 'flag' => 'mihomo']);
        $one->update(['expired_at' => time() - 1]); $this->get($url)->assertForbidden();
        $one->update(['expired_at' => time() + 86400, 'banned' => 1]); $this->get($url)->assertForbidden();
    }
}
