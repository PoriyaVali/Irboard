<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ReservedPlan;
use App\Models\BotSetting;
use App\Jobs\OrderHandleJob;
use App\Services\CustomPlanService;
use App\Services\CustomPlanSettingsService;
use App\Services\GiftOrderService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

class CustomPlanOrderTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_plan_prices']);
        DB::statement('CREATE UNIQUE INDEX custom_key ON v2_plan (custom_key)');
        DB::statement('CREATE UNIQUE INDEX custom_trade_no ON v2_order (trade_no)');
        DB::statement('CREATE UNIQUE INDEX settings_key ON v2_bot_settings ("key")');
        config(['app.key' => 'base64:' . base64_encode(str_repeat('t', 32))]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    private function auth($user): array
    {
        $token = 'custom-test-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function tariff(array $attrs = [], array $usd = []): Plan
    {
        $plan = $this->makePlan(array_merge(['transfer_enable' => 35, 'reset_traffic_method' => 2,
            'month_price' => 89000], $attrs));
        if ($usd) DB::table('v2_plan_prices')->insert(array_merge(['plan_id' => $plan->id], $usd));
        // Financial fixtures explicitly approve compatible finite-total sources.
        // Production defaults never auto-select a tariff. Dedicated admin tests
        // create unapproved tariffs and cover all rejection/withdrawal cases.
        $saved = json_decode(BotSetting::get(CustomPlanSettingsService::KEY, '{}'), true);
        $configuration = $saved['configuration'] ?? ['enabled' => false, 'classifications' => [], 'references' => []];
        $configuration['classifications'][] = ['plan_id' => (int)$plan->id, 'quota_type' => 'finite_total'];
        $cohort = CustomPlanSettingsService::cohort($plan);
        $first = $configuration['references'] ? Plan::find($configuration['references'][0]['plan_id']) : null;
        if ($plan->show && $plan->capacity_limit === null && $plan->transfer_enable >= 1 && $plan->transfer_enable <= 1024
            && $cohort['reset_traffic_method'] === 2 && (!$first || CustomPlanSettingsService::cohort($first) === $cohort)) {
            foreach (CustomPlanSettingsService::PERIOD_DAYS as $period => $days) {
                if ($plan->$period !== null && $plan->$period > 0) $configuration['references'][] = ['plan_id' => (int)$plan->id, 'period' => $period];
            }
        }
        $configuration['enabled'] = (bool)$configuration['references'];
        BotSetting::set(CustomPlanSettingsService::KEY, json_encode(['version' => 1, 'generation' => 'explicit-test-approval-' . $plan->id,
            'configuration' => CustomPlanSettingsService::normalize($configuration)]));
        return $plan;
    }

    private function quote($user, array $extra = []): array
    {
        $headers = $this->auth($user);
        $model = $this->withHeaders($headers)->getJson('/api/v1/user/plan/custom')->assertOk()->json('data');
        return $this->withHeaders($headers)->postJson('/api/v1/user/plan/custom/quote', array_merge([
            'volume_gb' => 73, 'duration_days' => 17, 'pricing_revision' => $model['pricing_revision'],
        ], $extra))->assertOk()->json('data');
    }

    private function saveQuote($user, array $quote, array $extra = []): Order
    {
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', array_merge([
            'quote_token' => $quote['quote_token'], 'use_wallet' => true,
        ], $extra))->assertOk();
        return Order::where('trade_no', $response->json('data'))->firstOrFail();
    }

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create(array_merge(['code' => 'CUSTOM-' . bin2hex(random_bytes(4)), 'name' => 'discount',
            'type' => 2, 'value' => 10, 'show' => 1, 'limit_use' => 1,
            'started_at' => time() - 10, 'ended_at' => time() + 86400], $attrs));
    }

    public function testCatalogueInfersMedianBasisFromActualNinePointsWithoutWrites()
    {
        foreach ([[35, 1.49, 1.70, null], [70, 2.00, 2.70, null], [200, 3.10, 3.80, null], [500, 6.20, 7.30, 8.99]] as $sample) {
            $this->tariff(['transfer_enable' => $sample[0], 'quarter_price' => 100000, 'half_year_price' => $sample[3] ? 200000 : null],
                ['month_price_usd' => $sample[1], 'quarter_price_usd' => $sample[2], 'half_year_price_usd' => $sample[3]]);
        }
        $user = $this->makeUser();
        $data = $this->withHeaders($this->auth($user))->getJson('/api/v1/user/plan/custom')->assertOk()->json('data');
        $this->assertTrue($data['enabled']);
        $this->assertEqualsWithDelta(1.498661842, $data['model']['base_usd'], 0.00000001);
        $this->assertCount(9, $data['source_points']);
        $this->assertSame(60000, $data['exchange_rate']);
        $this->assertSame('Toman', $data['currency']);
        $this->assertSame(4, Plan::count());
        $this->assertSame(0, Order::count());
    }

    public function testOnlyExplicitlyApprovedCompatiblePointsAndCanonicalUsdOverlaySupplyBasis()
    {
        $this->tariff(['sort' => -1, 'capacity_limit' => 100], ['month_price_usd' => 99]);
        $source = $this->tariff(['sort' => 0, 'month_price_usd' => 99], ['month_price_usd' => 1.49]);
        $this->tariff(['sort' => 1, 'group_id' => 2], ['month_price_usd' => 999]);
        $this->tariff(['sort' => 1, 'device_limit' => 3], ['month_price_usd' => 999]);
        $this->tariff(['sort' => 1, 'speed_limit' => 40], ['month_price_usd' => 999]);
        $this->tariff(['sort' => 1, 'reset_traffic_method' => 0], ['month_price_usd' => 999]);
        $this->tariff(['sort' => 1, 'transfer_enable' => 1025], ['month_price_usd' => 999]);
        $data = CustomPlanService::catalogue();
        $this->assertSame($source->id, $data['source_plan_id']);
        $this->assertCount(1, $data['source_points']);
        $this->assertEquals(1.49, $data['model']['base_usd']);
        DB::table('v2_plan_prices')->where('plan_id', $source->id)->update(['month_price_usd' => 2.98]);
        $changed = CustomPlanService::catalogue();
        $this->assertEquals(2.98, $changed['model']['base_usd']);
        $this->assertNotSame($data['pricing_revision'], $changed['pricing_revision']);
    }

    public function testTomanOnlyTariffsAreReadUsingExistingCurrencyUnits()
    {
        $this->tariff(['month_price' => 120000]);
        $data = CustomPlanService::catalogue();
        $this->assertSame('toman', $data['source_points'][0]['price_source']);
        $this->assertEquals(2.0, $data['model']['base_usd']);
        $quote = $this->quote($this->makeUser(), ['volume_gb' => 35, 'duration_days' => 30]);
        $this->assertSame(120000, $quote['tariff_amount']);
    }

    public function testPartialSchemaAndNoEligibleTariffsFailClosed()
    {
        $user = $this->makeUser(['balance' => 500000]);
        $this->tariff(['capacity_limit' => 10]);
        $headers = $this->auth($user);
        $this->withHeaders($headers)->getJson('/api/v1/user/plan/custom')->assertOk()->assertJsonPath('data.enabled', false);
        $this->withHeaders($headers)->postJson('/api/v1/user/plan/custom/quote', ['volume_gb' => 35, 'duration_days' => 30, 'pricing_revision' => str_repeat('a', 64)])
            ->assertStatus(503)->assertJsonPath('code', 'custom_unavailable');
        DB::statement('ALTER TABLE v2_order DROP COLUMN custom_snapshot');
        $this->withHeaders($headers)->getJson('/api/v1/user/plan/custom')->assertOk()->assertJsonPath('data.enabled', false);
        $this->withHeaders($headers)->postJson('/api/v1/user/order/saveCustom', ['quote_token' => 'invalid'])->assertStatus(503);
        $this->assertSame(500000, (int)$user->fresh()->balance);
        $this->assertSame(0, Order::count());
    }

    public function testQuoteHasNoMoneyOrCouponSideEffectsAndReusesOneHiddenTemplate()
    {
        $this->tariff([], ['month_price_usd' => 1.49]);
        $user = $this->makeUser(['balance' => 500000]);
        $coupon = $this->coupon();
        $first = $this->quote($user, ['coupon_code' => $coupon->code]);
        $second = $this->quote($user, ['volume_gb' => 1024, 'duration_days' => 365]);
        $this->assertSame($first['template_plan_id'], $second['template_plan_id']);
        $hidden = Plan::find($first['template_plan_id']);
        $this->assertSame(0, (int)$hidden->show);
        $this->assertSame(0, (int)$hidden->renew);
        $this->assertSame(2, (int)$hidden->reset_traffic_method);
        $this->assertNull($hidden->month_price);
        $this->assertSame(2, Plan::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertSame(500000, (int)$user->fresh()->balance);
        $this->assertSame($first['total_amount'], $first['balance_amount']);
        $this->assertSame(0, $first['payable_amount']);
        $this->assertSame(500000, $first['wallet_balance']);
        $this->assertTrue($first['wallet_eligible']);
    }

    public function testInputBoundsAndStaleChartAreRefusedBeforeMoneyMoves()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        $headers = $this->auth($user);
        $revision = CustomPlanService::catalogue()['pricing_revision'];
        foreach ([['volume_gb' => 0], ['volume_gb' => 1025], ['volume_gb' => '1,5'], ['volume_gb' => 1.5], ['duration_days' => 0], ['duration_days' => 366]] as $invalid) {
            $this->withHeaders($headers)->postJson('/api/v1/user/plan/custom/quote', array_merge([
                'volume_gb' => 35, 'duration_days' => 30, 'pricing_revision' => $revision], $invalid))->assertStatus(422);
        }
        $this->withHeaders($headers)->postJson('/api/v1/user/plan/custom/quote', ['volume_gb' => 35, 'duration_days' => 30, 'pricing_revision' => str_repeat('a', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'custom_price_changed');
        $this->assertSame(0, Order::count());
        $this->assertSame(1, Plan::count());
        $this->assertSame(500000, (int)$user->fresh()->balance);
    }

    public function testSavedPriceAndFulfilmentIgnoreClientAmountsAndApplyExactDaysAndTotalQuotaOnce()
    {
        $this->tariff([], ['month_price_usd' => 1.49]);
        $user = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($user);
        $order = $this->saveQuote($user, $quote, ['total_amount' => 1, 'volume_gb' => 1024, 'duration_days' => 365, 'plan_id' => 0]);
        $this->assertSame($quote['total_amount'], (int)$order->balance_amount);
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertSame(73, $order->custom_snapshot['volume_gb']);
        $this->assertSame(17, $order->custom_snapshot['duration_days']);
        $this->assertNull($user->fresh()->plan_id);
        (new OrderService($order))->open();
        $this->assertNull($user->fresh()->plan_id, 'unpaid invocation cannot fulfil');
        $before = time();
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertOk();
        $user->refresh();
        $this->assertSame(73 * 1073741824, (int)$user->transfer_enable);
        $this->assertGreaterThanOrEqual($before + 17 * 86400, (int)$user->expired_at);
        $this->assertLessThanOrEqual(time() + 17 * 86400, (int)$user->expired_at);
        $expiry = $user->expired_at;
        (new OrderService($order))->paid('duplicate');
        (new OrderService($order))->open();
        $this->assertSame($expiry, $user->fresh()->expired_at);
        $this->assertSame(3, (int)$order->fresh()->status);
    }

    public function testSaveReplayReturnsTheSameTradeAndDebitsWalletExactlyOnce()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($user);
        $first = $this->saveQuote($user, $quote);
        $second = $this->saveQuote($user, $quote);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(500000 - $quote['total_amount'], (int)$user->fresh()->balance);
        (new OrderService($first))->paid('test');
        $this->assertSame($first->id, $this->saveQuote($user, $quote)->id);
    }

    public function testQuoteIsBoundToUserAuthenticatedCiphertextAndExpiry()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        $other = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($user);
        $this->withHeaders($this->auth($other))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token']])
            ->assertStatus(422)->assertJsonPath('code', 'custom_invalid_quote');
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token'] . 'x'])->assertStatus(422);
        $expired = json_decode(Crypt::decryptString($quote['quote_token']), true);
        $expired['expires_at'] = time() - 1;
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => Crypt::encryptString(json_encode($expired))])
            ->assertStatus(409)->assertJsonPath('code', 'custom_quote_expired');
        $this->assertSame(0, Order::count());
        $this->assertSame(500000, (int)$user->fresh()->balance);
    }

    public function testWalletVipAndCouponChangesRequireNewConfirmationAndRollBackCouponConsumption()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000, 'discount' => 10]);
        $coupon = $this->coupon();
        $quote = $this->quote($user, ['coupon_code' => $coupon->code]);
        $user->balance = 0;
        $user->save();
        $headers = $this->auth($user);
        $this->withHeaders($headers)->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token'], 'coupon_code' => $coupon->code])
            ->assertStatus(409)->assertJsonPath('code', 'custom_quote_changed');
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $user->balance = 500000;
        $user->discount = 20;
        $user->save();
        $this->withHeaders($headers)->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token'], 'coupon_code' => $coupon->code])->assertStatus(409);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $user->discount = 10;
        $user->save();
        $coupon->value = 20;
        $coupon->save();
        $this->withHeaders($headers)->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token'], 'coupon_code' => $coupon->code])->assertStatus(409);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertSame(0, Order::count());
    }

    public function testGeneralCouponAndVipUseRawTariffAndWalletOptOutIsHonoured()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000, 'discount' => 10]);
        $coupon = $this->coupon(['value' => 20]);
        $quote = $this->quote($user, ['coupon_code' => $coupon->code]);
        $this->assertSame((int)round($quote['tariff_amount'] * 0.30), $quote['discount_amount']);
        $order = $this->saveQuote($user, $quote, ['coupon_code' => $coupon->code, 'use_wallet' => false]);
        $this->assertSame($quote['total_amount'], (int)$order->total_amount);
        $this->assertSame(0, (int)$order->balance_amount);
        $this->assertSame(500000, (int)$user->fresh()->balance);
        $this->assertSame(0, (int)$coupon->fresh()->limit_use);
    }

    public function testFixedPlanAndPeriodCouponsCannotBuyArbitraryTailoredQuota()
    {
        $source = $this->tariff();
        $user = $this->makeUser();
        $catalogue = CustomPlanService::catalogue();
        foreach ([['limit_plan_ids' => [(string)$source->id]], ['limit_period' => ['month_price']]] as $limit) {
            $coupon = $this->coupon($limit);
            $this->withHeaders($this->auth($user))->postJson('/api/v1/user/plan/custom/quote', ['volume_gb' => 1024,
                'duration_days' => 365, 'pricing_revision' => $catalogue['pricing_revision'], 'coupon_code' => $coupon->code])->assertStatus(500);
            $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        }
        $this->assertSame(0, Order::count());
    }

    public function testPurchasedGiftCannotPayForTailoredPackageAndTailoredGiftFlagIsRefused()
    {
        $source = $this->tariff();
        $buyer = $this->makeUser();
        $gift = $this->makeOrder($buyer, ['plan_id' => $source->id, 'is_gift' => 1, 'type' => 8, 'status' => 1]);
        GiftOrderService::fulfil($gift);
        $code = Coupon::firstOrFail()->code;
        $recipient = $this->makeUser();
        $catalogue = CustomPlanService::catalogue();
        $headers = $this->auth($recipient);
        $input = ['volume_gb' => 35, 'duration_days' => 30, 'pricing_revision' => $catalogue['pricing_revision']];
        $this->withHeaders($headers)->postJson('/api/v1/user/plan/custom/quote', $input + ['coupon_code' => $code])->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/v1/user/plan/custom/quote', $input + ['is_gift' => true])->assertStatus(422);
        $this->assertSame(1, (int)Coupon::first()->limit_use);
    }

    public function testActiveUserKeepsCurrentAllowanceAndQueuedPackageActivatesExactSnapshotLater()
    {
        $old = $this->tariff(['transfer_enable' => 100]);
        $user = $this->makeActiveUser($old, ['balance' => 500000]);
        $quote = $this->quote($user);
        $this->assertTrue($quote['will_reserve']);
        $order = $this->saveQuote($user, $quote);
        (new OrderService($order))->paid('test');
        $this->assertSame($old->id, (int)$user->fresh()->plan_id);
        $this->assertSame(100 * 1073741824, (int)$user->fresh()->transfer_enable);
        $reserved = ReservedPlan::firstOrFail();
        $this->assertSame($order->id, (int)$reserved->order_id);
        $user->expired_at = time() - 1;
        $user->save();
        // Changing/deleting the source after payment cannot change delivery.
        Plan::where('id', $old->id)->delete();
        $before = time();
        $this->artisan('reserved:activate')->assertExitCode(0);
        $user->refresh();
        $this->assertSame(73 * 1073741824, (int)$user->transfer_enable);
        $this->assertGreaterThanOrEqual($before + 17 * 86400, (int)$user->expired_at);
        $expiry = $user->expired_at;
        $this->artisan('reserved:activate')->assertExitCode(0);
        $this->assertSame($expiry, $user->fresh()->expired_at);
        $this->assertSame(1, (int)$reserved->fresh()->status);
    }

    public function testReservationLimitAndPendingOrderAreCheckedBeforeAnyDebit()
    {
        $old = $this->tariff();
        $user = $this->makeActiveUser($old, ['balance' => 500000]);
        $quote = $this->quote($user);
        for ($i = 0; $i < 10; $i++) ReservedPlan::create(['user_id' => $user->id, 'order_id' => 100 + $i,
            'plan_id' => $old->id, 'period' => 'month_price', 'status' => 0, 'created_at' => time(), 'updated_at' => time()]);
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token']])->assertStatus(409);
        ReservedPlan::query()->delete();
        $pending = $this->makeOrder($user);
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token']])
            ->assertStatus(409)->assertJsonPath('code', 'pending_order');
        $this->assertSame(500000, (int)$user->fresh()->balance);
        $this->assertSame(1, Order::count());
    }

    public function testReceiptAndReservationMetadataAreOwnerScopedWithoutRawSnapshot()
    {
        $old = $this->tariff();
        $user = $this->makeActiveUser($old, ['balance' => 500000]);
        $other = $this->makeUser();
        $order = $this->saveQuote($user, $this->quote($user));
        (new OrderService($order))->paid('test');
        $headers = $this->auth($user);
        foreach (['/api/v1/user/order/detail?trade_no=' . $order->trade_no, '/api/v1/user/order/fetch'] as $path) {
            $data = $this->withHeaders($headers)->getJson($path)->assertOk()->json('data');
            if (isset($data[0])) $data = $data[0];
            $this->assertTrue($data['is_custom']);
            $this->assertSame(73, $data['custom_volume_gb']);
            $this->assertSame(17, $data['custom_duration_days']);
            $this->assertSame(73, $data['plan']['transfer_enable']);
            $this->assertArrayNotHasKey('custom_snapshot', $data);
        }
        $this->withHeaders($headers)->getJson('/api/v1/user/reserved')->assertOk()->assertJsonPath('data.0.custom_volume_gb', 73)->assertJsonPath('data.0.custom_duration_days', 17);
        $this->withHeaders($this->auth($other))->getJson('/api/v1/user/order/detail?trade_no=' . $order->trade_no)->assertStatus(500);
        $this->withHeaders($this->auth($other))->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonCount(0, 'data');
    }

    public function testSourceSaleOrCapacityChangeAfterQuoteCannotCreateAnOrder()
    {
        $source = $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($user);
        $source->capacity_limit = 10;
        $source->save();
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token']])
            ->assertStatus(409)->assertJsonPath('code', 'custom_price_changed');
        $source->capacity_limit = null;
        $source->show = 0;
        $source->save();
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $quote['quote_token']])->assertStatus(409);
        $this->assertSame(0, Order::count());
        $this->assertSame(500000, (int)$user->fresh()->balance);
    }

    public function testCustomToNormalPurchaseCannotCrashOrMintCalendarMonthSurplus()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        $order = $this->saveQuote($user, $this->quote($user));
        (new OrderService($order))->paid('test');
        $user->refresh();
        $user->u = $user->transfer_enable - 1;
        $user->save();
        $normal = $this->tariff(['month_price' => 150000]);
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/save', [
            'plan_id' => $normal->id, 'period' => 'month_price', 'use_wallet' => false])->assertOk();
        $next = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertSame(150000, (int)$next->total_amount);
        $this->assertEmpty($next->surplus_amount);
        $this->assertEmpty($next->refund_amount);
    }

    public function testCancelledCustomOrderRefundAndLateGatewayPaymentReconcileOnlyOnce()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 10000]);
        $order = $this->saveQuote($user, $this->quote($user));
        $total = (int)$order->total_amount;
        $this->assertGreaterThan(0, $total);
        $service = new OrderService($order);
        $this->assertTrue($service->cancel());
        $this->assertTrue($service->cancel());
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertTrue($service->paid('late-gateway'));
        $this->assertTrue($service->paid('duplicate-gateway'));
        $this->assertSame(10000 + $total, (int)$user->fresh()->balance);
        $this->assertSame(4, (int)$order->fresh()->status);
        $this->assertNull($user->fresh()->plan_id);
    }

    public function testHiddenTemplateCannotBePurchasedThroughOrdinaryGiftOrResetRoutes()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($user);
        foreach (['/api/v1/user/order/save', '/api/v1/user/order/saveGift'] as $path) {
            $this->withHeaders($this->auth($user))->postJson($path, ['plan_id' => $quote['template_plan_id'], 'period' => 'month_price'])->assertStatus(422);
        }
        $this->assertSame(0, Order::count());
    }

    public function testExistingFulfilledQuoteRemainsIdempotentAfterExpiryAndAllEconomicChanges()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        $coupon = $this->coupon();
        $quote = $this->quote($user, ['coupon_code' => $coupon->code]);
        $order = $this->saveQuote($user, $quote, ['coupon_code' => $coupon->code]);
        (new OrderService($order))->paid('confirmed');
        $user->refresh();
        $originalExpiry = $user->expired_at;
        $user->discount = 90;
        $user->balance = 12345;
        $user->save();
        $expired = json_decode(Crypt::decryptString($quote['quote_token']), true);
        $expired['expires_at'] = time() - 900;
        $quote['quote_token'] = Crypt::encryptString(json_encode($expired, JSON_PRESERVE_ZERO_FRACTION));
        // The first success may have been lost on the network. Recovery is
        // delivery of its receipt, even when confirmation data is now stale.
        $retry = $this->saveQuote($user, $quote, ['coupon_code' => 'changed-after-payment']);
        $this->assertSame($order->id, $retry->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(12345, (int)$user->fresh()->balance);
        $this->assertSame($originalExpiry, $user->fresh()->expired_at);
    }

    public function testCouponExhaustedAfterQuoteReturnsMachineConflictWithoutCharge()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        $coupon = $this->coupon();
        $quote = $this->quote($user, ['coupon_code' => $coupon->code]);
        $coupon->limit_use = 0;
        $coupon->save();
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/saveCustom', [
            'quote_token' => $quote['quote_token'], 'coupon_code' => $coupon->code])
            ->assertStatus(409)->assertJsonPath('code', 'custom_quote_changed');
        $this->assertSame(0, Order::count());
        $this->assertSame(500000, (int)$user->fresh()->balance);
    }

    public function testTailoredAllowanceHasNoPeriodicResetOrAutomaticRenewalCharge()
    {
        $this->tariff(['reset_traffic_method' => 2]);
        $user = $this->makeUser(['balance' => 500000, 'auto_renewal' => 1]);
        $order = $this->saveQuote($user, $this->quote($user, ['duration_days' => 365]));
        (new OrderService($order))->paid('test');
        $user->refresh();
        $user->expired_at = time() + 1800;
        $user->u = 1073741824;
        $user->save();
        $before = $user->getAttributes();
        $this->assertNull((new \App\Services\UserService())->getResetDay($user));
        $this->assertNull((new \App\Services\UserService())->getResetPeriod($user));
        config(['v2board.allow_new_period' => 1]);
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/newPeriod')->assertStatus(500);
        $this->artisan('check:renewal')->assertExitCode(0);
        foreach (['balance', 'u', 'd', 'transfer_enable', 'expired_at'] as $key) $this->assertSame($before[$key], $user->fresh()->getAttributes()[$key], $key);
    }

    public function testReservationCannotActivateAnOrderOwnedByAnotherAccount()
    {
        $old = $this->tariff();
        $user = $this->makeActiveUser($old, ['balance' => 500000]);
        $other = $this->makeUser();
        $order = $this->saveQuote($user, $this->quote($user));
        (new OrderService($order))->paid('test');
        $reserved = ReservedPlan::firstOrFail();
        $reserved->user_id = $other->id;
        $reserved->save();
        $this->artisan('reserved:activate')->assertExitCode(0);
        $this->assertNull($other->fresh()->plan_id);
        $this->assertSame(0, (int)$reserved->fresh()->status);
        $this->assertSame($old->id, (int)$user->fresh()->plan_id);
    }

    public function testInclusiveVolumeAndExactDayBoundsReallyFulfil()
    {
        $this->tariff();
        foreach ([[1, 1], [1024, 365]] as [$volume, $days]) {
            $user = $this->makeUser(['balance' => 5000000]);
            $quote = $this->quote($user, ['volume_gb' => $volume, 'duration_days' => $days]);
            $order = $this->saveQuote($user, $quote);
            $before = time();
            (new OrderService($order))->paid('test');
            $this->assertSame($volume * 1073741824, (int)$user->fresh()->transfer_enable);
            $this->assertGreaterThanOrEqual($before + $days * 86400, (int)$user->fresh()->expired_at);
            $this->assertLessThanOrEqual(time() + $days * 86400, (int)$user->fresh()->expired_at);
        }
        $this->assertSame(1, Plan::whereNotNull('custom_key')->count());
    }

    public function testMissingUniqueTemplateIndexDoesNotAdvertiseUnsafePartialRollout()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000]);
        DB::statement('DROP INDEX custom_key');
        $this->withHeaders($this->auth($user))->getJson('/api/v1/user/plan/custom')->assertOk()->assertJsonPath('data.enabled', false);
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/plan/custom/quote', [
            'volume_gb' => 35, 'duration_days' => 30, 'pricing_revision' => str_repeat('a', 64)])
            ->assertStatus(503)->assertJsonPath('code', 'custom_unavailable');
        $this->assertSame(1, Plan::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(500000, (int)$user->fresh()->balance);
    }

    public function testCurrentTailoredAllowanceNeverCreditsOldOrdinaryHistory()
    {
        $old = $this->tariff(['month_price' => 100000]);
        $user = $this->makeUser(['balance' => 500000]);
        $oldOrder = $this->makeOrder($user, ['plan_id' => $old->id, 'total_amount' => 100000]);
        (new OrderService($oldOrder))->paid('old-month');
        $user->refresh();
        $user->u = $user->transfer_enable;
        $user->save();
        $custom = $this->saveQuote($user, $this->quote($user, ['volume_gb' => 1, 'duration_days' => 365]));
        (new OrderService($custom))->paid('custom-year');
        $user->refresh();
        $user->u = $user->transfer_enable - 104857600;
        $user->save();
        $next = $this->tariff(['month_price' => 1000]);
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/save', [
            'plan_id' => $next->id, 'period' => 'month_price', 'use_wallet' => false])->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertSame(1000, (int)$order->total_amount, 'exhausted old ordinary history cannot be valued against the new custom year');
        $this->assertEmpty($order->surplus_amount);
        $this->assertEmpty($order->refund_amount);
    }

    public function testTailoredBoundaryCannotReviveOldOrdinaryCreditOnLaterNormalPurchases()
    {
        $old = $this->tariff(['month_price' => 100000]);
        $user = $this->makeUser(['balance' => 500000]);
        $oldOrder = $this->makeOrder($user, ['plan_id' => $old->id, 'total_amount' => 100000]);
        (new OrderService($oldOrder))->paid('old-month');
        $user->refresh();
        $user->u = $user->transfer_enable;
        $user->save();
        $custom = $this->saveQuote($user, $this->quote($user, ['volume_gb' => 1, 'duration_days' => 365]));
        (new OrderService($custom))->paid('custom-year');
        $user->refresh();
        $user->u = $user->transfer_enable;
        $user->save();
        $middle = $this->tariff(['month_price' => 1000]);
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/save', [
            'plan_id' => $middle->id, 'period' => 'month_price', 'use_wallet' => false])->assertOk();
        $middleOrder = Order::where('trade_no', $response->json('data'))->firstOrFail();
        (new OrderService($middleOrder))->paid('normal-after-custom');
        $user->refresh();
        $user->u = $user->transfer_enable;
        $user->save();
        $next = $this->tariff(['month_price' => 200000]);
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/save', [
            'plan_id' => $next->id, 'period' => 'month_price', 'use_wallet' => false])->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertSame(200000, (int)$order->total_amount, 'ordinary history exhausted before a custom allowance cannot become credit again later');
        $this->assertEmpty($order->surplus_amount);
        $this->assertEmpty($order->refund_amount);
    }

    public function testOldOrdinaryReservationActivatedAfterCustomInSameSecondKeepsItsPaidValue()
    {
        $this->tariff();
        $reservedPlan = $this->tariff(['show' => 0, 'year_price' => 1200000]);
        $user = $this->makeUser(['balance' => 500000]);
        $reservedOrder = $this->makeOrder($user, ['plan_id' => $reservedPlan->id, 'period' => 'year_price',
            'total_amount' => 1200000, 'status' => 3, 'paid_at' => time() - 500, 'created_at' => time() - 500]);
        $reservation = ReservedPlan::create(['user_id' => $user->id, 'order_id' => $reservedOrder->id,
            'plan_id' => $reservedPlan->id, 'period' => 'year_price', 'status' => 0, 'created_at' => time() - 500, 'updated_at' => time() - 500]);
        $custom = $this->saveQuote($user, $this->quote($user, ['volume_gb' => 1, 'duration_days' => 365]));
        (new OrderService($custom))->paid('custom');
        $at = $custom->fresh()->custom_snapshot['activated_at'];
        $user->refresh();
        $user->u = $user->transfer_enable;
        $user->save();
        $this->artisan('reserved:activate')->assertExitCode(0);
        // Model the exact same-second chronology explicitly; the already-paid
        // old-ID reservation was still pending when custom delivery occurred.
        $reservation->refresh();
        $this->assertSame(1, (int)$reservation->status);
        $reservation->activated_at = $at;
        $reservation->save();
        $user->refresh();
        $this->assertSame($reservedPlan->id, (int)$user->plan_id);
        $user->expired_at = time() + 1800;
        $user->save();
        $next = $this->tariff(['month_price' => 100000]);
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/save', [
            'plan_id' => $next->id, 'period' => 'month_price', 'use_wallet' => false])->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertGreaterThan(0, $order->surplus_amount);
        $this->assertLessThan(100000, $order->surplus_amount);
        $this->assertContains($reservedOrder->id, $order->surplus_order_ids);
    }

    public function testOldCancelledGatewayOrderPaidAfterCustomInSameSecondKeepsItsPaidValue()
    {
        $this->tariff();
        $latePlan = $this->tariff(['show' => 0, 'year_price' => 1200000]);
        $user = $this->makeUser(['balance' => 500000]);
        $late = $this->makeOrder($user, ['plan_id' => $latePlan->id, 'period' => 'year_price',
            'total_amount' => 1200000, 'balance_amount' => 0, 'status' => 2, 'created_at' => time() - 500]);
        $custom = $this->saveQuote($user, $this->quote($user, ['volume_gb' => 1, 'duration_days' => 365]));
        (new OrderService($custom))->paid('custom');
        $at = $custom->fresh()->custom_snapshot['activated_at'];
        $user->refresh();
        $user->u = $user->transfer_enable;
        $user->save();
        $this->assertTrue((new OrderService($late))->paid('verified-late-payment'));
        $late->refresh();
        $this->assertSame(3, (int)$late->status);
        $late->paid_at = $at;
        $late->save();
        $user->refresh();
        $this->assertSame($latePlan->id, (int)$user->plan_id);
        $this->assertSame(0, ReservedPlan::count());
        $user->expired_at = time() + 1800;
        $user->save();
        $next = $this->tariff(['month_price' => 100000]);
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/save', [
            'plan_id' => $next->id, 'period' => 'month_price', 'use_wallet' => false])->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertLessThan($custom->id, $late->id);
        $this->assertGreaterThan(0, $order->surplus_amount);
        $this->assertContains($late->id, $order->surplus_order_ids);
    }

    public function testStaffWalletIsReportedButNotEligibleForTailoredDebit()
    {
        $this->tariff();
        $user = $this->makeUser(['balance' => 500000, 'is_staff' => 1]);
        $quote = $this->quote($user);
        $this->assertSame(500000, $quote['wallet_balance']);
        $this->assertFalse($quote['wallet_eligible']);
        $this->assertSame(0, $quote['balance_amount']);
        $this->assertSame($quote['total_amount'], $quote['payable_amount']);
        $order = $this->saveQuote($user, $quote);
        $this->assertSame(0, (int)$order->balance_amount);
        $this->assertSame(500000, (int)$user->fresh()->balance);
    }

    public function testPaidOrdinaryJobHeldBeforeCustomButDeliveredAfterItRetainsNewPaidValue()
    {
        $source = $this->tariff();
        $user = $this->makeActiveUser($source, ['balance' => 500000]);
        $custom = $this->saveQuote($user, $this->quote($user, ['volume_gb' => 1, 'duration_days' => 365]));
        (new OrderService($custom))->paid('custom-queued');
        $this->assertSame(0, (int)ReservedPlan::where('order_id', $custom->id)->firstOrFail()->status);
        $ordinaryPlan = $this->tariff(['show' => 0, 'year_price' => 1200000]);
        $ordinary = $this->makeOrder($user, ['plan_id' => $ordinaryPlan->id, 'period' => 'year_price',
            'total_amount' => 1200000, 'created_at' => time() - 500]);
        Bus::fake([OrderHandleJob::class]);
        $this->assertTrue((new OrderService($ordinary))->paid('paid-before-custom'));
        Bus::assertDispatched(OrderHandleJob::class);
        $ordinary->refresh();
        $this->assertSame(1, (int)$ordinary->status);
        // Model a queue worker held for several minutes, without sleeping.
        $ordinary->paid_at = time() - 500;
        $ordinary->save();
        $user->expired_at = time() - 1;
        $user->save();
        $this->artisan('reserved:activate')->assertExitCode(0);
        $custom->refresh();
        $this->assertArrayHasKey('activated_at', $custom->custom_snapshot);
        $user->refresh();
        $user->u = $user->transfer_enable;
        $user->save();
        // Deliver the real held job after custom is exhausted. This ordinary
        // right opens directly and therefore has no reservation timestamp.
        (new OrderHandleJob($ordinary->trade_no))->handle();
        $this->assertSame(3, (int)$ordinary->fresh()->status);
        $this->assertFalse(ReservedPlan::where('order_id', $ordinary->id)->exists());
        $user->refresh();
        $this->assertSame($ordinaryPlan->id, (int)$user->plan_id);
        $user->expired_at = time() + 1800;
        $user->save();
        $next = $this->tariff(['month_price' => 100000]);
        $response = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/save', [
            'plan_id' => $next->id, 'period' => 'month_price', 'use_wallet' => false])->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertGreaterThan(0, $order->surplus_amount, 'paid before custom but delivered later is a new ordinary allowance');
        $this->assertContains($ordinary->id, $order->surplus_order_ids);
    }

    public function testPreservedPaidJobStillCannotCreditItsUnactivatedReservation()
    {
        $source = $this->tariff();
        $user = $this->makeActiveUser($source, ['balance' => 500000]);
        $custom = $this->saveQuote($user, $this->quote($user, ['volume_gb' => 1, 'duration_days' => 365]));
        (new OrderService($custom))->paid('custom-queued');
        $ordinaryPlan = $this->tariff(['show' => 0, 'year_price' => 1200000]);
        $ordinary = $this->makeOrder($user, ['plan_id' => $ordinaryPlan->id, 'period' => 'year_price',
            'total_amount' => 1200000, 'created_at' => time() - 500]);
        Bus::fake([OrderHandleJob::class]);
        $this->assertTrue((new OrderService($ordinary))->paid('held-paid-job'));
        $ordinary->refresh();
        $ordinary->paid_at = time() - 500;
        $ordinary->save();
        $user->expired_at = time() - 1;
        $user->save();
        $this->artisan('reserved:activate')->assertExitCode(0);
        $snapshot = $custom->fresh()->custom_snapshot;
        $this->assertContains($ordinary->id, $snapshot['pending_paid_order_ids']);
        // Deliver while the custom allowance is still live: the normal right
        // is queued and its paid value must remain outside cash-credit inputs.
        (new OrderHandleJob($ordinary->trade_no))->handle();
        $this->assertSame(3, (int)$ordinary->fresh()->status);
        $this->assertSame(0, (int)ReservedPlan::where('order_id', $ordinary->id)->firstOrFail()->status);
        $eligible = Order::where('user_id', $user->id)->where('status', 3);
        CustomPlanService::constrainSurplus($eligible, (int)$user->id);
        $this->assertNotContains($ordinary->id, $eligible->pluck('id')->all());
        $this->assertSame(3, (int)$ordinary->fresh()->status, 'exclusion never fabricates an offset or refund');
    }
}
