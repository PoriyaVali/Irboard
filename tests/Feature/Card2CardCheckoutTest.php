<?php

namespace Tests\Feature;

use App\Models\CardPayment;
use App\Payments\Card2Card;
use App\Services\CardReceiptStore;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * Opening a card-to-card payment: the reason it cannot open reaches the user,
 * and an order whose transfer is already reported is not paid twice.
 */
class Card2CardCheckoutTest extends TestCase
{
    use PanelFixtures;

    private const CONFIG = ['card_number' => '6037000000000000', 'card_holder' => 'x', 'min_amount' => 50000, 'max_amount' => 5000000];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
    }

    private function orderOf(int $amount): array
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['total_amount' => $amount, 'status' => 0]);
        return [$order, ['trade_no' => $order->trade_no, 'total_amount' => $amount, 'user_id' => $user->id]];
    }

    private function refusal(callable $pay): HttpException
    {
        try {
            $pay();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('expected the payment to be refused with a message the user sees');
    }

    public function testAnAmountUnderTheMinimumSaysSo()
    {
        [, $pay] = $this->orderOf(10000);
        $e = $this->refusal(fn () => (new Card2Card(self::CONFIG))->pay($pay));
        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringContainsString('حداقل', $e->getMessage());
    }

    public function testAnIncompleteCardSaysSo()
    {
        [, $pay] = $this->orderOf(100000);
        $e = $this->refusal(fn () => (new Card2Card(['card_number' => '', 'card_holder' => '']))->pay($pay));
        $this->assertStringContainsString('ناقص', $e->getMessage());
    }

    public function testATransferAlreadyReportedIsNotOpenedAgain()
    {
        [$order, $pay] = $this->orderOf(100000);
        (new Card2Card(self::CONFIG))->pay($pay);
        DB::table('v2_card_payments')->where('order_id', $order->id)->update(['status' => CardPayment::STATUS_CLAIMED]);

        $e = $this->refusal(fn () => (new Card2Card(self::CONFIG))->pay($pay));
        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringContainsString('در انتظار تأیید', $e->getMessage());
        $this->assertSame(1, DB::table('v2_card_payments')->where('order_id', $order->id)->count());
        $this->assertSame(CardPayment::STATUS_CLAIMED, DB::table('v2_card_payments')->where('order_id', $order->id)->value('status'));
    }

    public function testAPendingPaymentIsReplacedAndItsReceiptRemoved()
    {
        [$order, $pay] = $this->orderOf(100000);
        $first = (new Card2Card(self::CONFIG))->pay($pay);
        $payment = CardPayment::where('order_id', $order->id)->first();
        $receipt = CardReceiptStore::save($payment, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        ), 'png');
        DB::table('v2_card_payments')->where('id', $payment->id)->update(['receipt_file_id' => $receipt]);
        $path = storage_path('app/' . substr($receipt, strlen('local:')));
        $this->assertFileExists($path);

        $second = (new Card2Card(self::CONFIG))->pay($pay);
        $this->assertNotSame($first['data']['payment_id'], $second['data']['payment_id']);
        $this->assertSame(1, DB::table('v2_card_payments')->where('order_id', $order->id)->count());
        $this->assertFileDoesNotExist($path);
    }
}
