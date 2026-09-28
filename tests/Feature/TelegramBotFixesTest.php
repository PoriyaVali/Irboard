<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * The Telegram bot's purchase path: buying, coupons, cancelling, invites.
 * Every call to Telegram's API is faked.
 */
class TelegramBotFixesTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        config(['v2board.telegram_bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    private function tapButton(User $user, string $data): void
    {
        (new TelegramBotService())->handleWebhook(['callback_query' => [
            'id' => 'cb' . uniqid(),
            'data' => $data,
            'from' => ['id' => (int)$user->telegram_id],
            'message' => ['chat' => ['id' => (int)$user->telegram_id], 'message_id' => 5],
        ]]);
    }

    private function message(int $telegramId, string $text): void
    {
        (new TelegramBotService())->handleWebhook(['message' => [
            'text' => $text,
            'chat' => ['id' => $telegramId],
            'from' => ['id' => $telegramId],
        ]]);
    }

    private function coupon(array $attrs): Coupon
    {
        $id = DB::table('v2_coupon')->insertGetId(array_merge([
            'code' => 'C' . strtoupper(uniqid()),
            'name' => 'c',
            'type' => 1,
            'value' => 10000,
            'show' => 1,
            'started_at' => time() - 60,
            'ended_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        return Coupon::find($id);
    }

    public function testTheSixMonthButtonBuysSixMonths()
    {
        $plan = $this->makePlan(['half_year_price' => 450000]);
        $user = $this->makeUser(['telegram_id' => 111]);

        $this->tapButton($user, "buy_{$plan->id}_half_year");

        $order = Order::where('user_id', $user->id)->first();
        $this->assertNotNull($order, 'an order was created');
        $this->assertSame('half_year_price', $order->period);
        $this->assertSame(450000, (int)$order->total_amount);
    }

    public function testACraftedPeriodIsRefused()
    {
        $plan = $this->makePlan();
        $user = $this->makeUser(['telegram_id' => 112]);

        $this->tapButton($user, "buy_{$plan->id}_transfer_enable");
        $this->tapButton($user, "buy_{$plan->id}_reset"); // no active plan to reset

        $this->assertSame(0, Order::where('user_id', $user->id)->count());
    }

    public function testTheWalletIsChargedFromTheLockedRowNotAStaleCopy()
    {
        $plan = $this->makePlan(['month_price' => 100000]);
        $user = $this->makeUser(['telegram_id' => 113, 'balance' => 30000]);
        $stale = User::find($user->id);
        $stale->balance = 500000; // what an old copy of the user said

        $result = (new TelegramOrderService($stale))->createPlanOrder($plan->id, 'month');

        $this->assertTrue($result['success']);
        $order = Order::where('user_id', $user->id)->first();
        $this->assertSame(30000, (int)$order->balance_amount);
        $this->assertSame(70000, (int)$order->total_amount);
        $this->assertSame(0, (int)$user->fresh()->balance);
    }

    public function testStartingANewOrderGivesBackTheWalletPartOfTheOldOne()
    {
        $plan = $this->makePlan(['month_price' => 100000]);
        $user = $this->makeUser(['telegram_id' => 114, 'balance' => 30000]);

        (new TelegramOrderService($user))->createPlanOrder($plan->id, 'month');
        $this->assertSame(0, (int)$user->fresh()->balance);

        (new TelegramOrderService($user->fresh()))->createDepositOrder(20000);

        $first = Order::where('user_id', $user->id)->where('plan_id', $plan->id)->first();
        $this->assertSame(2, (int)$first->status);
        $this->assertSame(30000, (int)$user->fresh()->balance);
    }

    private function orderAwaitingCoupon(User $user, $plan): Order
    {
        $result = (new TelegramOrderService($user))->createPlanOrder($plan->id, 'month');
        $order = Order::where('trade_no', $result['trade_no'])->first();
        $user->update(['bot_step' => 'enter_coupon', 'bot_data' => json_encode(['trade_no' => $order->trade_no])]);
        return $order;
    }

    public function testAFixedCouponTakesItsAmountNotThatManyPercent()
    {
        $plan = $this->makePlan(['month_price' => 100000]);
        $user = $this->makeUser(['telegram_id' => 115]);
        $coupon = $this->coupon(['type' => 1, 'value' => 10000, 'limit_use' => 5]);
        $order = $this->orderAwaitingCoupon($user, $plan);

        $this->message(115, $coupon->code);

        $order->refresh();
        $this->assertSame(90000, (int)$order->total_amount);
        $this->assertSame($coupon->id, (int)$order->coupon_id);
        $this->assertSame(0, (int)$order->status, 'still to be paid');
        $this->assertSame(4, (int)$coupon->fresh()->limit_use, 'the use is counted');
    }

    public function testAPercentCouponIsAPercentage()
    {
        $plan = $this->makePlan(['month_price' => 100000]);
        $user = $this->makeUser(['telegram_id' => 116]);
        $coupon = $this->coupon(['type' => 2, 'value' => 20]);
        $order = $this->orderAwaitingCoupon($user, $plan);

        $this->message(116, $coupon->code);

        $this->assertSame(80000, (int)$order->fresh()->total_amount);
    }

    public function testACouponCannotBeAppliedTwiceToOneOrder()
    {
        $plan = $this->makePlan(['month_price' => 100000]);
        $user = $this->makeUser(['telegram_id' => 117]);
        $coupon = $this->coupon(['type' => 1, 'value' => 30000]);
        $order = $this->orderAwaitingCoupon($user, $plan);

        $this->message(117, $coupon->code);
        $user->refresh()->update(['bot_step' => 'enter_coupon', 'bot_data' => json_encode(['trade_no' => $order->trade_no])]);
        $this->message(117, $coupon->code);
        $user->refresh()->update(['bot_step' => 'enter_coupon', 'bot_data' => json_encode(['trade_no' => $order->trade_no])]);
        $this->message(117, $coupon->code);

        $order->refresh();
        $this->assertSame(70000, (int)$order->total_amount);
        $this->assertSame(0, (int)$order->status);
    }

    public function testAUsedUpOrHiddenCouponIsRefused()
    {
        $plan = $this->makePlan(['month_price' => 100000]);
        $user = $this->makeUser(['telegram_id' => 118]);
        $usedUp = $this->coupon(['limit_use' => 0]);
        $hidden = $this->coupon(['show' => 0]);
        $order = $this->orderAwaitingCoupon($user, $plan);

        $this->message(118, $usedUp->code);
        $this->message(118, $hidden->code);

        $order->refresh();
        $this->assertSame(100000, (int)$order->total_amount);
        $this->assertNull($order->coupon_id);
    }

    public function testACouponLimitedToPlansNoLongerCrashes()
    {
        $plan = $this->makePlan(['month_price' => 100000]);
        $other = $this->makePlan();
        $user = $this->makeUser(['telegram_id' => 119]);
        $forThis = $this->coupon(['type' => 1, 'value' => 5000, 'limit_plan_ids' => json_encode([(string)$plan->id])]);
        $forOther = $this->coupon(['type' => 1, 'value' => 5000, 'limit_plan_ids' => json_encode([(string)$other->id])]);
        $order = $this->orderAwaitingCoupon($user, $plan);

        $this->message(119, $forOther->code);
        $this->assertSame(100000, (int)$order->fresh()->total_amount);

        $this->message(119, $forThis->code);
        $this->assertSame(95000, (int)$order->fresh()->total_amount);
    }

    public function testADiscountBeyondWhatIsStillDueGoesBackToTheWallet()
    {
        $plan = $this->makePlan(['month_price' => 100000]);
        $user = $this->makeUser(['telegram_id' => 120, 'balance' => 80000]);
        $coupon = $this->coupon(['type' => 2, 'value' => 50]);
        $order = $this->orderAwaitingCoupon($user, $plan);
        $this->assertSame(80000, (int)$order->balance_amount);
        $this->assertSame(20000, (int)$order->total_amount);

        $this->message(120, $coupon->code);

        $order->refresh();
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertSame(50000, (int)$order->balance_amount);
        $this->assertSame(30000, (int)$user->fresh()->balance, '50% of 100,000: 20,000 due + 30,000 back');
        $this->assertSame(3, (int)$order->status, 'nothing left to pay, so it was opened');
    }

    public function testAnInviteLinkOnlyAttachesToANewAccount()
    {
        $inviter = $this->makeUser();
        DB::table('v2_invite_code')->insert([
            'user_id' => $inviter->id, 'code' => 'INVITE01', 'status' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $old = $this->makeUser(['telegram_id' => 121, 'created_at' => time() - 30 * 86400]);

        $this->message(121, '/start invite_INVITE01');
        $this->message(122, '/start invite_INVITE01'); // first contact: a new account

        $this->assertNull($old->fresh()->invite_user_id);
        $new = User::where('telegram_id', 122)->first();
        $this->assertSame($inviter->id, (int)$new->invite_user_id);
    }

    public function testASubscriptionLinkAloneDoesNotBindAnAdmin()
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->message(123, '/start bind_' . $admin->token);
        $this->assertNull($admin->fresh()->telegram_id);

        $user = $this->makeUser();
        $this->message(124, '/start bind_' . $user->token);
        $this->assertSame(124, (int)$user->fresh()->telegram_id);
    }

    public function testTheTransitUrlStepSavesTheUrl()
    {
        DB::table('v2_bot_settings')->insert(['key' => 'payment_transit_url', 'value' => '']);
        $admin = $this->makeUser(['telegram_id' => 125, 'is_admin' => 1, 'bot_step' => 'setting_transit_url']);

        $this->message(125, 'https://transit.example.com/t.php');

        $this->assertSame('https://transit.example.com/t.php',
            DB::table('v2_bot_settings')->where('key', 'payment_transit_url')->value('value'));
        $this->assertNull($admin->fresh()->bot_step);
    }
}
