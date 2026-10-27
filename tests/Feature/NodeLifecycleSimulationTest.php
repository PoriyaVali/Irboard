<?php

namespace Tests\Feature;

use App\Models\ServerShadowsocks;
use App\Models\User;
use App\Protocols\Mihomo\ProtocolRegistry as Registry;
use App\Services\AddonBillingService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\Fixtures\MihomoProfiles;
use Tests\TestCase;

/** A node simulator drives real panel endpoints, synchronous jobs and isolated Redis.
 * It checks the panel contract, not a VPN transport or V2bX traffic shaper.
 * Opt in only with a disposable Redis: IRBOARD_NODE_SIMULATION=1.
 */
class NodeLifecycleSimulationTest extends TestCase
{
    use PanelFixtures;

    private $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('IRBOARD_NODE_SIMULATION') !== '1') $this->markTestSkipped('Set IRBOARD_NODE_SIMULATION=1 with a disposable Redis to run node simulations.');
        $prefix = 'irboard-sim-' . bin2hex(random_bytes(10)) . ':';
        config(['database.redis.options.prefix' => $prefix, 'cache.default' => 'redis', 'cache.prefix' => $prefix]);
        foreach (['default', 'cache', 'session'] as $connection) Redis::purge($connection);
        Cache::purge('redis');
        $this->resetPricing();
        $this->setUpPanel();
        $this->createInstallTables(array_merge(['v2_stat_user','v2_stat_server'], array_map(function ($type) { return 'v2_server_' . $type; }, array_merge([
            'shadowsocks','vmess','vless','trojan','tuic','hysteria','anytls','v2node','mdns','trusttunnel','route',
        ], Registry::types()))));
        // InstallSchema omits indexes for SQLite. Restore the real constraints
        // used by the production upserts before exercising actual statistics jobs.
        Schema::table('v2_stat_user', function ($t) { $t->unique(['server_rate','user_id','record_at']); });
        Schema::table('v2_stat_server', function ($t) { $t->unique(['server_id','server_type','record_at']); });
        foreach ([1,2] as $id) DB::table('v2_server_group')->insert(['id'=>$id,'name'=>'simulation group ' . $id,'created_at'=>time(),'updated_at'=>time()]);
        config(['v2board.server_token'=>'simulation-node-token','v2board.device_limit_mode'=>0,'v2board.server_push_interval'=>60,'queue.default'=>'sync',
            'app.key'=>'base64:' . base64_encode(str_repeat('t',32))]);
    }

    protected function tearDown(): void
    {
        $this->resetPricing();
        parent::tearDown();
    }

    private function resetPricing(): void
    {
        $property = new \ReflectionProperty(AddonBillingService::class, 'paidGroups');
        $property->setAccessible(true); $property->setValue(null, null);
    }

    public static function protocols(): array
    {
        $data = [];
        foreach (array_merge(Registry::types(), ['shadowsocks']) as $type) $data[$type] = [$type];
        return $data;
    }

    private function node(string $type, array $attrs = [])
    {
        $model = $type === 'shadowsocks' ? ServerShadowsocks::class : Registry::model($type);
        $fields = $type === 'shadowsocks' ? ['host'=>'192.0.2.10','port'=>'443','server_port'=>443,'cipher'=>'aes-128-gcm']
            : Registry::storage($type, MihomoProfiles::settings($type));
        return $model::create(array_replace($fields, ['name'=>'simulated ' . $type,'group_id'=>['1'],'route_id'=>[],'rate'=>1.5,'show'=>1,'sort'=>0], $attrs));
    }

    private function endpoint(string $type, string $action, int $id = 1): string
    {
        return '/api/v1/server/UniProxy/' . $action . '?' . http_build_query(['node_type'=>$type,'node_id'=>$id,'token'=>'simulation-node-token']);
    }

    private function users(string $type, int $id = 1, array $headers = [])
    {
        return $this->getJson($this->endpoint($type,'user',$id), $headers)->assertOk();
    }

    private function push(string $type, array $data, string $batch = 'simulation-batch-0001', int $id = 1)
    {
        return $this->postJson($this->endpoint($type,'push',$id), $data, ['X-Report-Id'=>$batch]);
    }

    private function adminPath(string $action): string
    {
        foreach (app('router')->getRoutes() as $route) if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\UserController@' . $action) return '/' . $route->uri();
        $this->fail('Admin user route missing: ' . $action);
    }

    private function edit(User $user, array $attrs): void
    {
        if (!$this->adminToken) {
            $admin = $this->makeUser(['is_admin'=>1]); $this->adminToken = 'simulation-admin-' . bin2hex(random_bytes(8));
            Cache::put($this->adminToken, $admin->only(['id','email','is_admin','is_staff']), 3600);
        }
        $this->postJson($this->adminPath('update'), array_replace(['id'=>$user->id,'email'=>$user->email,'banned'=>0,'is_admin'=>0,'is_staff'=>0],$attrs),
            ['authorization'=>$this->adminToken])->assertOk();
    }

    /** @dataProvider protocols */
    public function testDirectionalWeightedTrafficAndDuplicateReports(string $type): void
    {
        $node = $this->node($type); $user = $this->makeActiveUser($this->makePlan());
        // Independent directions, GiB-sized counters and a fractional node rate.
        $reports = [[1073741824,0],[0,2147483648],[536870912,1073741824]];
        foreach ($reports as $i => $bytes) {
            $this->push($type,[$user->id=>$bytes],'simulation-batch-000' . $i)->assertOk();
            $this->push($type,[$user->id=>$bytes],'simulation-batch-000' . $i)->assertOk();
        }
        $this->assertSame('2415919104', (string)Redis::hget('v2board_upload_traffic',$user->id));
        $this->assertSame('4831838208', (string)Redis::hget('v2board_download_traffic',$user->id));
        $this->artisan('traffic:update')->assertExitCode(0); $user->refresh();
        $this->assertSame(2415919104,(int)$user->u); $this->assertSame(4831838208,(int)$user->d);
        $this->assertSame(0,(int)$user->balance);
        $stat = DB::table('v2_stat_server')->where('server_type',$type)->where('server_id',$node->id)->first();
        $this->assertSame(1610612736,(int)$stat->u); $this->assertSame(3221225472,(int)$stat->d);
        $userStat = DB::table('v2_stat_user')->where('user_id',$user->id)->first();
        $this->assertSame(1.5,(float)$userStat->server_rate); $this->assertSame(1610612736,(int)$userStat->u);
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(2415919104,(int)$user->fresh()->u);
    }

    /** @dataProvider protocols */
    public function testBanUnbanExpiryQuotaCutoffAndResetThroughAdminApi(string $type): void
    {
        $this->node($type,['rate'=>1]); $user = $this->makeActiveUser($this->makePlan(),['transfer_enable'=>1000]);
        $original = $this->users($type)->assertJsonCount(1,'users');
        $this->getJson($this->endpoint($type,'user'),['If-None-Match'=>$original->headers->get('ETag')])->assertStatus(304);
        $this->edit($user,['banned'=>1]); $this->users($type)->assertJsonCount(0,'users');
        $this->edit($user,['banned'=>0]); $this->users($type)->assertJsonPath('users.0.id',$user->id);
        $this->assertSame(1,(int)$user->fresh()->group_id,'A partial ban edit must preserve access group');
        $this->edit($user,['expired_at'=>time()-60]); $this->users($type)->assertJsonCount(0,'users');
        $this->edit($user,['expired_at'=>time()+86400]); $this->users($type)->assertJsonCount(1,'users');
        $this->push($type,[$user->id=>[400,600]])->assertOk(); $this->artisan('traffic:update')->assertExitCode(0);
        $this->users($type)->assertJsonCount(0,'users');
        $this->edit($user,['u'=>0,'d'=>0]); $this->users($type)->assertJsonCount(1,'users');
        $this->assertSame(0,(int)$user->fresh()->u); $this->assertSame(0,(int)$user->fresh()->d);
    }

    /** @dataProvider protocols */
    public function testSpeedAndDevicePoliciesChangeOnTheNextPoll(string $type): void
    {
        $this->node($type); $user = $this->makeActiveUser($this->makePlan(),['speed_limit'=>8,'device_limit'=>2]);
        $previous = null;
        foreach ([[8,2],[32,4],[0,0],[null,null],[1,1]] as [$speed,$devices]) {
            $this->edit($user,['speed_limit'=>$speed,'device_limit'=>$devices]);
            $response = $this->users($type); $policy = $response->json('users.0');
            $this->assertSame($user->uuid,$policy['uuid']);
            if ($speed === null) $this->assertArrayNotHasKey('speed_limit',$policy); else $this->assertSame($speed,$policy['speed_limit']);
            if ($devices === null) $this->assertArrayNotHasKey('device_limit',$policy); else $this->assertSame($devices,$policy['device_limit']);
            if ($previous) $this->assertNotSame($previous,$response->headers->get('ETag'));
            $binary = $this->getJson($this->endpoint($type,'user'),['X-Response-Format'=>'msgpack'])->assertOk();
            $decoded = (new \MessagePack\BufferUnpacker($binary->getContent()))->unpack();
            $this->assertSame($response->json(),$decoded);
            $this->getJson($this->endpoint($type,'user'),['X-Response-Format'=>'msgpack','If-None-Match'=>$binary->headers->get('ETag')])->assertStatus(304);
            $previous = $response->headers->get('ETag');
        }
    }

    /** @dataProvider protocols */
    public function testAccessGroupsGrantsAndRevocation(string $type): void
    {
        $this->node($type); $user = $this->makeActiveUser($this->makePlan(['group_id'=>2]));
        $this->users($type)->assertJsonCount(0,'users');
        DB::table('v2_user_group')->insert(['user_id'=>$user->id,'group_id'=>1,'is_paid'=>0,'created_at'=>time(),'updated_at'=>time()]);
        $this->users($type)->assertJsonPath('users.0.id',$user->id);
        DB::table('v2_user_group')->where('user_id',$user->id)->update(['expired_at'=>time()-1]);
        $this->users($type)->assertJsonCount(0,'users');
        DB::table('v2_user_group')->where('user_id',$user->id)->update(['expired_at'=>time()+86400]);
        $this->edit($user,['banned'=>1]); $this->users($type)->assertJsonCount(0,'users');
        $this->edit($user,['banned'=>0]); $this->users($type)->assertJsonCount(1,'users');
        DB::table('v2_user_group')->where('user_id',$user->id)->delete(); $this->users($type)->assertJsonCount(0,'users');
    }

    /** @dataProvider protocols */
    public function testAliveReportsDeviceCountsClearingAndStaleNodeRemoval(string $type): void
    {
        $this->node($type); $this->node($type); $user = $this->makeActiveUser($this->makePlan(),['device_limit'=>2]); $uid = $user->id;
        $empty = $this->getJson($this->endpoint($type,'alivelist'))->assertOk();
        $this->assertSame('{"alive":{}}',$empty->getContent());
        $this->postJson($this->endpoint($type,'alive'),[$uid=>['192.0.2.1_1','192.0.2.2_1']])->assertOk();
        $this->postJson($this->endpoint($type,'alive',2),[$uid=>['192.0.2.1_2']])->assertOk();
        $this->getJson($this->endpoint($type,'alivelist'))->assertOk()->assertJsonPath('alive.' . $uid,3);
        $this->users($type)->assertJsonPath('users.0.device_limit',2);
        config(['v2board.device_limit_mode'=>1]);
        $this->postJson($this->endpoint($type,'alive',2),[$uid=>['192.0.2.1_2']])->assertOk();
        $this->getJson($this->endpoint($type,'alivelist'))->assertJsonPath('alive.' . $uid,2);
        $this->postJson($this->endpoint($type,'alive'),[$uid=>[]])->assertOk();
        $this->getJson($this->endpoint($type,'alivelist'))->assertJsonPath('alive.' . $uid,1);
        $snapshot = Cache::get('ALIVE_IP_USER_' . $uid); $snapshot[$type . '2']['lastupdateAt'] = time()-101;
        Cache::put('ALIVE_IP_USER_' . $uid,$snapshot,120);
        $this->postJson($this->endpoint($type,'alive'),[$uid=>[]])->assertOk();
        $this->getJson($this->endpoint($type,'alivelist'))->assertJsonPath('alive.' . $uid,0);
    }

    /** @dataProvider protocols */
    public function testAuthenticationHeartbeatAndConfigurationBoundary(string $type): void
    {
        $this->node($type); $user = $this->makeActiveUser($this->makePlan());
        $bad = str_replace('simulation-node-token','wrong-token',$this->endpoint($type,'user'));
        $this->getJson($bad)->assertStatus(500);
        $this->assertNull(Cache::get(CacheKey::get('SERVER_' . strtoupper($type) . '_LAST_CHECK_AT',1)));
        $this->getJson($this->endpoint($type,'user',999))->assertStatus(500);
        $this->users($type);
        $this->assertGreaterThanOrEqual(time()-2,Cache::get(CacheKey::get('SERVER_' . strtoupper($type) . '_LAST_CHECK_AT',1)));
        $this->postJson($this->endpoint($type,'status'),['cpu'=>12.5,'mem'=>['total'=>1000,'used'=>400]],['User-Agent'=>'V2bX/v1.7.2'])->assertOk();
        $load = Cache::get(CacheKey::get('SERVER_' . strtoupper($type) . '_LOAD_STATUS',1));
        $this->assertSame(12.5,$load['cpu']); $this->assertSame('v1.7.2',$load['version']);
        $this->push($type,[$user->id=>[10,20]])->assertOk();
        $this->assertSame(1,(int)Cache::get(CacheKey::get('SERVER_' . strtoupper($type) . '_ONLINE_USER',1)));
        if ($type !== 'shadowsocks') $this->getJson($this->endpoint($type,'config'))->assertStatus(501);
        else $this->getJson($this->endpoint($type,'config'))->assertOk();
    }

    /** @dataProvider protocols */
    public function testFinalTrafficAfterBanIsCountedAndZeroRateDoesNotConsumeQuota(string $type): void
    {
        $this->node($type,['rate'=>1]); $this->node($type,['rate'=>0]); $user = $this->makeActiveUser($this->makePlan());
        $this->edit($user,['banned'=>1]); $this->users($type)->assertJsonCount(0,'users');
        $this->push($type,[$user->id=>[1024,2048]])->assertOk();
        $this->push($type,[$user->id=>[1048576,2097152]],'simulation-batch-0001',2)->assertOk();
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(1024,(int)$user->fresh()->u); $this->assertSame(2048,(int)$user->fresh()->d);
        $this->assertSame(1048576,(int)DB::table('v2_stat_server')->where('server_type',$type)->where('server_id',2)->value('u'));
    }

    /** @dataProvider protocols */
    public function testPaidTierBillsWalletOnceAndStopsWhenCreditRunsOut(string $type): void
    {
        $this->node($type,['rate'=>1]);
        DB::table('v2_server_group')->where('id',1)->update(['addon_enabled'=>1,'price_per_gb'=>1000]); $this->resetPricing();
        $user = $this->makeActiveUser($this->makePlan(['group_id'=>2]),['balance'=>1000]);
        DB::table('v2_user_group')->insert(['user_id'=>$user->id,'group_id'=>1,'is_paid'=>1,'expired_at'=>time()+86400,'created_at'=>time(),'updated_at'=>time()]);
        $this->users($type)->assertJsonCount(1,'users');
        $this->push($type,[$user->id=>[268435456,805306368]])->assertOk();
        $this->push($type,[$user->id=>[268435456,805306368]])->assertOk();
        $this->assertSame(0,(int)$user->fresh()->balance);
        $this->assertSame(0,(int)$user->fresh()->u); $this->assertSame(0,(int)$user->fresh()->d);
        $ledger = DB::table('v2_user_group_usage')->where('user_id',$user->id)->first();
        $this->assertSame(1000,(int)$ledger->amount); $this->assertSame(268435456,(int)$ledger->u); $this->assertSame(805306368,(int)$ledger->d);
        $this->users($type)->assertJsonCount(0,'users');
        $this->edit($user,['balance'=>1000]); $this->users($type)->assertJsonCount(1,'users');
        // A free grant to the same group is an entitlement even at zero balance.
        DB::table('v2_user_group')->where('user_id',$user->id)->update(['is_paid'=>0]); $this->edit($user,['balance'=>0]);
        $this->users($type)->assertJsonCount(1,'users');
        $this->push($type,[$user->id=>[1024,2048]],'simulation-free-0001')->assertOk();
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(1024,(int)$user->fresh()->u); $this->assertSame(0,(int)$user->fresh()->balance);
    }

    /** @dataProvider protocols */
    public function testInvalidTrafficCannotReduceUsageOrPoisonTheRetryId(string $type): void
    {
        $this->node($type); $user = $this->makeActiveUser($this->makePlan(),['u'=>100,'d'=>200]);
        foreach ([[-1,20],[10,-1],[10],[10,20,30],['invalid',20],[1.5,20],[false,20],[PHP_INT_MAX,1]] as $counts) {
            $this->push($type,[$user->id=>$counts])->assertStatus(422);
            $this->assertFalse((bool)Redis::exists('v2board_upload_traffic'));
            $this->assertSame(0,DB::table('v2_stat_server')->count());
        }
        $this->push($type,[$user->id=>[20,40]])->assertOk(); $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(130,(int)$user->fresh()->u); $this->assertSame(260,(int)$user->fresh()->d);
    }

    /** @dataProvider protocols */
    public function testQueuedReportsKeepTheirCapturedRateAndProcessOnce(string $type): void
    {
        $node = $this->node($type); $user = $this->makeActiveUser($this->makePlan());
        config(['queue.default'=>'redis']);
        $queue = Queue::connection('redis');
        $drain = function () use ($queue) {
            foreach (['traffic_fetch','stat'] as $name) while ($job = $queue->pop($name)) { $job->fire(); $job->delete(); }
        };
        $this->push($type,[$user->id=>[1000,2000]])->assertOk();
        $this->assertSame(1,$queue->size('traffic_fetch')); $this->assertSame(2,$queue->size('stat'));
        $this->assertSame(0,(int)$user->fresh()->u);
        $node->update(['rate'=>3]); $drain(); $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(1500,(int)$user->fresh()->u); $this->assertSame(3000,(int)$user->fresh()->d);
        $this->push($type,[$user->id=>[1000,2000]])->assertOk(); $this->assertSame(0,$queue->size('traffic_fetch'));
        $this->push($type,[$user->id=>[1000,2000]],'simulation-batch-0002')->assertOk();
        $drain(); $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(4500,(int)$user->fresh()->u); $this->assertSame(9000,(int)$user->fresh()->d);
        $this->assertSame(2,DB::table('v2_stat_user')->where('user_id',$user->id)->count());
    }

    /** @dataProvider protocols */
    public function testScheduledResetRestoresDueAccountsAndPreservesNoResetPlans(string $type): void
    {
        $this->node($type);
        $expiry = mktime(12,0,0,(int)date('n'),(int)date('j'),(int)date('Y')+4);
        $due = $this->makeActiveUser($this->makePlan(['reset_traffic_method'=>1]),['expired_at'=>$expiry,'transfer_enable'=>1000,'u'=>400,'d'=>600]);
        $never = $this->makeActiveUser($this->makePlan(['reset_traffic_method'=>2]),['expired_at'=>$expiry,'transfer_enable'=>1000,'u'=>400,'d'=>600]);
        $this->users($type)->assertJsonCount(0,'users');
        $this->artisan('reset:traffic')->assertExitCode(0);
        $this->users($type)->assertJsonCount(1,'users')->assertJsonPath('users.0.id',$due->id);
        $this->assertSame(0,(int)$due->fresh()->u); $this->assertSame(0,(int)$due->fresh()->d);
        $this->assertSame(400,(int)$never->fresh()->u); $this->assertSame(600,(int)$never->fresh()->d);
        $this->assertFalse((bool)Redis::exists('traffic_reset_lock'));
    }

    /** @dataProvider protocols */
    public function testEmptyMalformedAndUnidentifiedTrafficReports(string $type): void
    {
        $this->node($type); $user = $this->makeActiveUser($this->makePlan());
        $this->push($type,[])->assertOk();
        $this->assertSame(0,DB::table('v2_stat_server')->count());
        $this->assertSame(0,(int)Cache::get(CacheKey::get('SERVER_' . strtoupper($type) . '_ONLINE_USER',1)));
        foreach ([['bad-user'=>[10,20]],[0=>[10,20]],['999999999999999999999999'=>[10,20]]] as $data) $this->push($type,$data,'simulation-bad-0001')->assertStatus(422);
        $this->call('POST',$this->endpoint($type,'push'),[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],'{broken')->assertStatus(400);
        $this->call('POST',$this->endpoint($type,'push'),[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],'null')->assertStatus(422);
        // A legacy sender without report ids still reports independent batches.
        foreach ([1,2] as $n) $this->postJson($this->endpoint($type,'push'),[$user->id=>[10,20]])->assertOk();
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(30,(int)$user->fresh()->u); $this->assertSame(60,(int)$user->fresh()->d);
    }

    public function testFailedCounterWriteRestoresTheSnapshotAndMergesNewReports(): void
    {
        $user = $this->makeActiveUser($this->makePlan());
        Redis::hset('v2board_upload_traffic',(string)$user->id,4096);
        Redis::hset('v2board_download_traffic',(string)$user->id,8192);
        $failOnce = true;
        DB::listen(function ($query) use (&$failOnce,$user) {
            if ($failOnce && str_starts_with($query->sql,'UPDATE v2_user SET')) {
                $failOnce = false;
                Redis::hIncrBy('v2board_upload_traffic',(string)$user->id,64);
                Redis::hIncrBy('v2board_download_traffic',(string)$user->id,128);
                throw new \RuntimeException('Simulated database failure before commit');
            }
        });
        $this->artisan('traffic:update')->assertExitCode(1);
        $this->assertSame(0,(int)$user->fresh()->u); $this->assertSame(0,(int)$user->fresh()->d);
        $this->assertSame(4160,(int)Redis::hget('v2board_upload_traffic',$user->id));
        $this->assertSame(8320,(int)Redis::hget('v2board_download_traffic',$user->id));
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(4160,(int)$user->fresh()->u); $this->assertSame(8320,(int)$user->fresh()->d);
        $this->artisan('traffic:update')->assertExitCode(0); $this->assertSame(4160,(int)$user->fresh()->u);
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSubscriptionsDisappearOnBanAndReturnOnUnbanAcrossEveryAddedProtocol(): void
    {
        foreach (Registry::types() as $type) $this->node($type);
        $user = $this->makeActiveUser($this->makePlan());
        config(['v2board.show_subscribe_method'=>0,'v2board.show_info_to_server_enable'=>0]);
        $url = '/api/v1/client/subscribe?' . http_build_query(['token'=>$user->token,'flag'=>'mihomo']);
        $response = $this->get($url)->assertOk();
        $config = \Symfony\Component\Yaml\Yaml::parse($response->getContent()); $this->assertCount(15,$config['proxies']);
        $this->edit($user,['banned'=>1]); $this->get($url)->assertForbidden();
        $this->edit($user,['banned'=>0]); $this->get($url)->assertOk();
    }

    /** @dataProvider protocols */
    public function testMultipleUsersKeepIndependentCountersAndOnlineRecords(string $type): void
    {
        $this->node($type); $plan = $this->makePlan();
        $one = $this->makeActiveUser($plan,['device_limit'=>2]); $two = $this->makeActiveUser($plan,['device_limit'=>2]);
        $this->users($type)->assertJsonCount(2,'users');
        $this->push($type,[$one->id=>[100,300],$two->id=>[50,150]])->assertOk();
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(150,(int)$one->fresh()->u); $this->assertSame(450,(int)$one->fresh()->d);
        $this->assertSame(75,(int)$two->fresh()->u); $this->assertSame(225,(int)$two->fresh()->d);
        $this->assertSame(2,(int)Cache::get(CacheKey::get('SERVER_' . strtoupper($type) . '_ONLINE_USER',1)));
        $this->postJson($this->endpoint($type,'alive'),[$one->id=>['192.0.2.1_1','192.0.2.2_1'],$two->id=>['192.0.2.3_1']])->assertOk();
        $this->getJson($this->endpoint($type,'alivelist'))->assertJsonPath('alive.' . $one->id,2)->assertJsonPath('alive.' . $two->id,1);
        $this->edit($one,['banned'=>1]); $this->users($type)->assertJsonCount(1,'users')->assertJsonPath('users.0.id',$two->id);
    }

    public function testResetLockDefersCounterDrainWithoutDroppingTheReport(): void
    {
        $user = $this->makeActiveUser($this->makePlan());
        Redis::hset('v2board_upload_traffic',(string)$user->id,4096);
        Redis::hset('v2board_download_traffic',(string)$user->id,8192);
        Redis::setex('traffic_reset_lock',300,1);
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(0,(int)$user->fresh()->u);
        $this->assertSame(4096,(int)Redis::hget('v2board_upload_traffic',$user->id));
        Redis::del('traffic_reset_lock'); $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(4096,(int)$user->fresh()->u); $this->assertSame(8192,(int)$user->fresh()->d);
    }

    public function testSameNumericIdsAndBatchIdsRemainSeparateAcrossAllFifteenProtocols(): void
    {
        $user = $this->makeActiveUser($this->makePlan());
        foreach (Registry::types() as $index=>$type) {
            $this->node($type,['rate'=>1]);
            $this->push($type,[$user->id=>[100,200]],'shared-report-0001')->assertOk();
            $this->postJson($this->endpoint($type,'alive'),[$user->id=>['192.0.2.' . ($index+1) . '_1']])->assertOk();
        }
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(1500,(int)$user->fresh()->u); $this->assertSame(3000,(int)$user->fresh()->d);
        $this->assertSame(15,DB::table('v2_stat_server')->count());
        $this->assertSame(15,Cache::get('ALIVE_IP_USER_' . $user->id)['alive_ip']);
    }

    public function testUploadOnlyRedisSnapshotIsAppliedWithoutACompanionDownloadKey(): void
    {
        $user = $this->makeActiveUser($this->makePlan());
        Redis::hset('v2board_upload_traffic',(string)$user->id,4096);
        $this->artisan('traffic:update')->assertExitCode(0);
        $this->assertSame(4096,(int)$user->fresh()->u); $this->assertSame(0,(int)$user->fresh()->d);
    }
}
