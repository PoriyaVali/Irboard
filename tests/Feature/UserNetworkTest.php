<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Services\UserNetworkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * The admin sees which network each user is on now: the app reports it in
 * X-Network, the panel keeps one row per account and device, and the devices
 * page, its operators view and the user's details show it.
 */
class UserNetworkTest extends TestCase
{
    use PanelFixtures;

    private const ANDROID = 'androidid:' . 'a1b2c3d4e5f60718' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const WIDEVINE = 'widevine:' . 'ffffeeeeddddcccc' . 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_user_network']);
        Cache::flush();
    }

    private function authFor(User $user): string
    {
        return (new AuthService($user))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
    }

    private function report(User $user, ?string $network, string $deviceIds = self::ANDROID . ',' . self::WIDEVINE)
    {
        $headers = ['authorization' => $this->authFor($user), 'x-device-ids' => $deviceIds];
        if ($network !== null) $headers['x-network'] = $network;
        return $this->withHeaders($headers)->getJson('/api/v1/user/checkLogin');
    }

    private function admin(): array
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $token = 'net-admin-' . bin2hex(random_bytes(16));
        Cache::put($token, $admin->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function adminPath(string $action): string
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\' . $action) return '/' . $route->uri();
        }
        $this->fail("Missing admin route {$action}");
    }

    public function testTheHeaderIsParsedStrictly()
    {
        $this->assertSame(
            ['transport' => 'cellular', 'operator' => 'irancell', 'mccmnc' => '43235', 'asn' => null, 'name' => null],
            UserNetworkService::parseHeader('t=cellular;op=irancell;mnc=43235')
        );
        $this->assertSame(
            ['transport' => 'wifi', 'operator' => 'tci', 'mccmnc' => null, 'asn' => 58224, 'name' => 'Iran Telecommunication Company PJS'],
            UserNetworkService::parseHeader('t=wifi;op=tci;as=58224;nm=Iran Telecommunication Company PJS;mnc=43211')
        );
        $this->assertSame('as12345', UserNetworkService::parseHeader('t=wifi;op=AS12345;as=12345')['operator']);
        // A bad field goes, the rest stays.
        $this->assertSame(
            ['transport' => 'wifi', 'operator' => null, 'mccmnc' => null, 'asn' => null, 'name' => null],
            UserNetworkService::parseHeader('t=wifi;op=<script>;as=-4;nm=' . str_repeat('x', 65))
        );
        $this->assertNull(UserNetworkService::parseHeader('op=irancell'));
        $this->assertNull(UserNetworkService::parseHeader('t=satellite;op=irancell'));
        $this->assertNull(UserNetworkService::parseHeader('t=wifi;nm=' . str_repeat('a', 300)));
        $this->assertNull(UserNetworkService::parseHeader(''));
    }

    public function testADeviceIsKeyedByItsMostStableHashLikeTheApp()
    {
        $this->assertSame('a1b2c3d4e5f60718', UserNetworkService::deviceKey(self::WIDEVINE . ',' . self::ANDROID));
        $this->assertSame('ffffeeeeddddcccc', UserNetworkService::deviceKey(self::WIDEVINE));
        $this->assertSame('', UserNetworkService::deviceKey(''));
        $this->assertSame('', UserNetworkService::deviceKey('nonsense'));
    }

    public function testAReportIsRecordedAndFollowsTheDeviceAcrossNetworks()
    {
        $user = $this->makeUser();
        $this->report($user, 't=cellular;op=irancell;mnc=43235')->assertStatus(200);
        $row = DB::table('v2_user_network')->where('user_id', $user->id)->first();
        $this->assertSame('a1b2c3d4e5f60718', $row->device);
        $this->assertSame('irancell', $row->operator);
        $this->assertSame('43235', $row->mccmnc);

        // Wi-Fi, then straight back to mobile data: each change is written at
        // once, and the device ends where it really is.
        $this->report($user, 't=wifi;op=tci;as=58224')->assertStatus(200);
        $this->assertSame('tci', DB::table('v2_user_network')->where('user_id', $user->id)->value('operator'));
        $this->report($user, 't=cellular;op=irancell;mnc=43235')->assertStatus(200);
        $rows = DB::table('v2_user_network')->where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('irancell', $rows[0]->operator);
        $this->assertNull($rows[0]->asn);
    }

    public function testTheSameNetworkIsWrittenOnlyOnceAWhileAndKeepsWhenItBegan()
    {
        $user = $this->makeUser();
        $this->report($user, 't=wifi;op=shatel;as=31549');
        DB::table('v2_user_network')->update(['changed_at' => 100, 'seen_at' => 100]);
        $this->report($user, 't=wifi;op=shatel;as=31549');
        $this->assertSame(100, (int)DB::table('v2_user_network')->value('seen_at'), 'gated');

        Cache::flush();
        $this->report($user, 't=wifi;op=shatel;as=31549');
        $row = DB::table('v2_user_network')->first();
        $this->assertGreaterThan(100, (int)$row->seen_at);
        $this->assertSame(100, (int)$row->changed_at, 'still on the network it moved to at 100');
    }

    public function testNoOrABrokenHeaderRecordsNothingAndFailsNothing()
    {
        $user = $this->makeUser();
        $this->report($user, null)->assertStatus(200);
        $this->report($user, 'garbage')->assertStatus(200);
        $this->report($user, 't=wifi;op=tci', '')->assertStatus(200);
        $this->assertSame([''], DB::table('v2_user_network')->pluck('device')->all(), 'no device ids: one row under no device');

        // A database without the table yet must not break the user's request.
        DB::statement('DROP TABLE v2_user_network');
        Cache::flush();
        $this->report($user, 't=cellular;op=mci;mnc=43211')->assertStatus(200);
    }

    public function testAnAccountKeepsABoundedNumberOfDevices()
    {
        $user = $this->makeUser();
        for ($i = 0; $i < UserNetworkService::MAX_DEVICES + 3; $i++) {
            UserNetworkService::store($user->id, sprintf('%016x', $i), UserNetworkService::parseHeader('t=wifi;op=tci'), 1000 + $i);
        }
        $kept = DB::table('v2_user_network')->where('user_id', $user->id)->orderBy('seen_at')->pluck('device')->all();
        $this->assertCount(UserNetworkService::MAX_DEVICES, $kept);
        $this->assertSame(sprintf('%016x', 3), $kept[0], 'the devices heard from longest ago went');
    }

    public function testTheAdminSeesWhoIsOnWhichOperator()
    {
        $a = $this->makeUser(['email' => 'a@example.com', 'device_count' => 1]);
        $b = $this->makeUser(['email' => 'b@example.com', 'device_count' => 1]);
        $c = $this->makeUser(['email' => 'c@example.com']);
        $this->report($a, 't=cellular;op=irancell;mnc=43235');
        $this->report($b, 't=cellular;op=irancell;mnc=43235', self::WIDEVINE);
        $this->report($c, 't=wifi;op=as99999;as=99999;nm=Small ISP');
        // An old report falls outside the window.
        DB::table('v2_user_network')->insert(['user_id' => $c->id, 'device' => 'old', 'transport' => 'wifi',
            'operator' => 'mci', 'changed_at' => 1, 'seen_at' => time() - 3 * 86400]);
        $admin = $this->admin();

        $res = $this->withHeaders($admin)->getJson($this->adminPath('DeviceIdentityAdminController@networks'))->assertStatus(200);
        $summary = collect($res->json('summary'))->keyBy('operator');
        $this->assertSame(2, $summary['irancell']['users']);
        $this->assertSame('cellular', $summary['irancell']['transport']);
        $this->assertSame('Small ISP', $summary['as99999']['name']);
        $this->assertArrayNotHasKey('mci', $summary->all());
        $this->assertSame(3, $res->json('total'));

        $only = $this->withHeaders($admin)
            ->getJson($this->adminPath('DeviceIdentityAdminController@networks') . '?operator=irancell&search=b@')
            ->assertStatus(200);
        $this->assertSame(['b@example.com'], array_column($only->json('data'), 'email'));

        $week = $this->withHeaders($admin)->getJson($this->adminPath('DeviceIdentityAdminController@networks') . '?hours=168');
        $this->assertSame(4, $week->json('total'));

        // The devices list and the user's details carry each device's network.
        $list = $this->withHeaders($admin)->getJson($this->adminPath('DeviceIdentityAdminController@fetch'))->assertStatus(200);
        $first = collect($list->json('data'))->firstWhere('email', 'a@example.com');
        $this->assertSame('irancell', $first['networks'][0]['operator']);

        $detail = $this->withHeaders($admin)
            ->getJson($this->adminPath('UserController@getUserInfoById') . '?id=' . $c->id)->assertStatus(200);
        $this->assertSame(['as99999', 'mci'], array_column($detail->json('data.networks'), 'operator'));
    }

    public function testOnlyAdminsReadTheOperators()
    {
        $user = $this->makeUser();
        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->getJson($this->adminPath('DeviceIdentityAdminController@networks'))
            ->assertStatus(403);
    }
}
