<?php

namespace Tests\Feature;

use App\Models\BotSetting;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ReservedPlan;
use App\Services\CouponService;
use App\Services\CustomPlanService;
use App\Services\CustomPlanSettingsService;
use App\Services\GiftOrderService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

class CustomPlanGiftOrderTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_plan_prices']);
        DB::statement('CREATE UNIQUE INDEX custom_key ON v2_plan (custom_key)');
        DB::statement('CREATE UNIQUE INDEX custom_trade_no ON v2_order (trade_no)');
        DB::statement('CREATE UNIQUE INDEX gift_coupon_id ON v2_order (gift_coupon_id)');
        DB::statement('CREATE UNIQUE INDEX settings_key ON v2_bot_settings ("key")');
        config(['app.key' => 'base64:' . base64_encode(str_repeat('t', 32))]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    private function auth($user): array
    {
        $token = 'custom-gift-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function tariff(): Plan
    {
        $plan = $this->makePlan(['transfer_enable' => 35, 'reset_traffic_method' => 2, 'month_price' => 89000]);
        DB::table('v2_plan_prices')->insert(['plan_id' => $plan->id, 'month_price_usd' => 1.49]);
        BotSetting::set(CustomPlanSettingsService::KEY, json_encode(['version' => 1, 'generation' => 'explicit-gift-test-approval',
            'configuration' => ['enabled' => true, 'classifications' => [['plan_id' => (int)$plan->id, 'quota_type' => 'finite_total']],
                'references' => [['plan_id' => (int)$plan->id, 'period' => 'month_price']]]]));
        return $plan;
    }

    private function quote($user, array $extra = []): array
    {
        return $this->withHeaders($this->auth($user))->postJson('/api/v1/user/plan/custom/quote', array_merge([
            'volume_gb' => 73, 'duration_days' => 17, 'pricing_revision' => CustomPlanService::catalogue()['pricing_revision'],
            'is_gift' => true], $extra))->assertOk()->json('data');
    }

    private function saveGift($buyer, array $quote, array $extra = []): Order
    {
        $response = $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustomGift', array_merge([
            'quote_token' => $quote['quote_token']], $extra))->assertOk();
        return Order::where('trade_no', $response->json('data'))->firstOrFail();
    }

    private function paidGift(array $buyerAttrs = []): array
    {
        $source = $this->tariff();
        $buyer = $this->makeUser($buyerAttrs);
        $quote = $this->quote($buyer);
        $order = $this->saveGift($buyer, $quote);
        $this->assertTrue((new OrderService($order))->paid('verified-custom-gift'));
        return [$source, $buyer, $quote, $order->fresh(), Coupon::findOrFail($order->fresh()->gift_coupon_id)];
    }

    public function testGiftQuoteUsesRawTariffWithoutVipOrCouponAndHasNoFinancialSideEffects()
    {
        $old = $this->makePlan();
        $buyer = $this->makeActiveUser($old, ['balance' => 500000, 'discount' => 90]);
        $this->tariff();
        $before = $buyer->getAttributes();
        $personal = $this->quote($buyer, ['is_gift' => false]);
        $gift = $this->quote($buyer);
        $this->assertTrue(CustomPlanService::catalogue()['supports_custom_gift']);
        $this->assertTrue($gift['is_gift']);
        $this->assertSame('gift', $gift['purpose']);
        $this->assertFalse($gift['will_reserve']);
        $this->assertSame($gift['tariff_amount'], $gift['total_amount']);
        $this->assertSame(0, $gift['discount_amount']);
        $this->assertLessThan($gift['total_amount'], $personal['total_amount']);
        $this->assertSame($before, $buyer->fresh()->getAttributes());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Coupon::count());
    }

    public function testCouponCannotDiscountAQuoteOrSaveForACustomGift()
    {
        $this->tariff();
        $buyer = $this->makeUser(['balance' => 500000]);
        $coupon = Coupon::create(['code' => 'PERSONAL-ONLY', 'name' => 'personal', 'type' => 2, 'value' => 100, 'show' => 1,
            'limit_use' => 1, 'started_at' => time() - 10, 'ended_at' => time() + 86400]);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/plan/custom/quote', [
            'volume_gb' => 73, 'duration_days' => 17, 'pricing_revision' => CustomPlanService::catalogue()['pricing_revision'],
            'is_gift' => true, 'coupon_code' => $coupon->code])->assertStatus(422);
        $quote = $this->quote($buyer);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustomGift', [
            'quote_token' => $quote['quote_token'], 'coupon_code' => $coupon->code])->assertStatus(409);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertSame(500000, (int)$buyer->fresh()->balance);
        $this->assertSame(0, Order::count());
    }

    public function testDedicatedRoutesCannotFlipPersonalAndGiftQuotePurpose()
    {
        $this->tariff();
        $buyer = $this->makeUser(['balance' => 500000]);
        $personal = $this->quote($buyer, ['is_gift' => false]);
        $gift = $this->quote($buyer);
        foreach ([['saveCustomGift', $personal], ['saveCustom', $gift]] as [$route, $quote]) {
            $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/' . $route, ['quote_token' => $quote['quote_token'], 'is_gift' => false])
                ->assertStatus(422)->assertJsonPath('code', 'custom_invalid_quote');
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(500000, (int)$buyer->fresh()->balance);
        $saved = $this->saveGift($buyer, $gift, ['is_gift' => false]);
        $this->assertTrue($saved->is_gift);
        $this->assertSame(8, (int)$saved->type);
    }

    public function testGiftPurchaseNeverActivatesOrReservesOnBuyerAndPaymentIssuesOne256BitCode()
    {
        $old = $this->makePlan();
        $buyer = $this->makeActiveUser($old, ['balance' => 500000, 'discount' => 90, 'u' => 123456]);
        $this->tariff();
        $before = $buyer->getAttributes();
        $quote = $this->quote($buyer);
        $order = $this->saveGift($buyer, $quote, ['total_amount' => 1, 'volume_gb' => 1024, 'duration_days' => 365]);
        $this->assertSame($quote['tariff_amount'], (int)$order->balance_amount);
        $this->assertNull($order->coupon_id);
        $this->assertEmpty($order->surplus_amount);
        $this->assertEmpty($order->surplus_order_ids);
        $this->assertEmpty($order->refund_amount);
        $this->assertSame(73, $order->custom_snapshot['volume_gb']);
        $this->assertSame(17, $order->custom_snapshot['duration_days']);
        (new OrderService($order))->open();
        $this->assertSame(0, Coupon::count());
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertOk();
        $order->refresh();
        $coupon = Coupon::findOrFail($order->gift_coupon_id);
        $this->assertSame(64, strlen($coupon->code), 'Existing receipt VARCHAR(64) stores the full entropy');
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $coupon->code);
        $this->assertSame($coupon->code, $order->gift_code_snapshot);
        $this->assertSame(1, (int)$coupon->limit_use);
        $this->assertSame(['custom_price'], $coupon->limit_period);
        $this->assertSame([(string)$order->plan_id], $coupon->limit_plan_ids);
        $this->assertFalse(OrderService::isReserved($order));
        $this->assertSame(0, ReservedPlan::count());
        foreach (['plan_id', 'group_id', 'expired_at', 'transfer_enable', 'u', 'd', 'speed_limit', 'device_limit'] as $key) {
            $this->assertSame($before[$key], $buyer->fresh()->getAttributes()[$key], $key);
        }
        $this->assertArrayNotHasKey('activated_at', $order->custom_snapshot);
    }

    public function testGiftReceiptIsPaidOnlyOwnerScopedAndPersistsAfterCouponDeletion()
    {
        $this->tariff();
        $buyer = $this->makeUser();
        $other = $this->makeUser();
        $order = $this->saveGift($buyer, $this->quote($buyer));
        $path = '/api/v1/user/order/detail?trade_no=' . $order->trade_no;
        $this->withHeaders($this->auth($buyer))->getJson($path)->assertOk()->assertJsonPath('data.gift_code', null)->assertJsonPath('data.is_custom', true);
        (new OrderService($order))->paid('verified-gift');
        $code = $order->fresh()->gift_code_snapshot;
        $receipt = $this->withHeaders($this->auth($buyer))->getJson($path)->assertOk()
            ->assertJsonPath('data.gift_code', $code)->assertJsonPath('data.is_gift', true)->assertJsonPath('data.custom_volume_gb', 73)->json('data');
        foreach (['gift_code_snapshot', 'gift_coupon_id', 'custom_snapshot'] as $hidden) $this->assertArrayNotHasKey($hidden, $receipt);
        $this->withHeaders($this->auth($other))->getJson($path)->assertStatus(500);
        $this->withHeaders($this->auth($other))->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonCount(0, 'data');
        Coupon::findOrFail($order->fresh()->gift_coupon_id)->delete();
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonPath('data.0.gift_code', $code);
    }

    public function testSaveAndCallbackReplaysIssueOneCodeAndDebitWalletOnce()
    {
        $this->tariff();
        $buyer = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($buyer);
        $order = $this->saveGift($buyer, $quote);
        $this->assertSame($order->id, $this->saveGift($buyer, $quote)->id);
        $service = new OrderService($order);
        $this->assertTrue($service->paid('first'));
        $this->assertTrue($service->paid('duplicate'));
        $service->open();
        GiftOrderService::fulfil($order);
        $this->assertSame(1, Coupon::count());
        $this->assertSame(1, Order::count());
        $this->assertSame(500000 - $quote['tariff_amount'], (int)$buyer->fresh()->balance);
        $this->assertSame($order->id, $this->saveGift($buyer, $quote)->id);
    }

    public function testGiftQuoteIsBoundToOwnerAndExpiryAndWithdrawnReferencesBlockNewGiftSaves()
    {
        $this->tariff();
        $buyer = $this->makeUser(['balance' => 500000]);
        $other = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($buyer);
        $this->withHeaders($this->auth($other))->postJson('/api/v1/user/order/saveCustomGift', ['quote_token' => $quote['quote_token']])->assertStatus(422);
        $payload = json_decode(Crypt::decryptString($quote['quote_token']), true);
        $payload['expires_at'] = time() - 1;
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustomGift', [
            'quote_token' => Crypt::encryptString(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION))])->assertStatus(409)->assertJsonPath('code', 'custom_quote_expired');
        BotSetting::where('key', CustomPlanSettingsService::KEY)->delete();
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustomGift', ['quote_token' => $quote['quote_token']])
            ->assertStatus(409)->assertJsonPath('code', 'custom_price_changed');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Coupon::count());
        $this->assertSame(500000, (int)$buyer->fresh()->balance);
    }

    public function testPaidGiftRetainsReceiptAndExactRedemptionAfterPriceAndApprovalWithdrawal()
    {
        [$source, $buyer, $quote, $gift, $coupon] = $this->paidGift(['balance' => 500000]);
        BotSetting::where('key', CustomPlanSettingsService::KEY)->delete();
        $source->show = 0;
        $source->reset_traffic_method = 0;
        $source->month_price = 999999;
        $source->save();
        $buyerBalance = $buyer->fresh()->balance;
        $this->assertSame($gift->id, $this->saveGift($buyer, $quote)->id);
        $recipient = $this->makeUser(['discount' => 100, 'balance' => 12345]);
        $before = time();
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertOk()->assertJsonPath('type', 5)
            ->assertJsonPath('redemption.status', 'active')->assertJsonPath('redemption.is_custom', true)
            ->assertJsonPath('redemption.volume_gb', 73)->assertJsonPath('redemption.duration_days', 17);
        $recipient->refresh();
        $this->assertSame(73 * 1073741824, (int)$recipient->transfer_enable);
        $this->assertGreaterThanOrEqual($before + 17 * 86400, (int)$recipient->expired_at);
        $this->assertLessThanOrEqual(time() + 17 * 86400, (int)$recipient->expired_at);
        $this->assertSame($gift->custom_snapshot['access']['group_id'], (int)$recipient->group_id);
        $this->assertSame(12345, (int)$recipient->balance);
        $this->assertSame($buyerBalance, $buyer->fresh()->balance);
        $this->assertNull($buyer->fresh()->plan_id);
        $this->assertSame(0, (int)$coupon->fresh()->limit_use);
        $redemption = Order::where('coupon_id', $coupon->id)->firstOrFail();
        $this->assertFalse((bool)$redemption->is_gift);
        $this->assertSame(0, (int)$redemption->total_amount);
        $this->assertEmpty($redemption->refund_amount);
        $this->assertEmpty($redemption->surplus_order_ids);
        $this->assertSame(CustomPlanService::commercialSnapshot($gift), CustomPlanService::commercialSnapshot($redemption));
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonPath('data.0.gift_status', 'redeemed');
    }

    public function testOneUseGiftCannotRedeemTwiceOrBeUsedAsAnArbitraryTailoredCoupon()
    {
        [, , , $gift, $coupon] = $this->paidGift();
        $recipient = $this->makeUser(['balance' => 12345]);
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/plan/custom/quote', [
            'volume_gb' => 1024, 'duration_days' => 365, 'pricing_revision' => CustomPlanService::catalogue()['pricing_revision'],
            'coupon_code' => $coupon->code])->assertStatus(422);
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/order/save', [
            'plan_id' => $gift->plan_id, 'period' => 'custom_price', 'coupon_code' => $coupon->code])->assertStatus(422);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $first = GiftOrderService::redeem($recipient->id, $coupon->code);
        $expiry = $recipient->fresh()->expired_at;
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertStatus(500);
        $other = $this->makeUser();
        $this->withHeaders($this->auth($other))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertStatus(500);
        $this->assertSame($expiry, $recipient->fresh()->expired_at);
        $this->assertSame(12345, (int)$recipient->fresh()->balance);
        $this->assertSame(2, Order::count());
        $this->assertSame(3, (int)$first->fresh()->status);
    }

    public function testRedemptionCopiesOnlyCommercialFieldsAndKeepsRecipientsOwnDeliveryHistory()
    {
        [, $buyer, , $gift, $coupon] = $this->paidGift();
        $snapshot = $gift->custom_snapshot;
        $snapshot['activated_at'] = 123;
        $snapshot['activated_at_microseconds'] = 123000000;
        $snapshot['boundary_order_id'] = 999999;
        $snapshot['pending_paid_order_ids'] = [999998];
        $snapshot['consumed_same_second_order_ids'] = [999997];
        $gift->custom_snapshot = $snapshot;
        $gift->saveOrFail();
        $recipient = $this->makeUser();
        $ordinary = $this->makeOrder($recipient, ['plan_id' => 1, 'status' => 1]);
        $redemption = GiftOrderService::redeem($recipient->id, $coupon->code)->fresh();
        $received = $redemption->custom_snapshot;
        $this->assertGreaterThanOrEqual(time() - 1, $received['activated_at']);
        $this->assertNotSame(999999, $received['boundary_order_id']);
        $this->assertContains($ordinary->id, $received['pending_paid_order_ids']);
        $this->assertNotContains(999998, $received['pending_paid_order_ids']);
        $this->assertNotContains(999997, $received['consumed_same_second_order_ids']);
        $this->assertNull($buyer->fresh()->plan_id);
    }

    public function testActiveRecipientQueuesExactCustomEntitlementWithoutSurplusOrWalletCredit()
    {
        [, , , $gift, $coupon] = $this->paidGift();
        $old = $this->makePlan();
        $recipient = $this->makeActiveUser($old, ['balance' => 12345]);
        $before = $recipient->getAttributes();
        $redemption = GiftOrderService::redeem($recipient->id, $coupon->code)->fresh();
        $this->assertSame($before, $recipient->fresh()->getAttributes());
        $this->assertSame(1, ReservedPlan::count());
        $this->assertArrayNotHasKey('activated_at', $redemption->custom_snapshot);
        $this->assertSame(CustomPlanService::commercialSnapshot($gift), $redemption->custom_snapshot);
        $recipient->expired_at = time() - 1;
        $recipient->save();
        $this->artisan('reserved:activate')->assertExitCode(0);
        $this->assertSame(73 * 1073741824, (int)$recipient->fresh()->transfer_enable);
        $this->assertSame(12345, (int)$recipient->fresh()->balance);
        $this->assertSame(1, (int)ReservedPlan::first()->status);
        $this->assertEmpty($redemption->surplus_amount);
        $this->assertEmpty($redemption->refund_amount);
    }

    public function testReservationLimitRejectsRedemptionWithoutUsingGiftButBuyerCanStillPurchaseGifts()
    {
        $old = $this->makePlan();
        $buyer = $this->makeActiveUser($old, ['balance' => 500000]);
        $this->tariff();
        foreach (range(1, 10) as $number) {
            $order = $this->makeOrder($buyer, ['plan_id' => $old->id, 'status' => 3]);
            ReservedPlan::create(['user_id' => $buyer->id, 'order_id' => $order->id, 'plan_id' => $old->id, 'period' => 'month_price',
                'status' => 0, 'created_at' => time(), 'updated_at' => time()]);
        }
        $gift = $this->saveGift($buyer, $this->quote($buyer));
        (new OrderService($gift))->paid('gift-with-full-queue');
        $coupon = Coupon::findOrFail($gift->fresh()->gift_coupon_id);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertStatus(500);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertSame(10, ReservedPlan::count());
        $other = $this->makeUser();
        $this->withHeaders($this->auth($other))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertOk();
        $this->assertSame(73 * 1073741824, (int)$other->fresh()->transfer_enable);
    }

    public function testCancellationAndLatePartialPaymentRefundOnlyActualPaidMoneyAndIssueNoCode()
    {
        $this->tariff();
        $buyer = $this->makeUser(['balance' => 10000]);
        $quote = $this->quote($buyer);
        $gift = $this->saveGift($buyer, $quote);
        $gateway = (int)$gift->total_amount;
        $this->assertGreaterThan(0, $gateway);
        $service = new OrderService($gift);
        $this->assertTrue($service->cancel());
        $this->assertTrue($service->cancel());
        $this->assertSame(10000, (int)$buyer->fresh()->balance);
        $this->assertTrue($service->paid('late-payment'));
        $this->assertTrue($service->paid('duplicate-late-payment'));
        $this->assertSame(10000 + $gateway, (int)$buyer->fresh()->balance);
        $this->assertSame(4, (int)$gift->fresh()->status);
        $this->assertSame(0, Coupon::count());
        $this->assertNull($buyer->fresh()->plan_id);
    }

    public function testCancelledGatewayOnlyGiftPaidLateIssuesOnePurchasedEntitlement()
    {
        $this->tariff();
        $buyer = $this->makeUser(['balance' => 10000]);
        $gift = $this->saveGift($buyer, $this->quote($buyer), ['use_wallet' => false]);
        $service = new OrderService($gift);
        $this->assertTrue($service->cancel());
        $this->assertTrue($service->paid('late-gateway-only'));
        $this->assertTrue($service->paid('duplicate'));
        $this->assertSame(1, Coupon::count());
        $this->assertSame(3, (int)$gift->fresh()->status);
        $this->assertSame(10000, (int)$buyer->fresh()->balance);
        $this->assertNull($buyer->fresh()->plan_id);
    }

    public function testGiftCapabilityAndDedicatedCreationFailClosedWithPartialGiftSchema()
    {
        $this->tariff();
        $buyer = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($buyer);
        DB::statement('ALTER TABLE v2_order DROP COLUMN gift_code_snapshot');
        $this->assertFalse(CustomPlanService::catalogue()['supports_custom_gift']);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/plan/custom/quote', [
            'volume_gb' => 73, 'duration_days' => 17, 'pricing_revision' => CustomPlanService::catalogue()['pricing_revision'], 'is_gift' => true])
            ->assertStatus(503)->assertJsonPath('code', 'custom_gift_unavailable');
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustomGift', ['quote_token' => $quote['quote_token']])->assertStatus(503);
        $this->assertSame(0, Order::count());
        $this->assertSame(500000, (int)$buyer->fresh()->balance);
        $personal = $this->quote($buyer, ['is_gift' => false]);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $personal['quote_token']])->assertOk();
    }

    public function testStaffCannotSpendWalletOnCustomGiftAndOrdinaryGiftFormatIsPreserved()
    {
        $this->tariff();
        $staff = $this->makeUser(['balance' => 500000, 'is_staff' => 1, 'discount' => 100]);
        $quote = $this->quote($staff);
        $this->assertFalse($quote['wallet_eligible']);
        $this->assertSame(0, $quote['balance_amount']);
        $this->assertSame($quote['tariff_amount'], $quote['payable_amount']);
        $gift = $this->saveGift($staff, $quote);
        $this->assertSame($quote['tariff_amount'], (int)$gift->total_amount);
        $this->assertSame(500000, (int)$staff->fresh()->balance);
        $normal = $this->makeOrder($this->makeUser(), ['is_gift' => 1, 'type' => 8, 'plan_id' => 1, 'status' => 1]);
        GiftOrderService::fulfil($normal);
        $this->assertMatchesRegularExpression('/^GIFT-[0-9A-F]{32}$/', $normal->fresh()->gift_code_snapshot);
    }

    public function testAuthenticatedQuoteTamperingAndMissingGiftIntentAreRejected()
    {
        $this->tariff();
        $buyer = $this->makeUser(['balance' => 500000]);
        $quote = $this->quote($buyer);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustomGift', ['quote_token' => $quote['quote_token'] . 'x'])->assertStatus(422);
        $personal = $this->quote($buyer, ['is_gift' => false]);
        $oldPayload = json_decode(Crypt::decryptString($personal['quote_token']), true);
        unset($oldPayload['is_gift']);
        $token = Crypt::encryptString(json_encode($oldPayload, JSON_PRESERVE_ZERO_FRACTION));
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustomGift', ['quote_token' => $token])->assertStatus(422);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveCustom', ['quote_token' => $token])->assertOk();
        $this->assertSame(1, Order::count());
        $this->assertFalse((bool)Order::first()->is_gift);
    }

    public function testPurchasedCustomCouponRejectsAnOrderWithAChangedCommercialSnapshot()
    {
        [, , , $gift, $coupon] = $this->paidGift();
        $recipient = $this->makeUser();
        $snapshot = CustomPlanService::commercialSnapshot($gift);
        $snapshot['volume_gb'] = 1024;
        $order = new Order(['user_id' => $recipient->id, 'plan_id' => $gift->plan_id, 'period' => 'custom_price',
            'total_amount' => 0, 'custom_snapshot' => $snapshot]);
        try {
            (new CouponService($coupon->code))->use($order);
            $this->fail('A purchased gift cannot cover a different custom allowance');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertSame(1, Order::count());
        $this->assertNull($recipient->fresh()->plan_id);
    }

    public function testCouponPersistenceFailureRollsBackRedemptionWithoutFreeEntitlement()
    {
        [, , , , $coupon] = $this->paidGift();
        $recipient = $this->makeUser(['balance' => 12345]);
        $dispatcher = Coupon::getEventDispatcher();
        Coupon::setEventDispatcher(clone $dispatcher);
        Coupon::saving(function ($row) { if ($row->limit_use === 0) return false; });
        try {
            GiftOrderService::redeem($recipient->id, $coupon->code);
            $this->fail('A failed coupon debit must not deliver a free entitlement');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        } finally {
            Coupon::setEventDispatcher($dispatcher);
        }
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertSame(1, Order::count());
        $this->assertNull($recipient->fresh()->plan_id);
        $this->assertSame(12345, (int)$recipient->fresh()->balance);
        $this->assertSame(0, DB::transactionLevel());
    }
    public function testCustomGiftEndpointReportsExactReservedEntitlement()
    {
        [, , , $gift, $coupon] = $this->paidGift();
        $recipient = $this->makeActiveUser($this->makePlan());
        $response = $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])
            ->assertOk()->assertJsonPath('redemption.status', 'reserved')->assertJsonPath('redemption.is_custom', true)
            ->assertJsonPath('redemption.volume_gb', 73)->assertJsonPath('redemption.duration_days', 17);
        $this->assertSame(1, ReservedPlan::where('user_id', $recipient->id)->count());
        $this->assertSame(['status', 'plan_name', 'period', 'is_custom', 'volume_gb', 'duration_days'], array_keys($response->json('redemption')));
        $this->assertArrayNotHasKey('custom_snapshot', $response->json('redemption'));
        $this->assertArrayNotHasKey('gift_code', $response->json('redemption'));
    }

}
