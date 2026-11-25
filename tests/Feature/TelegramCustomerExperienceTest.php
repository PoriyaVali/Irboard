<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Guest\TelegramBotController;
use App\Models\BotChannel;
use App\Models\BotText;
use App\Models\CardPayment;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

class TelegramCustomerExperienceTest extends TestCase
{
    use PanelFixtures;

    private array $sent = [];
    private string $membership = 'member';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_bot_texts']);
        config([
            'v2board.telegram_bot_token' => 'fake-token',
            'v2board.app_url' => 'https://panel.example.com',
            'v2board.subscribe_url' => 'https://sub.example.com',
            'v2board.app_name' => 'Irboard',
            'v2board.try_out_plan_id' => 0,
            'v2board.ticket_status' => 0,
            'app.key' => 'base64:' . base64_encode(random_bytes(32)),
        ]);
        Http::fake(function (SentRequest $request) {
            $this->sent[] = $request;
            if (str_contains($request->url(), '/getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->membership]]);
            }
            return Http::response(['ok' => true, 'result' => ['message_id' => 10]]);
        });
    }

    private function message(User $user, string $text, array $extra = []): void
    {
        $this->sent = [];
        (new TelegramBotService())->handleWebhook(['message' => array_replace_recursive([
            'text' => $text,
            'chat' => ['id' => (int) $user->telegram_id, 'type' => 'private'],
            'from' => ['id' => (int) $user->telegram_id],
        ], $extra)]);
    }

    private function tap(User $user, string $data, array $extra = []): void
    {
        $this->sent = [];
        (new TelegramBotService())->handleWebhook(['callback_query' => array_replace_recursive([
            'id' => 'cb-test', 'data' => $data,
            'from' => ['id' => (int) $user->telegram_id],
            'message' => ['chat' => ['id' => (int) $user->telegram_id, 'type' => 'private'], 'message_id' => 10],
        ], $extra)]);
    }

    private function messages(): array
    {
        return array_values(array_map(fn ($request) => $request->data(), array_filter($this->sent,
            fn ($request) => preg_match('~/(sendMessage|editMessageText)$~', $request->url()))));
    }

    private function text(): string
    {
        return implode("\n", array_column($this->messages(), 'text'));
    }

    private function markup(): array
    {
        $messages = $this->messages();
        return json_decode(end($messages)['reply_markup'] ?? '{}', true);
    }

    private function callbacks(): array
    {
        return array_column(array_merge(...($this->markup()['inline_keyboard'] ?? [[]])), 'callback_data');
    }

    public function testMainMenuHasAnEscapedDashboardAndInlineNavigation(): void
    {
        $user = $this->makeUser(['telegram_id' => 7001, 'balance' => 25000]);
        BotText::set('text_start', 'سلام <دوست> & همراه');
        $this->message($user, '/start');
        $this->assertStringContainsString('Irboard', $this->text());
        $this->assertStringContainsString('&lt;دوست&gt; &amp;', $this->text());
        $this->assertStringContainsString('25,000 تومان', $this->text());
        $this->assertArrayNotHasKey('keyboard', $this->markup());
        $labels = array_column(array_merge(...$this->markup()['inline_keyboard']), 'text');
        $this->assertContains('🧾 سفارش‌های من', $labels);
        $this->assertContains('📞 پشتیبانی', $labels);
    }

    public function testInlineAndSlashNavigationReallyCancelThePreviousInput(): void
    {
        $user = $this->makeUser(['telegram_id' => 7002, 'bot_step' => 'enter_charge_amount', 'bot_data' => '{"stale":1}']);
        $this->tap($user, 'main_menu');
        $this->assertNull($user->fresh()->bot_step);
        $this->assertNull($user->fresh()->bot_data);
        $user->update(['bot_step' => 'enter_giftcard']);
        $this->message($user, '/start@IrboardBot');
        $this->assertNull($user->fresh()->bot_step);
        $this->assertStringContainsString('میز کار', $this->text());
        $this->message($user, '/cancel');
        $this->assertStringNotContainsString('پیدا نشد', $this->text());
        $user->update(['bot_step' => 'enter_charge_amount', 'bot_data' => '{"stale":1}']);
        $this->tap($user, 'help_android');
        $this->assertNull($user->fresh()->bot_step);
        $this->assertNull($user->fresh()->bot_data);
    }

    public function testMembershipCheckButtonAndPurchaseButtonsUseTheSameGate(): void
    {
        BotChannel::create(['channel_id' => '@test', 'title' => 'کانال', 'status' => true]);
        $user = $this->makeUser(['telegram_id' => 7003]);
        $plan = $this->makePlan();
        $this->membership = 'left';
        $this->tap($user, 'buy_' . $plan->id . '_month');
        $this->assertSame(0, Order::count());
        $this->assertContains('check_join', $this->callbacks());
        $this->membership = 'member';
        $this->tap($user, 'check_join');
        $this->assertStringContainsString('میز کار', $this->text());
        $acks = array_filter($this->sent, fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery'));
        $this->assertCount(1, $acks);
    }

    public function testBannedUsersAndGroupChatsCannotUseOldPurchaseButtons(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser(['telegram_id' => 7004, 'banned' => 1]);
        $this->tap($user, 'buy_' . $plan->id . '_month');
        $this->assertSame(0, Order::count());
        $user->update(['banned' => 0]);
        $this->tap($user, 'get_sub_link', ['message' => ['chat' => ['id' => -123, 'type' => 'group']]]);
        $this->assertCount(0, $this->sent);
        $this->message($user, '/start', ['chat' => ['id' => -123, 'type' => 'supergroup']]);
        $this->assertCount(0, $this->sent);
    }

    public function testEverySupportedPeriodIncludingZeroPriceIsSelectable(): void
    {
        $plan = $this->makePlan(['name' => 'پلن <ویژه> & شما', 'month_price' => null,
            'two_year_price' => 200000, 'three_year_price' => 300000, 'onetime_price' => 0]);
        $user = $this->makeUser(['telegram_id' => 7005]);
        $this->tap($user, 'plan_' . $plan->id);
        $this->assertContains('buy_' . $plan->id . '_onetime', $this->callbacks());
        $this->assertContains('buy_' . $plan->id . '_two_year', $this->callbacks());
        $this->assertContains('buy_' . $plan->id . '_three_year', $this->callbacks());
        $this->assertStringContainsString('&lt;ویژه&gt; &amp;', $this->text());
        $this->assertStringContainsString('0 تومان', json_encode($this->markup(), JSON_UNESCAPED_UNICODE));
    }

    public function testStoreAndOrdersPaginateWithoutLeakingAnotherUsersOrders(): void
    {
        $user = $this->makeUser(['telegram_id' => 7006]);
        $other = $this->makeUser();
        for ($i = 0; $i < 7; $i++) {
            $this->makePlan();
            $this->makeOrder($user);
        }
        $private = $this->makeOrder($other);
        $this->message($user, '/plans');
        $this->assertCount(5, array_filter($this->callbacks(), fn ($data) => str_starts_with($data, 'plan_')));
        $this->assertContains('plans_page_2', $this->callbacks());
        $this->tap($user, 'orders_page_2');
        $this->assertCount(2, array_filter($this->callbacks(), fn ($data) => str_starts_with($data, 'order_view_')));
        $this->tap($user, 'order_view_' . $private->trade_no);
        $this->assertStringNotContainsString($private->trade_no, $this->text());
        $this->assertStringContainsString('یافت نشد', $this->text());
    }

    public function testPerpetualServicesHaveAWorkingLinkAndCorrectUsage(): void
    {
        $plan = $this->makePlan(['name' => 'حجمی <A>']);
        $user = $this->makeActiveUser($plan, ['telegram_id' => 7007, 'expired_at' => null,
            'transfer_enable' => 10 * 1073741824, 'u' => 2 * 1073741824]);
        $this->message($user, '/services');
        $this->assertStringContainsString('بدون انقضا', $this->text());
        $this->assertStringContainsString('8 GB', $this->text());
        $this->assertStringContainsString('20%', $this->text());
        $this->tap($user, 'get_sub_link');
        $this->assertStringContainsString('<code>https://', $this->text());
        $this->assertStringNotContainsString('خرید اشتراک', $this->text());
    }

    public function testDeletedPlansAndExpiredServicesStillHaveUsefulNavigation(): void
    {
        $user = $this->makeUser(['telegram_id' => 7008, 'plan_id' => 99999, 'expired_at' => time() - 10]);
        $this->message($user, '/services');
        $this->assertStringContainsString('منقضی شده', $this->text());
        $this->assertContains('get_sub_link', $this->callbacks());
        $this->tap($user, 'get_sub_link');
        $this->assertContains('plans_list', $this->callbacks());
    }

    /** @dataProvider chargeAmounts */
    public function testChargeAmountsAreParsedWithoutRoundingOrPartialCasting(string $amount, ?int $expected): void
    {
        $user = $this->makeUser(['telegram_id' => 7009, 'bot_step' => 'enter_charge_amount']);
        $this->message($user, $amount);
        $order = Order::first();
        if ($expected === null) {
            $this->assertNull($order);
            $this->assertSame('enter_charge_amount', $user->fresh()->bot_step);
        } else {
            $this->assertNotNull($order);
            $this->assertSame($expected, (int) $order->total_amount);
            $this->assertNull($user->fresh()->bot_step);
            $this->assertStringContainsString('درگاه فعالی', $this->text());
        }
    }

    public function chargeAmounts(): array
    {
        return [['۵۰٬۰۰۰', 50000], ['١٠٠٬٠٠٠', 100000], ['75,000', 75000], ['۵۰۰۰۰', 50000],
            ['50000abc', null], ['50,00', null], ['1e5', null], ['50000.9', null],
            ['-10000', null], ['۹۹۹۹', null], ['999999999999999999999999', null]];
    }

    public function testClaimedReceiptKeepsTrackingAndSupportOpenButBlocksNewPurchases(): void
    {
        $user = $this->makeUser(['telegram_id' => 7010]);
        $plan = $this->makePlan();
        $order = $this->makeOrder($user);
        CardPayment::create(['user_id' => $user->id, 'order_id' => $order->id, 'trade_no' => $order->trade_no,
            'expected_amount' => 100000, 'card_number' => '1234', 'card_holder' => 'test',
            'amount_fingerprint' => 'fake', 'expires_at' => time() + 1800, 'status' => 'claimed']);
        $this->tap($user, 'buy_' . $plan->id . '_month');
        $this->assertSame(1, Order::count());
        $this->tap($user, 'order_view_' . $order->trade_no);
        $this->assertStringContainsString('رسید دریافت شده', $this->text());
        $this->assertNotContains('cancel_order_' . $order->trade_no, $this->callbacks());
        $this->tap($user, 'new_ticket');
        $this->message($user, 'بررسی رسید بانکی');
        $this->tap($user, 'ticket_send_now');
        $this->assertSame(1, Ticket::where('user_id', $user->id)->count());
    }

    public function testTrialCreatesARealServiceAndDoesNotResetItOnReplay(): void
    {
        $plan = $this->makePlan(['transfer_enable' => 2, 'device_limit' => 1]);
        config(['v2board.try_out_plan_id' => $plan->id, 'v2board.try_out_hour' => 2]);
        $user = $this->makeUser(['telegram_id' => 7011]);
        $this->tap($user, 'trial_activate');
        $fresh = $user->fresh();
        $this->assertSame($plan->id, (int) $fresh->plan_id);
        $this->assertSame(2 * 1073741824, (int) $fresh->transfer_enable);
        $this->assertGreaterThan(time() + 7100, (int) $fresh->expired_at);
        $this->assertSame(1, (int) $fresh->bot_test_count);
        $this->assertContains('get_sub_link', $this->callbacks());
        $this->tap($user, 'trial_activate');
        $this->assertSame($fresh->expired_at, $user->fresh()->expired_at);
        $this->assertSame(1, (int) $user->fresh()->bot_test_count);
    }

    public function testTrialsNeverOverwritePaidServicesAndOldExternalButtonsDoNotConsumeQuota(): void
    {
        $plan = $this->makePlan();
        config(['v2board.try_out_plan_id' => $plan->id]);
        $user = $this->makeActiveUser($plan, ['telegram_id' => 7012]);
        $before = $user->expired_at;
        $this->tap($user, 'trial_activate');
        $this->assertSame($before, $user->fresh()->expired_at);
        $this->assertSame(0, (int) $user->fresh()->bot_test_count);
        $this->tap($user, 'test_123');
        $this->assertSame(0, (int) $user->fresh()->bot_test_count);
        $this->assertStringNotContainsString('ایجاد شد', $this->text());
    }

    public function testOldTicketConfirmationCannotCreateAnEmptyOrDuplicateTicket(): void
    {
        $user = $this->makeUser(['telegram_id' => 7013]);
        $this->tap($user, 'new_ticket');
        $this->message($user, 'مشکل اتصال');
        $this->assertSame('ticket_confirm', $user->fresh()->bot_step);
        $this->tap($user, 'cancel_input');
        $this->tap($user, 'ticket_send_now');
        $this->assertSame(0, Ticket::count());
        $this->tap($user, 'new_ticket');
        $this->message($user, 'مشکل اتصال');
        $this->tap($user, 'ticket_send_now');
        $this->tap($user, 'ticket_send_now');
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketMessage::count());
    }

    public function testTicketClosureNeedsConfirmationAndIsOwnerScoped(): void
    {
        $user = $this->makeUser(['telegram_id' => 7014]);
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'اتصال', 'level' => 1, 'status' => 0]);
        $other = $this->makeUser(['telegram_id' => 7015]);
        $this->tap($other, 'ticket_close_yes_' . $ticket->id);
        $this->assertSame(0, (int) $ticket->fresh()->status);
        $this->tap($user, 'ticket_close_' . $ticket->id);
        $this->assertSame(0, (int) $ticket->fresh()->status);
        $this->assertContains('ticket_close_yes_' . $ticket->id, $this->callbacks());
        $this->tap($user, 'ticket_close_yes_' . $ticket->id);
        $this->assertSame(1, (int) $ticket->fresh()->status);
    }

    public function testLongTicketMessagesArePagedInPlaceAndHistoryIsPaged(): void
    {
        $user = $this->makeUser(['telegram_id' => 7016]);
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'اتصال', 'level' => 1, 'status' => 0]);
        for ($i = 1; $i <= 7; $i++) TicketMessage::create([
            'ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => "پیام {$i} " . str_repeat('🙂', 1900),
        ]);
        $this->tap($user, 'view_ticket_' . $ticket->id);
        $allText = $this->text();
        $this->assertStringContainsString('پیام 3 ', $allText);
        $this->assertStringNotContainsString('پیام 1 ', $this->text());
        $page = 2;
        while (in_array('menu_page_' . $page, $this->callbacks(), true)) {
            $this->tap($user, 'menu_page_' . $page++);
            $allText .= $this->text();
            foreach ($this->messages() as $message) $this->assertLessThanOrEqual(4096, strlen(mb_convert_encoding($message['text'], 'UTF-16LE', 'UTF-8')) / 2);
        }
        $this->assertStringContainsString('پیام 7 ', $allText);
        $this->assertContains('ticket_thread_' . $ticket->id . '_2', $this->callbacks());
        $this->tap($user, 'ticket_thread_' . $ticket->id . '_2');
        $this->assertStringContainsString('پیام 1 ', $this->text());
    }

    public function testCompletedOrdersCannotReopenThePaymentScreen(): void
    {
        $user = $this->makeUser(['telegram_id' => 7017]);
        $order = $this->makeOrder($user, ['status' => 3]);
        $this->tap($user, 'coupon_no_' . $order->trade_no);
        $this->assertStringContainsString('تکمیل شده', $this->text());
        $this->assertNotContains('cancel_order_' . $order->trade_no, $this->callbacks());
    }

    public function testTelegramCommandMenuIncludesCustomerShortcutsWithValidUniqueNames(): void
    {
        $commands = (new \App\Services\TelegramService())->discoverCommands(app_path('Plugins/Telegram/Commands'));
        $names = array_column($commands, 'command');
        foreach (['orders', 'services', 'support', 'cancel', 'bind'] as $name) $this->assertContains($name, $names);
        $this->assertSame($names, array_values(array_unique($names)));
        foreach ($names as $name) $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/D', $name);
    }

    public function testHtmlErrorsFallBackToReadableTextWithoutLosingButtons(): void
    {
        $attempt = 0;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function (SentRequest $request) use (&$attempt) {
            $this->sent[] = $request;
            return ++$attempt === 1
                ? Http::response(['ok' => false, 'description' => "Bad Request: can't parse entities"], 400)
                : Http::response(['ok' => true]);
        });
        (new TelegramBotService())->sendMessage(123, '<b>سلام</b> &amp; شما', null, [[['text' => 'بازگشت', 'callback_data' => 'main_menu']]], 'HTML');
        $messages = $this->messages();
        $this->assertCount(2, $messages);
        $this->assertSame('سلام & شما', $messages[1]['text']);
        $this->assertArrayNotHasKey('parse_mode', $messages[1]);
        $this->assertContains('main_menu', $this->callbacks());
    }

    public function testAnUnconfiguredWebhookRejectsTheEmptyTokenHash(): void
    {
        config(['v2board.telegram_bot_token' => '']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new TelegramBotController())->webhook(Request::create('/', 'POST', ['access_token' => md5('')]));
    }
}
