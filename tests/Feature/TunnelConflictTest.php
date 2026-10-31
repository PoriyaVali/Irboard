<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * A relay port can forward to one foreign server. Rows of that same server,
 * listed twice for two user groups, share the forwarding and are no conflict;
 * only another server on the same relay port is.
 */
class TunnelConflictTest extends TestCase
{
    use PanelFixtures;

    private const RELAY = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpPanel();
        $this->createInstallTables(['v2_server_anytls', 'v2_tunnel_map']);
        // A relay that answers nothing keeps the probe from waiting on the network.
        Cache::put('tun_live_1', false, 60); Cache::put('tun_live_2', false, 60); Cache::put('tun_live_3', false, 60); Cache::put('tun_live_4', false, 60);
        foreach ([[1, 'de.example.com', 443], [2, 'DE.example.com.', 443], [3, 'nl.example.com', 443], [4, 'nl.example.com', 8443]] as [$id, $direct, $port]) {
            DB::table('v2_server_anytls')->insert(['id' => $id, 'name' => 'node ' . $id, 'group_id' => '["1"]', 'route_id' => '[]', 'host' => self::RELAY,
                'port' => (string) $port, 'server_port' => $port, 'server_name' => 'node.example.com', 'show' => 1, 'rate' => '1', 'created_at' => time(), 'updated_at' => time()]);
            DB::table('v2_tunnel_map')->insert(['server_id' => $id, 'direct_host' => $direct, 'tunnel_ip' => self::RELAY]);
        }
    }

    private function auth(): array
    {
        $user = $this->makeUser(['is_admin' => 1]); $token = 'tunnel-test-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function prefix(): string
    {
        return '/api/v1/' . config('v2board.secure_path', 'admin') . '/tunnel';
    }

    public function testOnlyAnotherForeignServerOnTheRelayPortConflicts(): void
    {
        $rows = collect($this->withHeaders($this->auth())->getJson($this->prefix() . '/status')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([3], $rows[1]['conflict'], 'node 1 shares port 443 with another server only');
        $this->assertSame([3], $rows[2]['conflict'], 'the same server listed twice is no conflict');
        $this->assertSame([1, 2], $rows[3]['conflict']);
        $this->assertSame([], $rows[4]['conflict'], 'another relay port');
    }

    public function testRegisteringTheSameServerTwiceIsAllowed(): void
    {
        // Leave only node 1's server on relay port 443.
        DB::table('v2_tunnel_map')->whereIn('server_id', [2, 3])->delete();
        $this->withHeaders($this->auth())->postJson($this->prefix() . '/register', ['id' => 2, 'direct_host' => 'de.example.com', 'tunnel_ip' => self::RELAY])->assertOk();
        DB::table('v2_tunnel_map')->where('server_id', 4)->delete();
        DB::table('v2_server_anytls')->where('id', 4)->update(['port' => '443']);
        $this->withHeaders($this->auth())->postJson($this->prefix() . '/register', ['id' => 4, 'direct_host' => 'se.example.com', 'tunnel_ip' => self::RELAY])->assertStatus(400);
    }
}
