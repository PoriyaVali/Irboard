<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CardPayment;
use App\Models\Order;
use App\Models\ReservedPlan;
use App\Services\GiftOrderService;
use App\Services\CardPaymentService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

class PurchasedGiftOrderTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $this->createInstallTables(['v2_plan_prices']);
        DB::statement('CREATE UNIQUE INDEX gift_coupon_id ON v2_order (gift_coupon_id)');
    }

    private function auth($user): array
    {
        // Exercise the real User middleware using its authenticated-session
        // cache path; tests never require a live Redis session server.
        $token = 'test-auth-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function buy($user, $plan, array $extra = []): Order
    {
        $res = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/order/save', array_merge([
            'plan_id' => $plan->id, 'period' => 'month_price', 'is_gift' => true,
        ], $extra))->assertOk();
        return Order::where('trade_no', $res->json('data'))->firstOrFail();
    }

    private function paidGift(array $userAttrs = []): array
    {
        $plan = $this->makePlan(['quarter_price' => 270000]);
        $buyer = $this->makeUser($userAttrs);
        $order = $this->buy($buyer, $plan);
        $this->assertTrue((new OrderService($order))->paid('verified-gateway'));
        return [$buyer, $plan, $order->fresh(), Coupon::find($order->fresh()->gift_coupon_id)];
    }

    public function testGiftsUseTheServerTariffAndDoNotConsumeTheBuyersSubscriptionOrVipDiscount()
    {
        $old = $this->makePlan();
        $buyer = $this->makeActiveUser($old, ['balance' => 200000, 'discount' => 90]);
        $plan = $this->makePlan(['month_price' => 150000]);
        $before = $buyer->getAttributes();
        $order = $this->buy($buyer, $plan, ['total_amount' => 1]);
        $this->assertTrue($order->is_gift);
        $this->assertSame(8, (int)$order->type);
        $this->assertSame(150000, (int)$order->balance_amount);
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertEmpty($order->surplus_order_ids);
        $this->assertNull($order->gift_coupon_id);
        $this->assertSame(0, Coupon::count());
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertOk();
        $this->assertSame(1, Coupon::count());
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame(50000, (int)$buyer->fresh()->balance);
        foreach (['plan_id', 'expired_at', 'transfer_enable', 'u', 'd'] as $key) {
            $this->assertSame($before[$key], $buyer->fresh()->getAttributes()[$key], $key);
        }
        $this->assertSame(0, ReservedPlan::count());
        $this->assertFalse(OrderService::isReserved($order));
    }

    public function testDedicatedGiftEndpointForcesGiftSemanticsAndFailsClosedWithoutTheSchema()
    {
        $plan = $this->makePlan();
        $buyer = $this->makeUser(['balance' => 200000]);
        $headers = $this->auth($buyer);
        // The route itself chooses gift semantics, even if the caller omits
        // or falsifies the flag. Older panels have no such creation route.
        $response = $this->withHeaders($headers)->postJson('/api/v1/user/order/saveGift', [
            'plan_id' => $plan->id, 'period' => 'month_price', 'is_gift' => false,
        ])->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertTrue($order->is_gift);
        $this->withHeaders($headers)->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertOk();
        $this->assertNull($buyer->fresh()->plan_id);
        $this->assertSame(1, Coupon::count());
        DB::statement('DROP INDEX gift_coupon_id');
        DB::statement('ALTER TABLE v2_order DROP COLUMN gift_coupon_id');
        $this->withHeaders($headers)->postJson('/api/v1/user/order/saveGift', [
            'plan_id' => $plan->id, 'period' => 'month_price',
        ])->assertStatus(503);
        $this->assertSame(1, Order::count());
        $this->assertSame(100000, (int)$buyer->fresh()->balance);
    }

    public function testNoCodeBeforePaymentAndBothOrderEndpointsScopeItToThePurchaser()
    {
        $plan = $this->makePlan();
        $buyer = $this->makeUser();
        $other = $this->makeUser();
        $order = $this->buy($buyer, $plan, ['use_wallet' => false]);
        $path = '/api/v1/user/order/detail?trade_no=' . $order->trade_no;
        $this->withHeaders($this->auth($buyer))->getJson($path)->assertOk()->assertJsonPath('data.gift_code', null)->assertJsonPath('data.gift_status', 'pending');
        (new OrderService($order))->open(); // a forged/unpaid fulfilment invocation
        $this->assertSame(0, Coupon::count());
        $this->assertSame(0, (int)$order->fresh()->status);
        (new OrderService($order))->paid('card-admin-verified');
        $code = Coupon::firstOrFail()->code;
        $res = $this->withHeaders($this->auth($buyer))->getJson($path)->assertOk()->assertJsonPath('data.gift_code', $code)->assertJsonPath('data.gift_status', 'available');
        $this->assertArrayNotHasKey('gift_coupon_id', $res->json('data'));
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonPath('data.0.gift_code', $code);
        $this->withHeaders($this->auth($other))->getJson($path)->assertStatus(500);
        $this->withHeaders($this->auth($other))->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonCount(0, 'data');
    }

    public function testDuplicateCallbacksAndStaleFulfilmentCopiesCreateExactlyOneCode()
    {
        [, , $order, $coupon] = $this->paidGift();
        $stale = $order->replicate()->setRawAttributes($order->getAttributes());
        $stale->status = 1;
        (new OrderService($stale))->open();
        (new OrderService($stale))->paid('replayed-card-callback');
        GiftOrderService::fulfil($stale);
        $this->assertSame(1, Coupon::count());
        $this->assertSame($coupon->id, (int)$order->fresh()->gift_coupon_id);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
    }

    public function testGiftCouponRequiresItsExactPlanAndPeriodAndCanOnlyRedeemOnce()
    {
        [, $plan, $source, $coupon] = $this->paidGift();
        $recipient = $this->makeUser(['discount' => 90, 'balance' => 5000]);
        $other = $this->makePlan();
        $headers = $this->auth($recipient);
        foreach ([['plan_id' => $other->id, 'period' => 'month_price'], ['plan_id' => $plan->id, 'period' => 'quarter_price'], ['plan_id' => $plan->id]] as $bad) {
            $this->withHeaders($headers)->postJson('/api/v1/user/coupon/check', array_merge(['code' => $coupon->code], $bad))->assertStatus(500);
        }
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->withHeaders($headers)->postJson('/api/v1/user/coupon/check', ['code' => $coupon->code, 'plan_id' => $plan->id, 'period' => 'month_price'])->assertOk()->assertJsonPath('is_gift', true);
        $this->withHeaders($headers)->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertOk();
        $this->assertSame($plan->id, (int)$recipient->fresh()->plan_id);
        $this->assertSame(5000, (int)$recipient->fresh()->balance);
        $this->assertSame(0, (int)$coupon->fresh()->limit_use);
        $this->assertSame('redeemed', GiftOrderService::present($source->fresh())->gift_status);
        $this->withHeaders($headers)->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertStatus(500);
        $secondRecipient = $this->makeUser();
        $this->withHeaders($this->auth($secondRecipient))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertStatus(500);
        $this->assertNull($secondRecipient->fresh()->plan_id);
        $this->assertSame(2, Order::count());
    }

    public function testRecipientWithAnActiveSubscriptionQueuesThePurchasedPeriod()
    {
        [, $plan, , $coupon] = $this->paidGift();
        $old = $this->makePlan();
        $recipient = $this->makeActiveUser($old);
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertOk();
        $this->assertSame($old->id, (int)$recipient->fresh()->plan_id);
        $reserved = ReservedPlan::firstOrFail();
        $this->assertSame($plan->id, (int)$reserved->plan_id);
        $this->assertSame('month_price', $reserved->period);
    }

    public function testOrdinaryCheckoutUsesGiftWithoutVipCashAndCancellationRestoresItOnce()
    {
        [, $plan, , $coupon] = $this->paidGift();
        $recipient = $this->makeUser(['discount' => 90, 'balance' => 5000]);
        $headers = $this->auth($recipient);
        $save = ['plan_id' => $plan->id, 'period' => 'month_price', 'coupon_code' => $coupon->code];
        $res = $this->withHeaders($headers)->postJson('/api/v1/user/order/save', $save)->assertOk();
        $order = Order::where('trade_no', $res->json('data'))->firstOrFail();
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertSame(0, (int)$order->balance_amount);
        $this->assertSame(5000, (int)$recipient->fresh()->balance);
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        // A delayed checkout/callback after cancellation cannot open a gift
        // whose single use has already been released for another recipient.
        $this->assertTrue((new OrderService($order))->paid('late-zero-payment'));
        $this->assertSame(4, (int)$order->fresh()->status);
        $this->assertNull($recipient->fresh()->plan_id);
        $res = $this->withHeaders($headers)->postJson('/api/v1/user/order/save', $save)->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/user/order/checkout', ['trade_no' => $res->json('data')])->assertOk();
        $this->assertSame($plan->id, (int)$recipient->fresh()->plan_id);
        $this->assertSame(0, (int)$coupon->fresh()->limit_use);
    }

    public function testGiftCannotBeBoughtWithACouponOrAsAResetOrDeposit()
    {
        $plan = $this->makePlan(['reset_price' => 2000]);
        $buyer = $this->makeUser(['balance' => 200000]);
        $headers = $this->auth($buyer);
        foreach ([['coupon_code' => 'ANY'], ['period' => 'reset_price'], ['period' => 'deposit', 'plan_id' => 0]] as $bad) {
            $this->withHeaders($headers)->postJson('/api/v1/user/order/save', array_merge(['plan_id' => $plan->id, 'period' => 'month_price', 'is_gift' => true], $bad))->assertStatus(422);
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(200000, (int)$buyer->fresh()->balance);
    }

    public function testCancelledGiftPurchaseRefundsTheWalletWithoutIssuingACode()
    {
        $plan = $this->makePlan();
        $buyer = $this->makeUser(['balance' => 40000]);
        $order = $this->buy($buyer, $plan);
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertTrue((new OrderService($order))->paid('late-paid-gateway-share'));
        $this->assertSame(4, (int)$order->fresh()->status);
        $this->assertSame(100000, (int)$buyer->fresh()->balance);
        $this->assertSame(0, Coupon::count());
        $this->assertSame('refunded', GiftOrderService::present($order->fresh())->gift_status);
    }

    public function testGatewayCallbackCreditsALatePaymentForACancelledMixedWalletGift()
    {
        $plan = $this->makePlan();
        $buyer = $this->makeUser(['balance' => 40000]);
        $order = $this->buy($buyer, $plan);
        $this->assertTrue((new OrderService($order))->cancel());
        $controller = new \App\Http\Controllers\V1\Guest\PaymentController();
        $handle = new \ReflectionMethod($controller, 'handleOrder');
        $handle->setAccessible(true);
        $this->assertTrue($handle->invoke($controller, $order->trade_no, 'late-gateway-payment'));
        $this->assertSame(4, (int)$order->fresh()->status);
        $this->assertSame(100000, (int)$buyer->fresh()->balance);
        $this->assertSame(0, Coupon::count());
        $this->assertTrue($handle->invoke($controller, $order->trade_no, 'duplicate-gateway-payment'));
        $this->assertSame(100000, (int)$buyer->fresh()->balance);
        $render = new \ReflectionMethod($controller, 'renderAppResult');
        $render->setAccessible(true);
        $response = $render->invoke($controller, true, '', $order->fresh());
        $this->assertStringContainsString('کیف پول', $response->getContent());
        $this->assertStringNotContainsString('کد هدیه', $response->getContent());
    }

    public function testCapabilityFailsClosedUntilTheOrderSchemaIsInstalled()
    {
        $buyer = $this->makeUser();
        $plan = $this->makePlan();
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/plan/fetch')->assertOk()->assertJsonPath('gift_purchase_enabled', true);
        DB::statement('DROP INDEX gift_coupon_id');
        DB::statement('ALTER TABLE v2_order DROP COLUMN gift_coupon_id');
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/plan/fetch')->assertOk()->assertJsonPath('gift_purchase_enabled', false);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price', 'is_gift' => true])->assertStatus(503);
        $this->assertSame(0, Order::count());
    }

    public function testGiftCreationAlsoFailsClosedWithoutThePersistentReceiptColumn()
    {
        $plan = $this->makePlan();
        $buyer = $this->makeUser(['balance' => 200000]);
        DB::statement('ALTER TABLE v2_order DROP COLUMN gift_code_snapshot');
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/plan/fetch')
            ->assertOk()->assertJsonPath('gift_purchase_enabled', false);
        $this->withHeaders($this->auth($buyer))->postJson('/api/v1/user/order/saveGift', [
            'plan_id' => $plan->id, 'period' => 'month_price',
        ])->assertStatus(503);
        $this->assertSame(0, Order::count());
        $this->assertSame(200000, (int)$buyer->fresh()->balance);
    }

    public function testExistingGiftCouponKeepsItsMoneyProtectionsIfTheReceiptSchemaIsIncomplete()
    {
        [, $plan, , $coupon] = $this->paidGift();
        DB::statement('ALTER TABLE v2_order DROP COLUMN gift_code_snapshot');
        $old = $this->makePlan(['onetime_price' => 900000]);
        $recipient = $this->makeUser(['discount' => 90, 'balance' => 5000, 'plan_id' => $old->id, 'expired_at' => null, 'transfer_enable' => 100 * 1073741824]);
        $this->makeOrder($recipient, ['plan_id' => $old->id, 'period' => 'onetime_price', 'status' => 3, 'total_amount' => 900000]);
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/coupon/check', [
            'plan_id' => $plan->id, 'period' => 'month_price', 'code' => $coupon->code,
        ])->assertOk()->assertJsonPath('is_gift', true);
        $response = $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id, 'period' => 'month_price', 'coupon_code' => $coupon->code,
        ])->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertEmpty($order->refund_amount);
        $this->assertEmpty($order->surplus_order_ids);
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertOk();
        $this->assertSame(5000, (int)$recipient->fresh()->balance);
    }

    private function cardPayment(Order $order): CardPayment
    {
        return CardPayment::create([
            'order_id' => $order->id, 'trade_no' => $order->trade_no,
            'user_id' => $order->user_id, 'expected_amount' => $order->total_amount,
            'card_number' => '6037000000000000', 'card_holder' => 'test',
            'amount_fingerprint' => 'test', 'status' => CardPayment::STATUS_CLAIMED,
            'expires_at' => time() + 1800,
        ]);
    }

    public function testCardApprovalFulfilsTheGiftOnceAndPartialPaymentDoesNotIssueOne()
    {
        $plan = $this->makePlan();
        $buyer = $this->makeUser();
        $order = $this->buy($buyer, $plan);
        $card = $this->cardPayment($order);
        $stale = CardPayment::find($card->id);
        $service = new CardPaymentService();
        $this->assertTrue($service->verifyFull($card, 1)['success']);
        $this->assertFalse($service->verifyFull($stale, 1)['success']);
        $this->assertSame(1, Coupon::count());
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertNull($buyer->fresh()->plan_id);

        $second = $this->buy($buyer, $plan);
        $partial = $this->cardPayment($second);
        $this->assertTrue($service->verifyWithDifferentAmount($partial, 60000, 1)['success']);
        $this->assertSame(1, Coupon::count());
        $this->assertSame(2, (int)$second->fresh()->status);
        $this->assertSame(60000, (int)$buyer->fresh()->balance);
        $this->assertNull($second->fresh()->gift_coupon_id);
    }

    public function testFailedFulfilmentRollsBackTheCodeAndCanBeRetried()
    {
        $plan = $this->makePlan();
        $buyer = $this->makeUser();
        $order = $this->buy($buyer, $plan);
        // Stand in for a paid row whose worker has not run yet.
        $order->status = 1;
        $order->save();
        DB::statement("CREATE TRIGGER fail_gift_fulfilment BEFORE UPDATE ON v2_order WHEN NEW.status = 3 BEGIN SELECT RAISE(ABORT, 'test fulfilment failure'); END");
        try {
            GiftOrderService::fulfil($order);
            $this->fail('Expected transactional fulfilment to fail');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('test fulfilment failure', $e->getMessage());
        }
        $this->assertSame(0, Coupon::count());
        $this->assertSame(1, (int)$order->fresh()->status);
        $this->assertSame(0, DB::transactionLevel());
        DB::statement('DROP TRIGGER fail_gift_fulfilment');
        GiftOrderService::fulfil($order);
        $this->assertSame(1, Coupon::count());
        $this->assertSame(3, (int)$order->fresh()->status);
    }

    public function testFailedRecipientFulfilmentRollsBackTheGiftUseAndCanBeRetried()
    {
        [, $plan, , $coupon] = $this->paidGift();
        $recipient = $this->makeUser();
        DB::statement("CREATE TRIGGER fail_recipient_fulfilment BEFORE UPDATE ON v2_order WHEN NEW.status = 3 AND NEW.is_gift = 0 BEGIN SELECT RAISE(ABORT, 'test redemption failure'); END");
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertStatus(500);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertNull($recipient->fresh()->plan_id);
        $this->assertSame(0, DB::transactionLevel());
        DB::statement('DROP TRIGGER fail_recipient_fulfilment');
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/redeemgiftcard', ['giftcard' => $coupon->code])->assertOk();
        $this->assertSame($plan->id, (int)$recipient->fresh()->plan_id);
        $this->assertSame(0, (int)$coupon->fresh()->limit_use);
    }

    public function testGiftRedemptionNeverTurnsAnExistingLifetimePlanIntoCash()
    {
        [, $plan, , $coupon] = $this->paidGift();
        $old = $this->makePlan(['onetime_price' => 900000]);
        $recipient = $this->makeUser(['plan_id' => $old->id, 'expired_at' => null, 'transfer_enable' => 100 * 1073741824]);
        $previous = $this->makeOrder($recipient, ['plan_id' => $old->id, 'period' => 'onetime_price', 'total_amount' => 900000, 'status' => 3]);
        $res = $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price', 'coupon_code' => $coupon->code])->assertOk();
        $order = Order::where('trade_no', $res->json('data'))->firstOrFail();
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertEmpty($order->refund_amount);
        $this->assertEmpty($order->surplus_order_ids);
        $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertOk();
        $this->assertSame(0, (int)$recipient->fresh()->balance);
        $this->assertSame(3, (int)$previous->fresh()->status);
    }

    public function testPaymentResultExplainsGiftDeliveryWithoutActivatingTheBuyersPlan()
    {
        [$buyer, , $order] = $this->paidGift();
        $controller = new \App\Http\Controllers\V1\Guest\PaymentController();
        $render = new \ReflectionMethod($controller, 'renderAppResult');
        $render->setAccessible(true);
        $response = $render->invoke($controller, true, '', $order);
        $this->assertStringContainsString('کد هدیه', $response->getContent());
        $this->assertStringContainsString('&amp;is_gift=1', $response->getContent());
        preg_match('/window\.location\.href=("[^"]*");/', $response->getContent(), $redirect);
        parse_str(parse_url(json_decode($redirect[1], true), PHP_URL_QUERY), $query);
        $this->assertSame($order->trade_no, $query['trade_no'] ?? null);
        $this->assertSame('1', $query['is_gift'] ?? null);
        $this->assertStringNotContainsString('اشتراک شما فعال شد!', $response->getContent());
        $this->assertNull($buyer->fresh()->plan_id);
    }

    public function testAnOldPurchasedGiftIsExcludedFromTheBuyersFuturePlanChangeCredit()
    {
        $old = $this->makePlan(['onetime_price' => 900000]);
        $new = $this->makePlan(['month_price' => 120000]);
        $buyer = $this->makeUser(['plan_id' => $old->id, 'expired_at' => null, 'transfer_enable' => 100 * 1073741824, 'u' => 50 * 1073741824]);
        $oldOrder = $this->makeOrder($buyer, ['plan_id' => $old->id, 'period' => 'onetime_price', 'total_amount' => 900000, 'status' => 3]);
        $giftOrder = $this->makeOrder($buyer, ['is_gift' => true, 'type' => 8, 'plan_id' => $old->id, 'period' => 'onetime_price', 'total_amount' => 999999, 'status' => 3]);
        $change = new Order(['user_id' => $buyer->id, 'plan_id' => $new->id, 'period' => 'month_price', 'total_amount' => 120000]);
        (new OrderService($change))->setOrderType($buyer);
        $this->assertSame(450000.0, (float)$change->surplus_amount);
        $this->assertSame([$oldOrder->id], $change->surplus_order_ids);
        $this->assertNotContains($giftOrder->id, $change->surplus_order_ids);
    }

    public function testTheBuyerCanStillReadTheGiftCodeIfAnAdministratorRemovesThePlan()
    {
        [$buyer, $plan, $order, $coupon] = $this->paidGift();
        DB::table('v2_plan')->where('id', $plan->id)->delete();
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/order/detail?trade_no=' . $order->trade_no)
            ->assertOk()->assertJsonPath('data.gift_code', $coupon->code)->assertJsonPath('data.plan', null);
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/order/fetch')
            ->assertOk()->assertJsonPath('data.0.gift_code', $coupon->code);
    }

    public function testThePaidGiftReceiptKeepsTheCodeAfterTheCouponIsDeleted()
    {
        [$buyer, , $order, $coupon] = $this->paidGift();
        $code = $coupon->code;
        $coupon->delete();
        $detail = $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/order/detail?trade_no=' . $order->trade_no)
            ->assertOk()->assertJsonPath('data.gift_code', $code)->assertJsonPath('data.gift_code_type', 'coupon');
        $this->assertArrayNotHasKey('gift_code_snapshot', $detail->json('data'));
        $this->withHeaders($this->auth($buyer))->getJson('/api/v1/user/order/fetch')
            ->assertOk()->assertJsonPath('data.0.gift_code', $code);
        (new OrderService($order))->paid('replay-after-coupon-deletion');
        GiftOrderService::fulfil($order);
        $this->assertSame(0, Coupon::count());
    }

    public function testBotGiftCouponClearsAnyPriorPlanChangeCashCredit()
    {
        [, $plan, , $coupon] = $this->paidGift();
        config(['v2board.telegram_bot_token' => 'test-token']);
        $old = $this->makePlan(['onetime_price' => 900000]);
        $recipient = $this->makeUser(['telegram_id' => 991601, 'plan_id' => $old->id, 'expired_at' => null, 'transfer_enable' => 100 * 1073741824, 'u' => 50 * 1073741824]);
        $previous = $this->makeOrder($recipient, ['plan_id' => $old->id, 'period' => 'onetime_price', 'total_amount' => 900000, 'status' => 3]);
        $res = $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price'])->assertOk();
        $order = Order::where('trade_no', $res->json('data'))->firstOrFail();
        $this->assertSame(350000, (int)$order->refund_amount, 'ordinary plan-change pricing before applying gift');
        $recipient->update(['bot_step' => 'enter_coupon', 'bot_data' => json_encode(['trade_no' => $order->trade_no])]);
        (new \App\Services\Telegram\TelegramBotService())->handleWebhook(['message' => [
            'text' => $coupon->code, 'chat' => ['id' => 991601], 'from' => ['id' => 991601],
        ]]);
        $this->assertSame(0, (int)$recipient->fresh()->balance);
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertEmpty($order->fresh()->refund_amount);
        $this->assertEmpty($order->fresh()->surplus_order_ids);
        $this->assertSame(3, (int)$previous->fresh()->status);
        $this->assertSame(0, (int)$coupon->fresh()->limit_use);
    }

    public function testBotGiftCannotEraseAnOrderWhoseCardPaymentAlreadyAwaitsApproval()
    {
        [, $plan, , $coupon] = $this->paidGift();
        config(['v2board.telegram_bot_token' => 'test-token']);
        $recipient = $this->makeUser(['telegram_id' => 991602]);
        $response = $this->withHeaders($this->auth($recipient))->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id, 'period' => 'month_price', 'use_wallet' => false,
        ])->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $card = $this->cardPayment($order);
        $recipient->update(['bot_step' => 'enter_coupon', 'bot_data' => json_encode(['trade_no' => $order->trade_no])]);
        (new \App\Services\Telegram\TelegramBotService())->handleWebhook(['message' => [
            'text' => $coupon->code, 'chat' => ['id' => 991602], 'from' => ['id' => 991602],
        ]]);
        $this->assertSame(0, (int)$order->fresh()->status);
        $this->assertSame(100000, (int)$order->fresh()->total_amount);
        $this->assertNull($order->fresh()->coupon_id);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertNull($recipient->fresh()->plan_id);
        $this->assertTrue((new CardPaymentService())->verifyFull($card, 1)['success']);
        $this->assertSame($plan->id, (int)$recipient->fresh()->plan_id);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
    }
}
