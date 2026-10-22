<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\SmartBotController;
use App\Models\BotSetting;
use App\Models\BotText;
use App\Models\User;
use App\Services\Telegram\BotFileCache;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * The smart bot's download files (help sections): big Android builds used to go
 * out as a link because a bot may upload at most 50 MB. Telegram is faked; the
 * upload limit is lowered to 100 bytes so the files can stay tiny.
 */
class TelegramBotFilesTest extends TestCase
{
    use PanelFixtures;

    /** @var list<SentRequest> */
    private array $sent = [];

    /** @var list<string> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_bot_texts']);
        config(['v2board.telegram_bot_token' => 'test-token']);
        BotFileCache::$uploadLimit = 100;

        Http::fake(function (SentRequest $request) {
            $this->sent[] = $request;
            if (str_ends_with($request->url(), '/sendDocument')) {
                if ($request->isMultipart()) {
                    return Http::response(['ok' => true, 'result' => ['document' => ['file_id' => 'UPLOADED-ID']]]);
                }
                if ($request['document'] === 'GONE-ID') {
                    return Http::response(['ok' => false, 'description' => 'Bad Request: wrong file identifier'], 400);
                }
                return Http::response(['ok' => true, 'result' => ['document' => ['file_id' => $request['document']]]]);
            }
            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $path) {
            @unlink($path);
        }
        BotFileCache::$uploadLimit = 50 * 1024 * 1024;
        parent::tearDown();
    }

    /** A downloaded copy in public/bot_files, as SmartBotController leaves it. */
    private function copyOnDisk(int $bytes): string
    {
        $dir = public_path('bot_files');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = 'bf_test_' . bin2hex(random_bytes(4)) . '.apk';
        file_put_contents("$dir/$name", str_repeat('x', $bytes));
        $this->made[] = "$dir/$name";
        return 'bot_files/' . $name;
    }

    private function androidLinks(array $links): void
    {
        BotText::set('help_android_text', 'نصب برنامه');
        BotText::set('help_android_links', json_encode($links, JSON_UNESCAPED_UNICODE));
    }

    private function link(string $file, string $label = 'گوشی های جدید', string $version = '2.2.5'): array
    {
        return [
            'label' => $label,
            'type' => 'file',
            'url' => "https://github.com/PoriyaVali/drmobile-updates/releases/download/$version/DoctorMobile-$version-android-arm64-v8a.apk",
            'file' => $file,
        ];
    }

    private function tapAndroidHelp(User $user): void
    {
        $this->sent = [];
        (new TelegramBotService())->handleWebhook(['callback_query' => [
            'id' => 'cb' . uniqid(),
            'data' => 'help_android',
            'from' => ['id' => (int) $user->telegram_id],
            'message' => ['chat' => ['id' => (int) $user->telegram_id], 'message_id' => 5],
        ]]);
    }

    private function sendDocument(User $from, string $fileId, int $size, string $name = 'DoctorMobile-2.2.5-android-arm64-v8a.apk'): void
    {
        $this->sent = [];
        (new TelegramBotService())->handleWebhook(['message' => [
            'chat' => ['id' => (int) $from->telegram_id],
            'from' => ['id' => (int) $from->telegram_id],
            'document' => ['file_id' => $fileId, 'file_size' => $size, 'file_name' => $name],
        ]]);
    }

    /** @return list<SentRequest> */
    private function sentTo(string $method, ?int $chatId = null): array
    {
        return array_values(array_filter($this->sent, function (SentRequest $r) use ($method, $chatId) {
            if (!str_ends_with($r->url(), '/' . $method)) {
                return false;
            }
            if ($chatId === null) {
                return true;
            }
            if ($r->isMultipart()) {
                foreach ($r->data() as $part) {
                    if (($part['name'] ?? '') === 'chat_id') {
                        return (string) $part['contents'] === (string) $chatId;
                    }
                }
                return false;
            }
            return (string) $r['chat_id'] === (string) $chatId;
        }));
    }

    private function linkButtons(int $chatId): array
    {
        return array_values(array_filter($this->sentTo('sendMessage', $chatId), function (SentRequest $r) {
            return str_contains((string) $r['reply_markup'], 'bot_files');
        }));
    }

    public function testABigFileTheBotCannotUploadGoesAsALinkAndTheAdminsAreAskedOnce()
    {
        $admin = $this->makeUser(['telegram_id' => 900, 'is_admin' => 1]);
        $user = $this->makeUser(['telegram_id' => 901]);
        $this->androidLinks([$this->link($this->copyOnDisk(200))]);

        $this->tapAndroidHelp($user);

        $this->assertCount(1, $this->linkButtons(901), 'the user gets the link');
        $this->assertCount(0, $this->sentTo('sendDocument'));
        $asked = $this->sentTo('sendMessage', 900);
        $this->assertCount(1, $asked, 'the admin is told which file to send');
        $this->assertStringContainsString('DoctorMobile-2.2.5-android-arm64-v8a.apk', (string) $asked[0]['text']);

        $this->tapAndroidHelp($user);
        $this->assertCount(0, $this->sentTo('sendMessage', 900), 'only once per file');
    }

    public function testOnceAnAdminSendsTheFileEveryUserGetsItAsAFile()
    {
        $admin = $this->makeUser(['telegram_id' => 910, 'is_admin' => 1]);
        $user = $this->makeUser(['telegram_id' => 911]);
        $file = $this->copyOnDisk(200);
        $this->androidLinks([$this->link($file)]);

        $this->sendDocument($admin, 'BIG-ID', 200);

        $this->assertSame('BIG-ID', BotFileCache::get($file, 200));
        $reply = $this->sentTo('sendMessage', 910);
        $this->assertCount(1, $reply);
        $this->assertStringContainsString('✅', (string) $reply[0]['text']);

        $this->tapAndroidHelp($user);

        $docs = $this->sentTo('sendDocument', 911);
        $this->assertCount(1, $docs);
        $this->assertFalse($docs[0]->isMultipart(), 'sent by id, not uploaded');
        $this->assertSame('BIG-ID', $docs[0]['document']);
        $this->assertSame('گوشی های جدید', $docs[0]['caption']);
        $this->assertCount(0, $this->linkButtons(911), 'and no link');
    }

    public function testADocumentFromSomeoneWhoIsNotAnAdminIsNotStored()
    {
        $user = $this->makeUser(['telegram_id' => 920]);
        $file = $this->copyOnDisk(200);
        $this->androidLinks([$this->link($file)]);

        $this->sendDocument($user, 'NOT-TRUSTED', 200);

        $this->assertNull(BotFileCache::get($file, 200));
    }

    public function testAFileOfAnotherSizeIsNotTakenForTheRelease()
    {
        $admin = $this->makeUser(['telegram_id' => 925, 'is_admin' => 1]);
        $file = $this->copyOnDisk(200);
        $this->androidLinks([$this->link($file)]);

        $this->sendDocument($admin, 'WRONG-ID', 199, 'DoctorMobile-2.2.4-android-arm64-v8a.apk');

        $this->assertNull(BotFileCache::get($file, 200));
        $reply = $this->sentTo('sendMessage', 925);
        $this->assertCount(1, $reply, 'the admin is told it did not match');
        $this->assertStringContainsString('⚠️', (string) $reply[0]['text']);
    }

    public function testASmallFileIsUploadedOnceAndSentByIdAfterwards()
    {
        $user = $this->makeUser(['telegram_id' => 930]);
        $file = $this->copyOnDisk(50);
        $this->androidLinks([$this->link($file)]);

        $this->tapAndroidHelp($user);
        $first = $this->sentTo('sendDocument', 930);
        $this->assertCount(1, $first);
        $this->assertTrue($first[0]->isMultipart(), 'the first one is an upload');
        $this->assertSame('UPLOADED-ID', BotFileCache::get($file, 50));

        $this->tapAndroidHelp($user);
        $second = $this->sentTo('sendDocument', 930);
        $this->assertCount(1, $second);
        $this->assertFalse($second[0]->isMultipart(), 'then by id');
        $this->assertSame('UPLOADED-ID', $second[0]['document']);
    }

    public function testANewVersionNeverGoesOutWithTheOldVersionsId()
    {
        $admin = $this->makeUser(['telegram_id' => 940, 'is_admin' => 1]);
        $user = $this->makeUser(['telegram_id' => 941]);
        $old = $this->copyOnDisk(200);
        $this->androidLinks([$this->link($old)]);
        $this->sendDocument($admin, 'OLD-ID', 200);

        // The admin saves the next release: a new download, even of the same size.
        $new = $this->copyOnDisk(200);
        $this->androidLinks([$this->link($new, 'گوشی های جدید', '2.2.6')]);
        $this->tapAndroidHelp($user);

        $this->assertCount(0, $this->sentTo('sendDocument', 941));
        $this->assertCount(1, $this->linkButtons(941), 'a link until the new file is sent');
    }

    public function testAnIdTelegramNoLongerKnowsIsDroppedAndTheFileStillArrives()
    {
        $user = $this->makeUser(['telegram_id' => 950]);
        $file = $this->copyOnDisk(50);
        $this->androidLinks([$this->link($file)]);
        BotFileCache::put($file, 50, 'GONE-ID');

        $this->tapAndroidHelp($user);

        $docs = $this->sentTo('sendDocument', 950);
        $this->assertCount(2, $docs, 'the refused id, then an upload');
        $this->assertTrue($docs[1]->isMultipart());
        $this->assertSame('UPLOADED-ID', BotFileCache::get($file, 50), 'and the fresh id replaces it');
    }

    public function testTheFilesCommandShowsWhatWillBeSent()
    {
        $admin = $this->makeUser(['telegram_id' => 960, 'is_admin' => 1]);
        $big = $this->copyOnDisk(200);
        $this->androidLinks([$this->link($big)]);

        $this->sent = [];
        (new TelegramBotService())->handleWebhook(['message' => [
            'text' => '/files',
            'chat' => ['id' => 960],
            'from' => ['id' => 960],
        ]]);

        $reply = $this->sentTo('sendMessage', 960);
        $this->assertCount(1, $reply);
        $this->assertStringContainsString('⚠️', (string) $reply[0]['text']);
        $this->assertStringContainsString('DoctorMobile-2.2.5-android-arm64-v8a.apk', (string) $reply[0]['text']);
    }

    public function testRemovingALinkInThePanelForgetsItsId()
    {
        $file = $this->copyOnDisk(200);
        $this->androidLinks([$this->link($file)]);
        BotFileCache::put($file, 200, 'BIG-ID');

        (new SmartBotController())->save(Request::create('/', 'POST', ['help_android_links' => []]));

        $this->assertFileDoesNotExist(public_path($file));
        $this->assertNull(BotSetting::get('tgfile:' . basename($file)));
    }
}
