<?php

namespace Tests\Feature;

use App\Models\ServerMihomo;
use App\Protocols\Mihomo\Outbound;
use App\Services\ServerService;
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
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(array_map(function ($type) { return 'v2_server_' . $type; }, [
            'shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'v2node', 'mdns', 'trusttunnel', 'mihomo',
        ]));
        DB::table('v2_server_group')->insert([
            ['id' => 1, 'name' => 'group one', 'created_at' => time(), 'updated_at' => time()],
            ['id' => 2, 'name' => 'group two', 'created_at' => time(), 'updated_at' => time()],
        ]);
        config(['app.key' => 'base64:' . base64_encode(str_repeat('t', 32))]);
    }

    private function path(string $action): string
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\Server\\MihomoController@' . $action) return '/' . $route->uri();
        }
        $this->fail('Protected Mihomo route is missing');
    }

    private function auth(array $attrs = ['is_admin' => 1]): array
    {
        $user = $this->makeUser($attrs);
        $token = 'mihomo-test-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function node(string $protocol = 'socks5', array $attrs = []): array
    {
        return array_replace([
            'name' => 'Mihomo ' . $protocol, 'protocol' => $protocol, 'group_id' => ['1'], 'tags' => ['external'],
            'sort' => 0, 'show' => true, 'outbound_settings' => MihomoProfiles::settings($protocol),
        ], $attrs);
    }

    public function testEveryEndpointRequiresAnAdmin(): void
    {
        foreach (['fetch', 'save', 'update', 'copy', 'drop'] as $action) {
            $method = $action === 'fetch' ? 'getJson' : 'postJson';
            $this->flushHeaders()->$method($this->path($action))->assertForbidden();
            foreach ([[], ['is_staff' => 1]] as $attrs) {
                $this->withHeaders($this->auth($attrs))->$method($this->path($action))->assertForbidden();
            }
        }
        $this->assertSame(0, ServerMihomo::count());
    }

    public function testAllFifteenProtocolsCanBeSavedFetchedAndEdited(): void
    {
        $this->withHeaders($this->auth());
        foreach (array_keys(Outbound::catalog()) as $protocol) {
            $this->postJson($this->path('save'), $this->node($protocol))->assertOk()->assertExactJson(['data' => true]);
            $saved = ServerMihomo::where('protocol', $protocol)->firstOrFail();
            $this->assertSame(MihomoProfiles::settings($protocol), $saved->outbound_settings);
            $this->assertSame(['1'], $saved->group_id);
            $this->postJson($this->path('save'), $this->node($protocol, ['id' => $saved->id, 'sort' => 15]))->assertOk();
            $this->assertSame(15, $saved->fresh()->sort);
        }
        $this->getJson($this->path('fetch'))->assertOk()->assertJsonCount(15, 'data.nodes')->assertJsonCount(15, 'data.protocols')
            ->assertJsonPath('data.version', '1.19.33');
    }

    public function testInvalidProtocolSettingsAndGroupGrantsAreRejectedBeforeWriting(): void
    {
        $this->withHeaders($this->auth());
        $invalid = [
            $this->node('ssh', ['outbound_settings' => ['server' => '192.0.2.1', 'port' => 22, 'username' => 'fixture']]),
            $this->node('socks5', ['protocol' => 'invented']),
            $this->node('socks5', ['group_id' => []]),
            $this->node('socks5', ['group_id' => [999]]),
            $this->node('socks5', ['outbound_settings' => ['server' => '192.0.2.1', 'port' => 0]]),
            $this->node('socks5', ['outbound_settings' => ['server' => '192.0.2.1', 'port' => 65536]]),
            $this->node('socks5', ['outbound_settings' => ['server' => '192.0.2.1', 'port' => 443, 'type' => 'http']]),
            $this->node('sudoku', ['outbound_settings' => array_replace(MihomoProfiles::settings('sudoku'), ['padding-max' => 0])]),
            $this->node('zerotier', ['outbound_settings' => ['network' => 'invalid']]),
            $this->node('mieru', ['outbound_settings' => array_replace(MihomoProfiles::settings('mieru'), ['port-range' => '8000-7000'])]),
        ];
        foreach ($invalid as $node) $this->postJson($this->path('save'), $node)->assertStatus(422);
        $this->assertSame(0, ServerMihomo::count());
    }

    public function testCopyIsHiddenAndVisibilityDeleteAndOrderingWork(): void
    {
        $this->withHeaders($this->auth());
        $this->postJson($this->path('save'), $this->node('socks5', ['sort' => 9]))->assertOk();
        $this->postJson($this->path('save'), $this->node('http', ['sort' => 1]))->assertOk();
        $first = ServerMihomo::firstOrFail();
        $this->postJson($this->path('copy'), ['id' => $first->id])->assertOk();
        $copy = ServerMihomo::orderByDesc('id')->firstOrFail();
        $this->assertSame(0, $copy->show);
        $this->assertSame($first->outbound_settings, $copy->outbound_settings);
        $this->postJson($this->path('update'), ['id' => $first->id, 'show' => false])->assertOk();
        $this->assertSame(0, $first->fresh()->show);
        $this->getJson($this->path('fetch'))->assertJsonPath('data.nodes.0.protocol', 'http');
        $this->postJson($this->path('drop'), ['id' => $copy->id])->assertOk();
        $this->postJson($this->path('drop'), ['id' => $copy->id])->assertNotFound();
        $this->assertSame(2, ServerMihomo::count());
    }

    public function testGroupVisibilityTemplatesAndDistinctProtocolsAtSameAddress(): void
    {
        $one = $this->makeActiveUser($this->makePlan());
        $two = $this->makeActiveUser($this->makePlan());
        foreach (['socks5', 'http', 'gost-relay'] as $protocol) {
            $settings = MihomoProfiles::settings($protocol);
            $settings['username'] = '{{user_id}}'; $settings['password'] = '{{uuid}}';
            $settings['headers'] = ['X-Test' => ['subscriber-{{user_id}}', 'literal']];
            ServerMihomo::create($this->node($protocol, ['outbound_settings' => $settings]));
        }
        ServerMihomo::create($this->node('ssh', ['show' => false]));
        ServerMihomo::create($this->node('snell', ['group_id' => ['2']]));
        $service = new ServerService();
        $available = $service->getAvailableServers($one);
        $this->assertCount(3, $available);
        $protocols = array_column($available, 'protocol');
        sort($protocols);
        $this->assertSame(['gost-relay', 'http', 'socks5'], $protocols);
        foreach ($available as $server) {
            $this->assertArrayNotHasKey('outbound_settings', $server);
            $this->assertSame($one->uuid, $server['mihomo']['password']);
            $this->assertSame((string)$one->id, $server['mihomo']['username']);
            $this->assertSame(['subscriber-' . $one->id, 'literal'], $server['mihomo']['headers']['X-Test']);
            $this->assertNull($server['is_online']);
            $this->assertFalse($server['managed']);
        }
        $other = $service->getAvailableServers($two);
        $this->assertSame($two->uuid, $other[0]['mihomo']['password']);
        $this->assertNotSame($available[0]['cache_key'], $other[0]['cache_key']);
        DB::table('v2_user_group')->insert(['user_id' => $one->id, 'group_id' => 2, 'is_paid' => 0, 'created_at' => time(), 'updated_at' => time()]);
        $this->assertCount(4, (new ServerService())->getAvailableServers($one));
        DB::table('v2_user_group')->where('user_id', $one->id)->update(['expired_at' => time() - 1]);
        $this->assertCount(3, (new ServerService())->getAvailableServers($one));
    }

    public function testGroupCountsIncludeExternalProfilesAndDeletingUsedGroupIsBlocked(): void
    {
        ServerMihomo::create($this->node());
        $service = new ServerService();
        $this->assertCount(1, $service->getAllServers());
        $this->assertSame([], $service->getAllServers(false));
        $this->withHeaders($this->auth());
        $groupPath = preg_replace('~/mihomo/fetch$~', '/group/fetch', $this->path('fetch'));
        $this->getJson($groupPath)->assertOk()->assertJsonPath('data.0.server_count', 1);
        $this->postJson(preg_replace('~/fetch$~', '/drop', $groupPath), ['id' => 1])->assertStatus(500);
        $this->assertNotNull(DB::table('v2_server_group')->find(1));
    }

    public function testMissingUpgradeDoesNotBreakNativeSubscriptions(): void
    {
        Schema::drop('v2_server_mihomo');
        $user = $this->makeActiveUser($this->makePlan());
        $this->assertSame([], (new ServerService())->getAvailableServers($user));
        $this->withHeaders($this->auth())->getJson($this->path('fetch'))->assertStatus(503);
    }

    public function testOfflineMonitorIgnoresProfilesWithoutManagedHeartbeat(): void
    {
        ServerMihomo::create($this->node());
        $this->artisan('check:server')->assertExitCode(0);
    }

    public function testJsonCredentialsKeepEmptyStringsAndSignificantWhitespace(): void
    {
        $this->withHeaders($this->auth());
        $settings = ['server' => '192.0.2.10', 'port' => 1080, 'username' => '', 'password' => "  test\npassword  ", 'headers' => ['empty' => '', 'spaced' => ' value ']];
        $this->postJson($this->path('save'), $this->node('socks5', ['outbound_settings' => $settings]))->assertOk();
        $this->assertSame($settings, ServerMihomo::firstOrFail()->outbound_settings);
        $this->postJson($this->path('save'), $this->node('tailscale', ['outbound_settings' => new \stdClass()]))->assertOk();
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSubscriptionRouteSelectsMihomoAndChecksAccountAvailability(): void
    {
        config(['v2board.show_subscribe_method' => 0, 'v2board.show_info_to_server_enable' => 0]);
        $one = $this->makeActiveUser($this->makePlan());
        $two = $this->makeActiveUser($this->makePlan());
        ServerMihomo::create($this->node('socks5', ['outbound_settings' => ['server' => '192.0.2.10', 'port' => 1080, 'password' => '{{uuid}}']]));
        foreach ([$one, $two] as $user) {
            foreach (['mihomo', 'meta'] as $flag) {
                $response = $this->get('/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => $flag]))->assertOk();
                $config = Yaml::parse($response->getContent());
                $this->assertCount(1, $config['proxies']);
                $this->assertSame($user->uuid, $config['proxies'][0]['password']);
            }
        }
        $url = '/api/v1/client/subscribe?' . http_build_query(['token' => $one->token, 'flag' => 'mihomo']);
        $one->update(['expired_at' => time() - 1]); $this->get($url)->assertForbidden();
        $one->update(['expired_at' => time() + 86400, 'banned' => 1]); $this->get($url)->assertForbidden();
    }
}
