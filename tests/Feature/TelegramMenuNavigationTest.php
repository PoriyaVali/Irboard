<?php

namespace Tests\Feature;

use App\Models\BotText;
use App\Models\CardPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

class TelegramMenuNavigationTest extends TestCase
{
    use PanelFixtures;

    private array $sent = [];
    private array $ids = [];
    private int $nextId = 100;
    private ?array $editFailure = null;
    private ?\Closure $beforeEdit = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_bot_texts']);
        config(['v2board.telegram_bot_token' => 'navigation-token', 'v2board.app_name' => 'Irboard',
            'v2board.app_url' => 'https://panel.example.com', 'v2board.subscribe_url' => 'https://sub.example.com',
            'v2board.try_out_plan_id' => 0, 'v2board.ticket_status' => 0,
            'app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        Http::fake(function (SentRequest $request) {
            $this->sent[] = $request;
            if (str_ends_with($request->url(), '/editMessageText') && $this->beforeEdit) {
                $callback = $this->beforeEdit;
                $this->beforeEdit = null;
                $callback();
            }
            if (str_ends_with($request->url(), '/editMessageText') && $this->editFailure) {
                [$status, $description] = $this->editFailure;
                $this->editFailure = null;
                return Http::response(['ok' => false, 'description' => $description], $status);
            }
            if (str_ends_with($request->url(), '/sendMessage')) {
                $id = $this->nextId++;
                $this->ids[(int) $request['chat_id']] = $id;
                return Http::response(['ok' => true, 'result' => ['message_id' => $id]]);
            }
            return Http::response(['ok' => true, 'result' => ['message_id' => $request['message_id'] ?? 1]]);
        });
    }

    private function message(User $user, string $text): void
    {
        $this->sent = [];
        (new TelegramBotService())->handleWebhook(['message' => [
            'chat' => ['id' => (int) $user->telegram_id, 'type' => 'private'],
            'from' => ['id' => (int) $user->telegram_id], 'text' => $text,
        ]]);
    }

    private function tap(User $user, string $data, ?int $sourceId = null): void
    {
        $this->sent = [];
        (new TelegramBotService())->handleWebhook(['callback_query' => [
            'id' => 'navigation-' . uniqid(), 'data' => $data,
            'from' => ['id' => (int) $user->telegram_id],
            'message' => ['chat' => ['id' => (int) $user->telegram_id, 'type' => 'private'],
                'message_id' => $sourceId ?? $this->ids[(int) $user->telegram_id] ?? 50, 'text' => 'menu'],
        ]]);
    }

    private function calls(string $method): array
    {
        return array_values(array_filter($this->sent, fn ($r) => str_ends_with($r->url(), '/' . $method)));
    }

    private function panel(): array
    {
        $calls = array_values(array_filter($this->sent, fn ($r) => preg_match('~/(sendMessage|editMessageText)$~', $r->url())));
        return $calls ? end($calls)->data() : [];
    }

    private function callbacks(): array
    {
        $markup = json_decode($this->panel()['reply_markup'] ?? '{}', true);
        return array_column(array_merge(...($markup['inline_keyboard'] ?? [[]])), 'callback_data');
    }

    private function assertEdited(int $messageId): void
    {
        $this->assertCount(0, $this->calls('sendMessage'), 'navigation must not add another bot message');
        $this->assertCount(1, $this->calls('editMessageText'), 'render exactly once per update');
        $this->assertSame($messageId, $this->panel()['message_id']);
    }

    public function testIndependentWebhookRequestsEditOneMessageAndBackRestoresTheActualPreviousView(): void
    {
        $user = $this->makeUser(['telegram_id' => 8101]);
        $plan = $this->makePlan(['name' => 'پلن حرفه‌ای']);
        $this->message($user, '/start');
        $this->assertCount(1, $this->calls('sendMessage'));
        $id = $this->ids[8101];
        $this->tap($user, 'plans_list');
        $this->assertEdited($id);
        $this->tap($user, 'plan_' . $plan->id);
        $this->assertEdited($id);
        $this->tap($user, 'menu_back');
        $this->assertEdited($id);
        $this->assertStringContainsString('انتخاب اشتراک', $this->panel()['text']);
        $this->tap($user, 'menu_back');
        $this->assertEdited($id);
        $this->assertStringContainsString('میز کار', $this->panel()['text']);
        $this->assertNotContains('menu_back', $this->callbacks());
    }

    public function testAllCustomerSectionsAndPlainTextValidationHaveBackAndHomeButtons(): void
    {
        $user = $this->makeUser(['telegram_id' => 8102]);
        $this->message($user, '/start');
        $id = $this->ids[8102];
        foreach (['plans_list', 'nav_services', 'nav_wallet', 'nav_account', 'nav_gift', 'nav_affiliate',
            'support_menu', 'nav_help', 'nav_trial', 'orders_page_1', 'reserved_plans', 'help_android', 'get_sub_link', 'charge_wallet', 'new_ticket', 'cred_reset'] as $data) {
            $this->tap($user, $data);
            $this->assertEdited($id);
            $this->assertContains('menu_back', $this->callbacks(), $data);
            $this->assertContains('main_menu', $this->callbacks(), $data);
            $this->tap($user, 'main_menu');
        }
        $this->tap($user, 'nav_wallet');
        $this->tap($user, 'charge_wallet');
        $this->message($user, 'عدد اشتباه');
        $this->assertEdited($id);
        $this->assertContains('menu_back', $this->callbacks());
        $this->tap($user, 'menu_back');
        $this->assertStringContainsString('کیف پول شما', $this->panel()['text']);
        $this->assertNull($user->fresh()->bot_step);
    }

    public function testBackThroughCheckoutAndCouponDoesNotCreateOrdersOrChargeTheWalletAgain(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser(['telegram_id' => 8103, 'balance' => 30000]);
        $this->message($user, '/start');
        $this->tap($user, 'plans_list');
        $this->tap($user, 'plan_' . $plan->id);
        $this->tap($user, 'buy_' . $plan->id . '_month');
        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->tap($user, 'coupon_yes_' . $order->trade_no);
        $this->assertSame('enter_coupon', $user->fresh()->bot_step);
        $this->tap($user, 'menu_back');
        $this->assertStringContainsString('تکمیل سفارش', $this->panel()['text']);
        $this->assertNull($user->fresh()->bot_step);
        $this->tap($user, 'menu_back');
        $this->assertContains('buy_' . $plan->id . '_month', $this->callbacks());
        $this->assertSame(1, Order::count());
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(30000, (int) $order->fresh()->balance_amount);
        $this->assertSame(0, (int) $order->fresh()->status);
    }

    public function testListPaginationAndRefreshDoNotFillTheBackStack(): void
    {
        $user = $this->makeUser(['telegram_id' => 8104]);
        for ($i = 0; $i < 7; $i++) $this->makePlan();
        $this->message($user, '/start');
        $this->tap($user, 'plans_list');
        $this->tap($user, 'plans_page_2');
        $this->tap($user, 'plans_page_2');
        $this->tap($user, 'menu_back');
        $this->assertStringContainsString('میز کار', $this->panel()['text']);
        $this->assertNotContains('plans_page_1', $this->callbacks());
    }

    public function testBackFromTicketDetailsKeepsTheSubjectAndNeverSendsTheTicket(): void
    {
        $user = $this->makeUser(['telegram_id' => 8105]);
        $this->message($user, '/start');
        $this->tap($user, 'support_menu');
        $this->tap($user, 'new_ticket');
        $this->message($user, 'اتصال اندروید قطع می‌شود');
        $this->tap($user, 'ticket_add_message');
        $this->assertSame('ticket_message', $user->fresh()->bot_step);
        $this->tap($user, 'menu_back');
        $this->assertSame('ticket_confirm', $user->fresh()->bot_step);
        $this->assertStringContainsString('اتصال اندروید', $this->panel()['text']);
        $this->tap($user, 'menu_back');
        $this->assertSame('ticket_subject', $user->fresh()->bot_step);
        $this->message($user, '/back');
        $this->assertNull($user->fresh()->bot_step);
        $this->assertStringContainsString('کنار شما', $this->panel()['text']);
        $this->assertSame(0, Ticket::count());
    }

    public function testHelpOnlyDeliversFilesImagesAndCredentialsWhenRequestedAndBackDoesNotRedeliver(): void
    {
        $user = $this->makeUser(['telegram_id' => 8106, 'email' => 'tg_8106@telegram.user']);
        $hash = $user->password;
        BotText::set('help_android_text', 'راهنمای نصب برنامه');
        BotText::set('help_android_send_credentials', '1');
        BotText::set('help_android_image', 'https://example.com/help.png');
        BotText::set('help_android_links', json_encode([['type' => 'file', 'file' => 'bot_files/demo.apk', 'label' => 'برنامه']]));
        $this->message($user, '/start');
        $this->tap($user, 'nav_help');
        $this->tap($user, 'help_android');
        $this->assertCount(0, $this->calls('sendDocument'));
        $this->assertCount(0, $this->calls('sendPhoto'));
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertContains('help_file_android_0', $this->callbacks());
        $this->assertContains('help_credentials_android', $this->callbacks());
        $this->tap($user, 'help_credentials_android');
        $this->assertStringContainsString('📧', $this->panel()['text']);
        $this->assertNotSame($hash, $user->fresh()->password);
        $this->tap($user, 'menu_back');
        $this->assertStringContainsString('راهنمای نصب', $this->panel()['text']);
        $this->assertCount(0, $this->calls('sendDocument'));
        $this->assertCount(0, $this->calls('sendPhoto'));
        $this->assertStringNotContainsString('📧', $this->panel()['text']);
    }

    public function testNotModifiedIsSuccessfulAndDoesNotCreateAnotherMessage(): void
    {
        $user = $this->makeUser(['telegram_id' => 8107]);
        $this->message($user, '/start');
        $this->editFailure = [400, 'Bad Request: message is not modified'];
        $this->tap($user, 'main_menu');
        $this->assertEdited($this->ids[8107]);
        $this->tap($user, 'nav_wallet');
        $this->assertEdited($this->ids[8107]);
    }

    public function testDeletedMenuIsReplacedOnceAndItsOldButtonsCannotExecuteActions(): void
    {
        $user = $this->makeUser(['telegram_id' => 8108]);
        $plan = $this->makePlan();
        $this->message($user, '/start');
        $oldId = $this->ids[8108];
        $this->editFailure = [400, 'Bad Request: message to edit not found'];
        $this->tap($user, 'nav_wallet');
        $this->assertCount(1, $this->calls('editMessageText'));
        $this->assertCount(1, $this->calls('sendMessage'));
        $this->assertCount(1, $this->calls('editMessageReplyMarkup'));
        $this->assertNotSame($oldId, $this->ids[8108]);
        $this->tap($user, 'buy_' . $plan->id . '_month', $oldId);
        $this->assertCount(0, $this->calls('sendMessage'));
        $this->assertCount(0, $this->calls('editMessageText'));
        $this->assertSame(0, Order::count());
        $this->assertStringContainsString('قدیمی', $this->calls('answerCallbackQuery')[0]['text']);
        $this->tap($user, 'nav_services');
        $this->assertEdited($this->ids[8108]);
    }

    /** @dataProvider transientErrors */
    public function testTransientEditFailuresDoNotFallBackToDuplicateMessages(int $status, string $description): void
    {
        $user = $this->makeUser(['telegram_id' => 8109]);
        $this->message($user, '/start');
        $this->editFailure = [$status, $description];
        $this->tap($user, 'nav_wallet');
        $this->assertEdited($this->ids[8109]);
        $this->tap($user, 'nav_wallet');
        $this->tap($user, 'menu_back');
        $this->assertStringContainsString('میز کار', $this->panel()['text']);
    }

    public function transientErrors(): array
    {
        return [[429, 'Too Many Requests: retry later'], [500, 'Internal Server Error']];
    }

    public function testExpiredHistoryRecoversToHomeInTheExistingMessage(): void
    {
        $user = $this->makeUser(['telegram_id' => 8110]);
        $this->message($user, '/start');
        $this->tap($user, 'nav_wallet');
        Cache::flush();
        $this->tap($user, 'menu_back');
        $this->assertEdited($this->ids[8110]);
        $this->assertStringContainsString('میز کار', $this->panel()['text']);
    }

    public function testFeedbackAndRedirectAreCombinedIntoOneSafeMenuEdit(): void
    {
        $user = $this->makeUser(['telegram_id' => 8116]);
        $this->message($user, '/start');
        $this->message($user, 'گزینهٔ ناشناس');
        $this->assertEdited($this->ids[8116]);
        $this->assertStringContainsString('پیدا نشد', $this->panel()['text']);
        $this->assertStringContainsString('میز کار', $this->panel()['text']);
    }

    public function testBackNeverRecreatesTheCardPaymentOrRestoresAnUnpaidFormAfterSettlement(): void
    {
        $user = $this->makeUser(['telegram_id' => 8111]);
        $plan = $this->makePlan();
        $gateway = Payment::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'بانک', 'payment' => 'Card2Card', 'enable' => 1,
            'config' => ['card_number' => '1234567890123456', 'card_holder' => 'نمونه', 'min_amount' => 10000]]);
        $this->message($user, '/start');
        $this->tap($user, 'plans_list');
        $this->tap($user, 'plan_' . $plan->id);
        $this->tap($user, 'buy_' . $plan->id . '_month');
        $order = Order::firstOrFail();
        $this->tap($user, 'coupon_no_' . $order->trade_no);
        $this->tap($user, 'pay_' . $order->trade_no . '_' . $gateway->id);
        $payment = CardPayment::firstOrFail();
        $this->assertSame('waiting_card_receipt', $user->fresh()->bot_step);
        $this->tap($user, 'nav_help');
        $this->tap($user, 'menu_back');
        $this->assertSame('waiting_card_receipt', $user->fresh()->bot_step);
        $this->assertSame(1, CardPayment::count());
        $this->assertSame($payment->id, CardPayment::first()->id);
        $this->tap($user, 'nav_help');
        $order->update(['status' => 3]);
        $this->tap($user, 'menu_back');
        $this->assertNull($user->fresh()->bot_step);
        $this->assertStringContainsString('تکمیل شده', $this->panel()['text']);
        $this->tap($user, 'menu_back');
        $this->tap($user, 'menu_back');
        $this->tap($user, 'menu_back');
        $this->assertContains('buy_' . $plan->id . '_month', $this->callbacks());
        $this->assertSame(1, Order::count());
    }

    public function testMenuHistoryAndMessagesAreScopedToTheAccount(): void
    {
        $a = $this->makeUser(['telegram_id' => 8112, 'balance' => 10000]);
        $b = $this->makeUser(['telegram_id' => 8113, 'balance' => 90000]);
        $this->message($a, '/start');
        $this->tap($a, 'plans_list');
        $this->message($b, '/start');
        $this->tap($b, 'nav_wallet');
        $this->tap($a, 'menu_back');
        $this->assertEdited($this->ids[8112]);
        $this->assertSame(8112, $this->panel()['chat_id']);
        $this->assertStringContainsString('10,000', $this->panel()['text']);
        $this->assertStringNotContainsString('90,000', $this->panel()['text']);
    }

    public function testAQueuedOldBuyTapCannotPurchaseAgainAfterTheMenuHasChanged(): void
    {
        $user = $this->makeUser(['telegram_id' => 8114, 'balance' => 300000]);
        $plan = $this->makePlan();
        $this->message($user, '/start');
        $this->tap($user, 'plans_list');
        $this->tap($user, 'plan_' . $plan->id);
        $this->tap($user, 'buy_' . $plan->id . '_month');
        $balance = $user->fresh()->balance;
        $this->tap($user, 'buy_' . $plan->id . '_month');
        $this->assertSame(1, Order::count());
        $this->assertSame($balance, $user->fresh()->balance);
        $this->assertCount(0, $this->calls('sendMessage'));
        $this->assertCount(0, $this->calls('editMessageText'));
        $this->assertStringContainsString('مرحله تغییر', $this->calls('answerCallbackQuery')[0]['text']);
    }

    public function testASecondTapBetweenSettlementAndTheMenuEditCannotChargeAgain(): void
    {
        $user = $this->makeUser(['telegram_id' => 8115, 'balance' => 300000]);
        $plan = $this->makePlan();
        $this->message($user, '/start');
        $this->tap($user, 'plans_list');
        $this->tap($user, 'plan_' . $plan->id);
        $data = 'buy_' . $plan->id . '_month';
        $this->beforeEdit = function () use ($user, $data) {
            $this->assertSame(1, Order::count(), 'the first transaction has settled');
            (new TelegramBotService())->handleWebhook(['callback_query' => [
                'id' => 'queued-buy', 'data' => $data, 'from' => ['id' => 8115],
                'message' => ['chat' => ['id' => 8115, 'type' => 'private'],
                    'message_id' => $this->ids[8115], 'text' => 'old plan buttons'],
            ]]);
        };
        $this->tap($user, $data);
        $this->assertSame(1, Order::count());
        $this->assertSame(200000, (int) $user->fresh()->balance);
        $this->assertCount(1, $this->calls('editMessageText'));
    }
}
