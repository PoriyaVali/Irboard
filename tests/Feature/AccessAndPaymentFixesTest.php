<?php

namespace Tests\Feature;

use App\Models\CardPayment;
use App\Models\Order;
use App\Models\User;
use App\Payments\Card2Card;
use App\Payments\ZibalPayment;
use App\Services\AuthService;
use App\Services\CardPaymentService;
use App\Services\PaymentService;
use App\Services\Telegram\TelegramLoginLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * Who may do what (reseller endpoints, the bot's panel link, the
 * wrong-password counter) and the payment paths around them.
 */
class AccessAndPaymentFixesTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    private function authFor(User $user): string
    {
        return (new AuthService($user))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
    }

    private function staffPath(): string
    {
        return '/api/v1/staff/user/update';
    }

    public function testAResellerCannotEditAnAdminOrItself()
    {
        $reseller = $this->makeUser(['is_staff' => 1, 'balance' => 1000]);
        $admin = $this->makeUser(['is_admin' => 1]);
        $auth = $this->authFor($reseller);

        $this->withHeaders(['authorization' => $auth])->postJson($this->staffPath(), [
            'id' => $admin->id, 'email' => $admin->email, 'password' => 'hijacked1', 'banned' => 0,
        ])->assertStatus(500);
        $this->withHeaders(['authorization' => $auth])->postJson($this->staffPath(), [
            'id' => $reseller->id, 'email' => $reseller->email, 'balance' => 999999999, 'banned' => 0,
        ])->assertStatus(500);

        $this->assertTrue(password_verify('secret123', $admin->fresh()->password));
        $this->assertSame(1000, (int)$reseller->fresh()->balance);
    }

    public function testAResellerEditsItsOwnCustomerButNotTheirServiceOrMoney()
    {
        $reseller = $this->makeUser(['is_staff' => 1]);
        $customer = $this->makeUser(['invite_user_id' => $reseller->id, 'balance' => 0]);
        $auth = $this->authFor($reseller);

        $this->withHeaders(['authorization' => $auth])->postJson($this->staffPath(), [
            'id' => $customer->id, 'email' => $customer->email, 'balance' => 50000, 'banned' => 0,
        ])->assertStatus(500);
        $this->assertSame(0, (int)$customer->fresh()->balance);

        // The whole record posted back unchanged, with a new password: accepted.
        $this->withHeaders(['authorization' => $auth])->postJson($this->staffPath(), [
            'id' => $customer->id, 'email' => $customer->email, 'password' => 'newpass99',
            'banned' => 0, 'balance' => 0, 'u' => 0, 'd' => 0,
        ])->assertStatus(200);
        $this->assertTrue(password_verify('newpass99', $customer->fresh()->password));
    }

    public function testTheBotPanelLinkNoLongerAcceptsASubscriptionToken()
    {
        config(['v2board.frontend_url' => 'https://panel.example.com']);
        $admin = $this->makeUser(['is_admin' => 1, 'telegram_id' => 900]);

        $res = $this->get('/api/v1/guest/telegram/auth?token=' . $admin->token . '&redirect=dashboard');
        $res->assertRedirect();
        $this->assertStringNotContainsString('auth_data', $res->headers->get('Location'));

        $link = TelegramLoginLink::make($admin);
        $res = $this->get(substr($link, strpos($link, '/api/')));
        $this->assertStringContainsString('auth_data=', $res->headers->get('Location'));
    }

    public function testTheBotPanelLinkDiesWithTheBindingAndCannotBeAltered()
    {
        $user = $this->makeUser(['telegram_id' => 901]);
        parse_str(parse_url(TelegramLoginLink::make($user), PHP_URL_QUERY), $q);

        $this->assertNotNull(TelegramLoginLink::verify($q['uid'], $q['exp'], $q['sig']));
        $this->assertNull(TelegramLoginLink::verify($q['uid'], $q['exp'] + 60, $q['sig']));
        $other = $this->makeUser(['telegram_id' => 902]);
        $this->assertNull(TelegramLoginLink::verify($other->id, $q['exp'], $q['sig']));

        $user->update(['telegram_id' => null]);
        $this->assertNull(TelegramLoginLink::verify($q['uid'], $q['exp'], $q['sig']));
    }

    public function testTheWrongPasswordCounterIgnoresTheCaseOfTheEmail()
    {
        // MySQL finds the account whatever the case of the address (SQLite, here,
        // only the exact one); the counter must be one per address either way.
        config(['v2board.password_limit_enable' => 1, 'v2board.password_limit_count' => 3]);
        $this->makeUser(['email' => 'Victim@Example.com']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/passport/auth/login', ['email' => 'Victim@Example.com', 'password' => 'wrong-pass']);
        }

        $this->assertSame(3, (int)Cache::get(AuthService::passwordLimitKey('victim@example.com')));
        $this->assertSame(
            AuthService::passwordLimitKey('VICTIM@EXAMPLE.COM'),
            AuthService::passwordLimitKey(' victim@example.com ')
        );
        $this->postJson('/api/v1/passport/auth/login', ['email' => 'Victim@Example.com', 'password' => 'secret123'])
            ->assertStatus(500);
    }

    public function testMovingCommissionToTheWalletEarnsTheInviterNothing()
    {
        $inviter = $this->makeUser(['commission_type' => 1]);
        $user = $this->makeUser(['invite_user_id' => $inviter->id, 'commission_balance' => 50000]);

        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->postJson('/api/v1/user/transfer', ['transfer_amount' => 50000])
            ->assertStatus(200);

        $order = Order::where('user_id', $user->id)->first();
        $this->assertNull($order->invite_user_id);
        $this->assertSame(0, (int)$order->commission_balance);
        $this->assertSame(50000, (int)$user->fresh()->balance);
    }

    private function cardPaymentFor(Order $order, string $status = CardPayment::STATUS_CLAIMED): CardPayment
    {
        $id = DB::table('v2_card_payments')->insertGetId([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'user_id' => $order->user_id,
            'expected_amount' => $order->total_amount,
            'card_number' => '6037000000000000',
            'card_holder' => 'x',
            'amount_fingerprint' => 'f',
            'status' => $status,
            'created_at' => time(),
            'expires_at' => time() + 1800,
            'updated_at' => time(),
        ]);
        return CardPayment::find($id);
    }

    public function testRejectingACardPaymentGivesBackTheWalletPart()
    {
        $user = $this->makeUser(['balance' => 0]);
        $order = $this->makeOrder($user, ['balance_amount' => 25000, 'total_amount' => 75000]);
        $payment = $this->cardPaymentFor($order);

        $result = (new CardPaymentService())->reject($payment, 1, 'no deposit');

        $this->assertTrue($result['success']);
        $this->assertSame(2, (int)$order->fresh()->status);
        $this->assertSame(25000, (int)$user->fresh()->balance);
    }

    public function testASecondApprovalOfTheSameCardPaymentDoesNothing()
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['total_amount' => 100000]);
        $payment = $this->cardPaymentFor($order);
        $staleCopy = CardPayment::find($payment->id);

        $first = (new CardPaymentService())->verifyWithDifferentAmount($payment, 60000, 1);
        $second = (new CardPaymentService())->verifyWithDifferentAmount($staleCopy, 60000, 1);

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame(60000, (int)$user->fresh()->balance, 'credited once');
    }

    public function testACardPaymentCannotBeClaimedForACancelledOrder()
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['status' => 2]);
        $payment = $this->cardPaymentFor($order, CardPayment::STATUS_PENDING);

        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->postJson('/api/v1/user/card-payment/claim', ['trade_no' => $order->trade_no, 'tracking_number' => '123456'])
            ->assertStatus(400);
        $this->assertSame(CardPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function testCardToCardLimitsAreInToman()
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['total_amount' => 120000]);
        $gateway = new Card2Card(['card_number' => '6037000000000000', 'card_holder' => 'x', 'min_amount' => '', 'max_amount' => '']);

        $result = $gateway->pay(['trade_no' => $order->trade_no, 'total_amount' => 120000, 'user_id' => $user->id]);

        $this->assertSame(0, $result['type']);
        $this->assertSame(120000, $result['data']['amount']);
    }

    public function testZibalExpectsTheHandlingFeeItWasAskedToCollect()
    {
        $order = new Order(['total_amount' => 100000, 'handling_amount' => 2000]);
        $this->assertSame(1020000, ZibalPayment::expectedRial($order));
        $this->assertSame(1000000, ZibalPayment::expectedRial(new Order(['total_amount' => 100000])));
    }

    public function testEachGatewayRunsOnItsOwnSettings()
    {
        foreach (['m-first', 'm-second'] as $merchant) {
            DB::table('v2_payment')->insert([
                'uuid' => md5($merchant), 'payment' => 'ZibalPayment', 'name' => $merchant,
                'config' => json_encode(['zibal_merchant' => $merchant, 'zibal_callback' => 'https://x']),
                'enable' => 1, 'created_at' => time(), 'updated_at' => time(),
            ]);
        }
        $second = DB::table('v2_payment')->where('name', 'm-second')->value('id');

        $form = (new PaymentService('ZibalPayment', $second))->form();

        $this->assertSame('m-second', $form['zibal_merchant']['value']);
    }

    public function testRenewalChargesAndExtendsForTheSamePeriodAndKeepsBigPlansWhole()
    {
        $plan = $this->makePlan(['month_price' => null, 'quarter_price' => 270000, 'transfer_enable' => 1500]);
        $user = $this->makeUser([
            'plan_id' => $plan->id, 'group_id' => 1, 'auto_renewal' => 1, 'balance' => 300000,
            'transfer_enable' => 1500 * 1073741824, 'u' => 1500 * 1073741824, 'd' => 0,
            'expired_at' => time() + 10 * 86400,
        ]);
        $lifetime = $this->makeUser([
            'plan_id' => $plan->id, 'auto_renewal' => 1, 'balance' => 300000,
            'transfer_enable' => 1073741824, 'u' => 1073741824, 'expired_at' => null,
        ]);
        $before = time();

        $this->artisan('check:renewal');

        $user->refresh();
        $this->assertSame(30000, (int)$user->balance);
        $this->assertSame(1500 * 1073741824, (int)$user->transfer_enable);
        $this->assertGreaterThanOrEqual($before + 100 * 86400 - 5, (int)$user->expired_at, '10 days left + 90');
        $this->assertSame(300000, (int)$lifetime->fresh()->balance, 'a one-time plan is not renewed');
        $this->assertNull($lifetime->fresh()->expired_at);
    }
}
