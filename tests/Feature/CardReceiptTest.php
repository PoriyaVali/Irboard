<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\CardPaymentController as AdminCardPaymentController;
use App\Models\CardPayment;
use App\Models\Order;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CardReceiptStore;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * A card-to-card receipt sent as an image from the app or the site: kept on
 * the panel, shown to the admin, sent to the admins' Telegram, and never a way
 * to read or write any other file.
 */
class CardReceiptTest extends TestCase
{
    use PanelFixtures;

    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        config(['v2board.telegram_bot_token' => 'test-token']);
        // The card gateway names the admin chat the requests go to.
        DB::table('v2_payment')->insert([
            'uuid' => md5('card'),
            'payment' => 'Card2Card',
            'name' => 'card',
            'config' => json_encode(['card_number' => '6037000000000000', 'card_holder' => 'x', 'telegram_admin_id' => '1']),
            'enable' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200)]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function authFor(User $user): string
    {
        return (new AuthService($user))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
    }

    private function pending(): array
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['total_amount' => 150000, 'status' => 0]);
        $id = DB::table('v2_card_payments')->insertGetId([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'user_id' => $user->id,
            'expected_amount' => 150000,
            'card_number' => '6037000000000000',
            'card_holder' => 'x',
            'amount_fingerprint' => 'f',
            'status' => CardPayment::STATUS_PENDING,
            'created_at' => time(),
            'expires_at' => time() + 1800,
            'updated_at' => time(),
        ]);
        return [$user, $order, CardPayment::find($id)];
    }

    private function storedPath(?string $id): string
    {
        $this->assertMatchesRegularExpression('#^local:card-receipts/\d+-[0-9a-f]{16}\.png$#', (string)$id);
        $path = storage_path('app/' . substr($id, strlen('local:')));
        $this->created[] = $path;
        return $path;
    }

    public function testAReceiptImageStandsInForTheTrackingNumber()
    {
        [$user, $order, $payment] = $this->pending();
        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->postJson('/api/v1/user/card-payment/claim', [
                'trade_no' => $order->trade_no,
                'receipt' => 'data:image/png;base64,' . self::PNG,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.receipt', true);

        $payment = $payment->fresh();
        $this->assertSame(CardPayment::STATUS_CLAIMED, $payment->status);
        $this->assertNull($payment->tracking_number);
        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->storedPath($payment->receipt_file_id)));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/sendPhoto'));
    }

    public function testATrackingNumberAloneStillWorks()
    {
        [$user, $order, $payment] = $this->pending();
        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->postJson('/api/v1/user/card-payment/claim', ['trade_no' => $order->trade_no, 'tracking_number' => '123456'])
            ->assertStatus(200)
            ->assertJsonPath('data.receipt', false);
        $payment = $payment->fresh();
        $this->assertSame('123456', $payment->tracking_number);
        $this->assertNull($payment->receipt_file_id);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/sendPhoto'));
    }

    public function testNeitherNumberNorImageIsRefused()
    {
        [$user, $order, $payment] = $this->pending();
        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->postJson('/api/v1/user/card-payment/claim', ['trade_no' => $order->trade_no])
            ->assertStatus(400);
        $this->assertSame(CardPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function testSomethingThatIsNotAnImageIsRefusedAndNothingIsKept()
    {
        [$user, $order, $payment] = $this->pending();
        $before = glob(storage_path('app/card-receipts/*')) ?: [];
        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->postJson('/api/v1/user/card-payment/claim', [
                'trade_no' => $order->trade_no,
                'receipt' => base64_encode('<?php echo "hi";'),
            ])
            ->assertStatus(400);
        $this->assertSame(CardPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame($before, glob(storage_path('app/card-receipts/*')) ?: []);
    }

    public function testAReceiptSentAfterTheClaimReplacesTheEarlierOneAndReachesTheAdmins()
    {
        [$user, $order, $payment] = $this->pending();
        $auth = ['authorization' => $this->authFor($user)];
        $this->withHeaders($auth)->postJson('/api/v1/user/card-payment/claim', [
            'trade_no' => $order->trade_no, 'tracking_number' => '98765', 'receipt' => self::PNG,
        ])->assertStatus(200);
        $first = $this->storedPath($payment->fresh()->receipt_file_id);

        $this->withHeaders($auth)->postJson('/api/v1/user/card-payment/receipt', [
            'trade_no' => $order->trade_no, 'receipt' => self::PNG,
        ])->assertStatus(200)->assertJsonPath('data.receipt', true);

        $second = $this->storedPath($payment->fresh()->receipt_file_id);
        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($first);
        $this->assertFileExists($second);
        Http::assertSentCount(3); // request and photo at the claim, then the new photo; one admin chat
    }

    public function testNoReceiptOnceTheAdminHasDecided()
    {
        [$user, $order, $payment] = $this->pending();
        DB::table('v2_card_payments')->where('id', $payment->id)->update(['status' => CardPayment::STATUS_VERIFIED_FULL]);
        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->postJson('/api/v1/user/card-payment/receipt', ['trade_no' => $order->trade_no, 'receipt' => self::PNG])
            ->assertStatus(400);
        $this->assertNull($payment->fresh()->receipt_file_id);
    }

    public function testTheAdminSeesTheStoredImage()
    {
        [$user, $order, $payment] = $this->pending();
        $this->withHeaders(['authorization' => $this->authFor($user)])
            ->postJson('/api/v1/user/card-payment/claim', ['trade_no' => $order->trade_no, 'receipt' => self::PNG])
            ->assertStatus(200);
        $this->storedPath($payment->fresh()->receipt_file_id);

        $data = (new AdminCardPaymentController())->receipt(Request::create('/', 'GET', ['id' => $payment->id]))
            ->getOriginalContent()['data'];
        $this->assertSame('image/png', $data['mime']);
        $this->assertSame(self::PNG, $data['image']);
    }

    public function testAStoredIdThatPointsElsewhereIsNeverRead()
    {
        $this->assertNull(CardReceiptStore::read('local:card-receipts/../../.env'));
        $this->assertNull(CardReceiptStore::read('local:../.env'));
        $this->assertNull(CardReceiptStore::read('AgACAgQAAxkBAAI'));
        CardReceiptStore::delete('local:card-receipts/../../.env');
        $this->assertFileExists(base_path('phpunit.xml'));
    }
}
