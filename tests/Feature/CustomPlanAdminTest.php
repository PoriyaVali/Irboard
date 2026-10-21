<?php

namespace Tests\Feature;

use App\Models\BotSetting;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Services\CustomPlanService;
use App\Services\CustomPlanSettingsService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

class CustomPlanAdminTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_plan_prices']);
        DB::statement('CREATE UNIQUE INDEX custom_key ON v2_plan (custom_key)');
        DB::statement('CREATE UNIQUE INDEX custom_trade_no ON v2_order (trade_no)');
        // InstallSchema intentionally drops MySQL key clauses; restore the
        // production settings primary-key guarantee for this financial fixture.
        DB::statement('CREATE UNIQUE INDEX settings_key ON v2_bot_settings ("key")');
        config(['app.key' => 'base64:' . base64_encode(str_repeat('t', 32))]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    private function auth($user): array
    {
        $token = 'custom-admin-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function path(string $action): string
    {
        // Routes are registered before this fixture sets its encryption key.
        // Use the actual registered secure prefix, including configured panels.
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\CustomPlanController@' . $action) return '/' . $route->uri();
        }
        $this->fail('Missing protected custom-plan admin route');
    }

    private function tariff(array $attrs = [], array $usd = []): Plan
    {
        $plan = $this->makePlan(array_merge(['transfer_enable' => 35, 'reset_traffic_method' => 2, 'month_price' => 89000], $attrs));
        if ($usd) DB::table('v2_plan_prices')->insert(['plan_id' => $plan->id] + $usd);
        return $plan;
    }

    private function configuration(array $references, array $types = [], bool $enabled = true): array
    {
        $classes = [];
        foreach ($references as $reference) $classes[$reference['plan_id']] = ['plan_id' => $reference['plan_id'], 'quota_type' => $types[$reference['plan_id']] ?? 'finite_total'];
        return ['enabled' => $enabled, 'classifications' => array_values($classes), 'references' => $references];
    }

    private function ref(Plan $plan, string $period = 'month_price'): array
    {
        return ['plan_id' => (int)$plan->id, 'period' => $period];
    }

    private function body(array $configuration, ?array $view = null): array
    {
        $view = $view ?? CustomPlanSettingsService::fetch();
        return ['configuration' => $configuration, 'settings_revision' => $view['settings_revision'], 'source_revision' => $view['source_revision']];
    }

    private function saveConfiguration(array $configuration): array
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        return $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $this->body($configuration))->assertOk()->json('data');
    }

    private function quote($user, ?string $code = null): array
    {
        return $this->withHeaders($this->auth($user))->postJson('/api/v1/user/plan/custom/quote', [
            'volume_gb' => 73, 'duration_days' => 17, 'pricing_revision' => CustomPlanService::catalogue()['pricing_revision'],
            'coupon_code' => $code])->assertOk()->json('data');
    }

    public function testNoReferencesAreApprovedByDefaultAndReadsCreateNothing()
    {
        $this->tariff([], ['month_price_usd' => 1.49]);
        $admin = $this->makeUser(['is_admin' => 1]);
        $view = $this->withHeaders($this->auth($admin))->getJson($this->path('fetch'))->assertOk()->json('data');
        $this->assertFalse($view['configuration']['enabled']);
        $this->assertSame([], $view['configuration']['references']);
        $this->assertSame('unknown', $view['candidates'][0]['quota_type']);
        $this->assertContains('not_finite_total', $view['candidates'][0]['periods'][0]['reasons']);
        $this->assertSame(['enabled' => false], CustomPlanService::catalogue());
        $this->assertSame(0, BotSetting::count());
        $this->assertSame(1, Plan::count());
        $this->assertSame(0, Order::count());
    }

    public function testAdminEndpointsRejectAnonymousStaffAndOrdinaryUsers()
    {
        foreach (['fetch' => 'getJson', 'preview' => 'postJson', 'save' => 'postJson'] as $action => $method) {
            $this->$method($this->path($action))->assertStatus(403);
            foreach ([['is_staff' => 1], []] as $attrs) {
                $this->withHeaders($this->auth($this->makeUser($attrs)))->$method($this->path($action))->assertStatus(403);
            }
        }
        $this->assertSame(0, BotSetting::count());
    }

    public function testPreviewUsesOnlySelectedPeriodAndLeavesSettingsPlansOrdersMoneyUnchanged()
    {
        $plan = $this->tariff(['quarter_price' => 150000], ['month_price_usd' => 99, 'quarter_price_usd' => 1.70]);
        $this->tariff([], ['month_price_usd' => 999]);
        $admin = $this->makeUser(['is_admin' => 1, 'balance' => 500000]);
        $configuration = $this->configuration([$this->ref($plan, 'quarter_price')], [], false);
        $view = $this->withHeaders($this->auth($admin))->postJson($this->path('preview'), $this->body($configuration))->assertOk()->json('data');
        $this->assertTrue($view['validation']['valid']);
        $this->assertFalse($view['configuration']['enabled']);
        $this->assertTrue($view['preview']['enabled'], 'Disabled drafts can still preview a curve');
        $this->assertCount(1, $view['preview']['source_points']);
        $this->assertSame('quarter_price', $view['preview']['source_points'][0]['period']);
        $this->assertEqualsWithDelta(1.70 / CustomPlanService::factor(35, 90), $view['preview']['model']['base_usd'], 0.0000001);
        $this->assertSame(0, BotSetting::count());
        $this->assertSame(2, Plan::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(500000, (int)$admin->fresh()->balance);
        $this->assertFalse(CustomPlanService::catalogue()['enabled']);
    }

    public function testExplicitPlanPeriodApprovalKeepsShapeAndUnselectedPricesHaveNoEffect()
    {
        $plan = $this->tariff(['quarter_price' => 150000], ['month_price_usd' => 1.49, 'quarter_price_usd' => 99]);
        $other = $this->tariff([], ['month_price_usd' => 999]);
        $saved = $this->saveConfiguration($this->configuration([$this->ref($plan)]));
        $data = CustomPlanService::catalogue();
        $this->assertTrue($data['enabled']);
        $this->assertCount(1, $data['source_points']);
        $this->assertEquals(1.49, $data['model']['base_usd']);
        foreach (CustomPlanSettingsService::MODEL as $key => $value) $this->assertEquals($value, $data['model'][$key]);
        DB::table('v2_plan_prices')->where('plan_id', $other->id)->update(['month_price_usd' => 1999]);
        DB::table('v2_plan_prices')->where('plan_id', $plan->id)->update(['quarter_price_usd' => 999]);
        $this->assertSame($data, CustomPlanService::catalogue());
        $this->assertSame(1, BotSetting::count());
        $this->assertSame($saved['settings_revision'], CustomPlanSettingsService::fetch()['settings_revision']);
    }

    public function testUnknownAndUnlimitedFupRemainIneligibleDespiteFiniteNumericQuota()
    {
        $plan = $this->tariff(['transfer_enable' => 500], ['month_price_usd' => 1.49]);
        $admin = $this->makeUser(['is_admin' => 1]);
        foreach (['unknown', 'unlimited_fup'] as $type) {
            $configuration = $this->configuration([$this->ref($plan)], [$plan->id => $type]);
            $this->withHeaders($this->auth($admin))->postJson($this->path('preview'), $this->body($configuration))
                ->assertOk()->assertJsonPath('data.validation.valid', false)->assertJsonPath('data.validation.errors.0.code', 'not_finite_total');
            $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $this->body($configuration))->assertStatus(422);
        }
        $this->assertSame(0, BotSetting::count());
        $this->assertFalse(CustomPlanService::catalogue()['enabled']);
    }

    public function testPeriodicResetAndInheritedResetAreRejectedButInheritedNoResetIsAllowed()
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        foreach ([0, 1, 3, 4, null] as $reset) {
            config(['v2board.reset_traffic_method' => 0]);
            $plan = $this->tariff(['reset_traffic_method' => $reset]);
            $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $this->body($this->configuration([$this->ref($plan)])))
                ->assertStatus(422)->assertJsonPath('errors.0.code', 'resetting_quota');
        }
        config(['v2board.reset_traffic_method' => 2]);
        $plan = $this->tariff(['reset_traffic_method' => null]);
        $this->saveConfiguration($this->configuration([$this->ref($plan)]));
        $this->assertSame(2, CustomPlanService::catalogue()['cohort']['reset_traffic_method']);
    }

    public function testMixedAccessDeviceOrSpeedCohortsAreRejectedAtomically()
    {
        $first = $this->tariff();
        $admin = $this->makeUser(['is_admin' => 1]);
        foreach ([['group_id' => 2], ['device_limit' => 3], ['speed_limit' => 40]] as $attrs) {
            $other = $this->tariff($attrs);
            $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $this->body($this->configuration([$this->ref($first), $this->ref($other)])))
                ->assertStatus(422)->assertJsonPath('errors.0.code', 'mixed_cohort');
        }
        $this->assertSame(0, BotSetting::count());
        $this->assertFalse(CustomPlanService::catalogue()['enabled']);
    }

    public function testMissingPeriodPlanZeroVolumeCapacityAndHiddenSourcesAreRejected()
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        foreach ([['quarter_price' => null], ['transfer_enable' => 0], ['transfer_enable' => 1025], ['show' => 0], ['capacity_limit' => 10], ['group_id' => 0]] as $attrs) {
            $plan = $this->tariff($attrs);
            $period = array_key_exists('quarter_price', $attrs) ? 'quarter_price' : 'month_price';
            $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $this->body($this->configuration([$this->ref($plan, $period)])))->assertStatus(422);
        }
        $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $this->body($this->configuration([['plan_id' => 99999, 'period' => 'month_price']])))
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'plan_missing');
        $this->assertSame(0, BotSetting::count());
    }

    public function testEmptyEnabledAndMalformedOrDuplicateOrOversizedConfigurationAreRejected()
    {
        $plan = $this->tariff();
        $admin = $this->makeUser(['is_admin' => 1]);
        $valid = $this->configuration([$this->ref($plan)]);
        $invalids = [
            $this->configuration([]),
            array_replace($valid, ['enabled' => 'false']),
            array_replace($valid, ['references' => [$this->ref($plan), $this->ref($plan)]]),
            array_replace($valid, ['references' => [$this->ref($plan, 'onetime_price')]]),
            array_replace($valid, ['references' => [['plan_id' => (string)$plan->id, 'period' => 'month_price']]]),
            array_replace($valid, ['classifications' => [['plan_id' => $plan->id, 'quota_type' => 'finite']]]),
            array_replace($valid, ['model' => ['base_usd' => 0.01]]),
            array_replace($valid, ['references' => array_fill(0, 1001, $this->ref($plan))]),
            array_replace($valid, ['classifications' => array_fill(0, 301, ['plan_id' => $plan->id, 'quota_type' => 'finite_total'])]),
        ];
        foreach ($invalids as $invalid) $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $this->body($invalid))->assertStatus(422);
        $this->assertSame(0, BotSetting::count());
    }

    public function testStaleFirstSaveAndExistingSaveCannotOverwriteOtherAdminAndNoOpSaveCreatesNewEpoch()
    {
        $plan = $this->tariff();
        $admin = $this->makeUser(['is_admin' => 1]);
        $configuration = $this->configuration([$this->ref($plan)]);
        $firstBody = $this->body($configuration);
        $first = $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $firstBody)->assertOk()->json('data');
        $stored = BotSetting::get(CustomPlanSettingsService::KEY);
        $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $firstBody)->assertStatus(409)->assertJsonPath('code', 'custom_settings_stale');
        $this->assertSame($stored, BotSetting::get(CustomPlanSettingsService::KEY));
        $nextBody = $this->body($configuration, $first);
        $next = $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $nextBody)->assertOk()->json('data');
        $this->assertNotSame($first['settings_revision'], $next['settings_revision']);
        $this->withHeaders($this->auth($admin))->postJson($this->path('save'), $nextBody)->assertStatus(409);
        $this->assertSame(1, BotSetting::count());
    }

    public function testSourcePriceChangedDuringAdminEditingRequiresReloadForPreviewAndSave()
    {
        $plan = $this->tariff([], ['month_price_usd' => 1.49]);
        $admin = $this->makeUser(['is_admin' => 1]);
        $body = $this->body($this->configuration([$this->ref($plan)]));
        DB::table('v2_plan_prices')->where('plan_id', $plan->id)->update(['month_price_usd' => 2.98]);
        foreach (['preview', 'save'] as $action) $this->withHeaders($this->auth($admin))->postJson($this->path($action), $body)
            ->assertStatus(409)->assertJsonPath('code', 'custom_settings_stale');
        $this->assertSame(0, BotSetting::count());
        $this->assertSame(1, Plan::count());
        $this->assertSame(0, Order::count());
    }

    public function testWithdrawnReferencesRejectNewOrdersWithoutDebitOrCouponUseButPaidRetryIsIdempotent()
    {
        $plan = $this->tariff([], ['month_price_usd' => 1.49]);
        $this->saveConfiguration($this->configuration([$this->ref($plan)]));
        $user = $this->makeUser(['balance' => 500000]);
        $coupon = Coupon::create(['code' => 'APPROVED', 'name' => 'discount', 'type' => 2, 'value' => 10, 'show' => 1,
            'limit_use' => 2, 'started_at' => time() - 10, 'ended_at' => time() + 86400]);
        $quote = $this->quote($user, $coupon->code);
        $paidUser = $this->makeUser(['balance' => 500000]);
        $paidQuote = $this->quote($paidUser);
        $paidTrade = $this->withHeaders($this->auth($paidUser))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $paidQuote['quote_token']])->assertOk()->json('data');
        $paid = Order::where('trade_no', $paidTrade)->firstOrFail();
        (new OrderService($paid))->paid('test');
        $balance = $paidUser->fresh()->balance;
        $expiry = $paidUser->fresh()->expired_at;
        $this->saveConfiguration($this->configuration([], [], false));
        $this->assertFalse(CustomPlanService::catalogue()['enabled']);
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token'], 'coupon_code' => $coupon->code])
            ->assertStatus(409)->assertJsonPath('code', 'custom_price_changed');
        $this->assertSame(500000, (int)$user->fresh()->balance);
        $this->assertSame(2, (int)$coupon->fresh()->limit_use);
        $this->withHeaders($this->auth($paidUser))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $paidQuote['quote_token']])
            ->assertOk()->assertJsonPath('data', $paidTrade);
        $this->assertSame($balance, $paidUser->fresh()->balance);
        $this->assertSame($expiry, $paidUser->fresh()->expired_at);
        $this->assertSame(1, Order::count());
    }

    public function testAnySelectedPriceOrClassificationChangeInvalidatesOutstandingQuotes()
    {
        $first = $this->tariff([], ['month_price_usd' => 1.49]);
        $second = $this->tariff(['transfer_enable' => 70], ['month_price_usd' => 2]);
        $this->saveConfiguration($this->configuration([$this->ref($first), $this->ref($second)]));
        $user = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($user);
        DB::table('v2_plan_prices')->where('plan_id', $second->id)->update(['month_price_usd' => 3]);
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token']])->assertStatus(409);
        $fresh = $this->quote($user);
        $configuration = $this->configuration([$this->ref($first)]);
        $configuration['classifications'][] = ['plan_id' => $second->id, 'quota_type' => 'unlimited_fup'];
        $this->saveConfiguration($configuration);
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $fresh['quote_token']])->assertStatus(409);
        $this->assertSame(500000, (int)$user->fresh()->balance);
        $this->assertSame(0, Order::count());
    }

    public function testSourceBecomingInvalidDisablesEntireConfigurationWithoutAutoFallback()
    {
        $first = $this->tariff();
        $second = $this->tariff(['transfer_enable' => 70]);
        $this->saveConfiguration($this->configuration([$this->ref($first), $this->ref($second)]));
        $second->reset_traffic_method = 0;
        $second->save();
        $this->assertSame(['enabled' => false], CustomPlanService::catalogue());
        $view = CustomPlanSettingsService::fetch();
        $this->assertFalse($view['validation']['valid']);
        $this->assertSame('resetting_quota', $view['validation']['errors'][0]['code']);
        $this->assertSame(2, Plan::count());
        $this->assertSame(0, Order::count());
    }

    public function testDeletingAnUnselectedClassifiedTariffHasNoEffectButDeletingASelectedOneDisablesPurchases()
    {
        $selected = $this->tariff();
        $unselected = $this->tariff();
        $configuration = $this->configuration([$this->ref($selected)]);
        $configuration['classifications'][] = ['plan_id' => $unselected->id, 'quota_type' => 'unlimited_fup'];
        $this->saveConfiguration($configuration);
        $before = CustomPlanService::catalogue();
        $unselected->delete();
        $this->assertSame($before, CustomPlanService::catalogue());
        $selected->delete();
        $this->assertSame(['enabled' => false], CustomPlanService::catalogue());
        $this->assertSame('plan_missing', CustomPlanSettingsService::fetch()['validation']['errors'][0]['code']);
    }

    public function testCorruptNullAndPartialConfigurationFailsClosedAndAdminCanRepairIt()
    {
        $plan = $this->tariff();
        foreach (['{broken', '{"version":2}', null] as $raw) {
            DB::table('v2_bot_settings')->updateOrInsert(['key' => CustomPlanSettingsService::KEY], ['value' => $raw]);
            $this->assertFalse(CustomPlanService::catalogue()['enabled']);
            $this->assertFalse(CustomPlanSettingsService::fetch()['validation']['valid']);
            $this->saveConfiguration($this->configuration([$this->ref($plan)]));
            $this->assertTrue(CustomPlanService::catalogue()['enabled']);
        }
    }

    public function testMissingSettingsOrPartialPriceSchemaFailsClosedWithoutCreatingRows()
    {
        $this->tariff();
        DB::statement('ALTER TABLE v2_plan_prices DROP COLUMN year_price_usd');
        $this->assertFalse(CustomPlanSettingsService::fetch()['capable']);
        $this->assertFalse(CustomPlanService::catalogue()['enabled']);
        $this->assertSame(0, BotSetting::count());
        DB::statement('DROP TABLE v2_bot_settings');
        $this->assertFalse(CustomPlanSettingsService::fetch()['capable']);
        $this->assertFalse(CustomPlanService::catalogue()['enabled']);
        $this->assertSame(0, Order::count());
    }

    public function testSettingsTableWithoutUniqueKeyCannotEnablePurchasesOrAcceptAdminSaves()
    {
        $plan = $this->tariff();
        DB::statement('DROP TABLE v2_bot_settings');
        DB::statement('CREATE TABLE v2_bot_settings ("key" TEXT, value TEXT, created_at TEXT, updated_at TEXT)');
        $this->assertFalse(CustomPlanSettingsService::fetch()['capable']);
        $this->assertSame(['enabled' => false], CustomPlanService::catalogue());
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->withHeaders($this->auth($admin))->postJson($this->path('save'), ['configuration' => $this->configuration([$this->ref($plan)]),
            'settings_revision' => str_repeat('a', 64), 'source_revision' => str_repeat('b', 64)])->assertStatus(503);
        $this->assertSame(0, BotSetting::count());
    }
}
