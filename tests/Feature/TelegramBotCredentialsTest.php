<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\SmartBotController;
use App\Models\BotText;
use App\Models\User;
use App\Services\Telegram\BotCredentials;
use App\Services\Telegram\TelegramBotService;
use App\Utils\Helper;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * "Send email and password" after a smart-bot help section. Telegram is faked.
 */
class TelegramBotCredentialsTest extends TestCase
{
    use PanelFixtures;

    /** @var list<SentRequest> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_bot_texts']);
        config(['v2board.telegram_bot_token' => 'test-token']);
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        Http::fake(function (SentRequest $request) {
            $this->sent[] = $request;
            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
        BotText::set('help_android_text', 'برنامه دکتر موبایل را نصب کنید و با ایمیل و رمزی که فرستاده می‌شود وارد شوید');
    }

    private function botUser(int $telegramId, array $attrs = []): User
    {
        return $this->makeUser(array_merge([
            'telegram_id' => $telegramId,
            'email' => "tg_{$telegramId}@telegram.user",
            'password' => password_hash('never-shown-' . $telegramId, PASSWORD_DEFAULT),
        ], $attrs));
    }

    private function tap(User $user, string $data): void
    {
        $this->sent = [];
        (new TelegramBotService())->handleWebhook(['callback_query' => [
            'id' => 'cb' . uniqid(),
            'data' => $data,
            'from' => ['id' => (int) $user->telegram_id],
            'message' => ['chat' => ['id' => (int) $user->telegram_id], 'message_id' => 5],
        ]]);
    }

    /** Texts of the sendMessage calls to [chatId], in order. */
    private function texts(int $chatId): array
    {
        $out = [];
        foreach ($this->sent as $r) {
            if (str_ends_with($r->url(), '/sendMessage') && (string) ($r->data()['chat_id'] ?? '') === (string) $chatId) {
                $out[] = (string) $r['text'];
            }
        }
        return $out;
    }

    private function credentialMessage(int $chatId): ?string
    {
        foreach ($this->texts($chatId) as $text) {
            if (str_contains($text, 'tg_') || str_contains($text, '📧')) {
                return $text;
            }
        }
        return null;
    }

    private function shownPassword(string $text): ?string
    {
        return preg_match('/🔑 رمز عبور: <code>([^<]+)<\/code>/u', $text, $m) ? html_entity_decode($m[1]) : null;
    }

    public function testWithTheBoxTickedABotUserGetsAnEmailAndAPasswordThatLogsIn()
    {
        BotText::set('help_android_send_credentials', '1');
        $user = $this->botUser(5001);

        $this->tap($user, 'help_android');

        $text = $this->credentialMessage(5001);
        $this->assertNotNull($text, 'a credentials message follows the help');
        $this->assertStringContainsString('tg_5001@telegram.user', $text);
        $password = $this->shownPassword($text);
        $this->assertNotNull($password);
        $fresh = $user->fresh();
        $this->assertTrue(Helper::multiPasswordVerify($fresh->password_algo, $fresh->password_salt, $password, $fresh->password),
            'the password shown is the one the login page accepts');
        $this->assertSame('HTML', $this->lastParseMode(5001));
    }

    public function testTheSamePasswordIsShownEveryTime()
    {
        BotText::set('help_android_send_credentials', '1');
        $user = $this->botUser(5002);

        $this->tap($user, 'help_android');
        $first = $this->shownPassword($this->credentialMessage(5002));
        $hash = $user->fresh()->password;

        $this->tap($user, 'help_android');
        $second = $this->shownPassword($this->credentialMessage(5002));

        $this->assertSame($first, $second);
        $this->assertSame($hash, $user->fresh()->password, 'and the account is not changed again');
    }

    public function testWithoutTheBoxNothingIsSentOrChanged()
    {
        $user = $this->botUser(5003);
        $hash = $user->password;

        $this->tap($user, 'help_android');

        $this->assertNull($this->credentialMessage(5003));
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function testAPasswordTheOwnerChoseIsNeverReplacedOnItsOwn()
    {
        BotText::set('help_android_send_credentials', '1');
        $user = $this->makeUser(['telegram_id' => 5004, 'email' => 'owner@example.com', 'password' => password_hash('mine', PASSWORD_DEFAULT)]);
        $hash = $user->password;

        $this->tap($user, 'help_android');

        $text = $this->credentialMessage(5004);
        $this->assertStringContainsString('owner@example.com', $text);
        $this->assertNull($this->shownPassword($text), 'no password is invented for it');
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function testAPasswordChangedAfterTheBotSetItIsLeftAlone()
    {
        BotText::set('help_android_send_credentials', '1');
        $user = $this->botUser(5005);
        BotCredentials::forUser($user); // the bot set one ...
        $user->fresh()->forceFill(['password' => password_hash('changed-on-the-site', PASSWORD_DEFAULT)])->save(); // ... then it changed

        $this->tap($user, 'help_android');

        $this->assertNull($this->shownPassword($this->credentialMessage(5005)));
        $this->assertTrue(password_verify('changed-on-the-site', $user->fresh()->password));
    }

    public function testANewPasswordOnlyAfterConfirmingAndItWorks()
    {
        $user = $this->makeUser(['telegram_id' => 5006, 'email' => 'forgot@example.com', 'password' => password_hash('lost', PASSWORD_DEFAULT)]);

        $this->tap($user, 'cred_reset');
        $this->assertTrue(password_verify('lost', $user->fresh()->password), 'the first tap only asks');

        $this->tap($user, 'cred_reset_yes');
        $password = $this->shownPassword((string) $this->credentialMessage(5006));
        $this->assertNotNull($password);
        $this->assertTrue(password_verify($password, $user->fresh()->password));
        $this->assertFalse(password_verify('lost', $user->fresh()->password));
    }

    public function testAnAdminAccountsPasswordIsNeverShownOrReplaced()
    {
        BotText::set('help_android_send_credentials', '1');
        $admin = $this->makeUser(['telegram_id' => 5007, 'is_admin' => 1, 'email' => 'tg_5007@telegram.user', 'password' => password_hash('admin-secret', PASSWORD_DEFAULT)]);

        $this->tap($admin, 'help_android');
        $this->assertNull($this->shownPassword((string) $this->credentialMessage(5007)));

        $this->tap($admin, 'cred_reset_yes');
        $this->assertTrue(password_verify('admin-secret', $admin->fresh()->password));
    }

    public function testThePanelStoresTheBoxPerPlatformAndAnOldPageLeavesItAlone()
    {
        $controller = new SmartBotController();
        $controller->save(Request::create('/', 'POST', ['help_android_send_credentials' => 1, 'help_windows_send_credentials' => 0]));
        $data = $controller->get(Request::create('/', 'GET'))->getOriginalContent()['data'];
        $this->assertTrue($data['help_android_send_credentials']);
        $this->assertFalse($data['help_windows_send_credentials']);

        // A page cached from before the option sends no such field.
        $controller->save(Request::create('/', 'POST', ['text_help' => 'x']));
        $this->assertSame('1', BotText::get('help_android_send_credentials'));
    }

    private function lastParseMode(int $chatId): ?string
    {
        $mode = null;
        foreach ($this->sent as $r) {
            if (str_ends_with($r->url(), '/sendMessage') && (string) ($r->data()['chat_id'] ?? '') === (string) $chatId) {
                $mode = $r->data()['parse_mode'] ?? null;
            }
        }
        return $mode;
    }
}
