<?php

namespace App\Services\Telegram;

use App\Models\BotSetting;
use App\Models\BotText;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram's own ids for the smart bot's download files.
 *
 * A bot can upload at most 50 MB through the Bot API, and the Android builds are
 * bigger, so those went out as a link. A file Telegram already holds, though, can
 * be sent to anyone by its file_id at any size. So an admin sends each big file to
 * the bot once and the bot keeps the id; a small file is uploaded once and then
 * re-sent by id instead of 48 MB again on every tap.
 *
 * 🔑 An id belongs to the panel's own copy of the file (bot_files/<name>) at its
 * exact size. A new version is a new download under a new name, so an id can never
 * send the previous version: until an admin sends the new file, users get the link.
 */
class BotFileCache
{
    /** Bot API upload limit; a static so tests can lower it. */
    public static int $uploadLimit = 50 * 1024 * 1024;

    private const PLATFORMS = ['android', 'ios', 'windows'];

    private const PLATFORM_NAMES = ['android' => 'اندروید', 'ios' => 'iOS', 'windows' => 'ویندوز'];

    /** The id stored for [file] (relative to public/), if it was stored for this exact size. */
    public static function get(string $file, int $size): ?string
    {
        $data = json_decode((string) BotSetting::get(self::key($file), ''), true);
        if (!is_array($data) || (int) ($data['size'] ?? -1) !== $size || empty($data['file_id'])) {
            return null;
        }
        return (string) $data['file_id'];
    }

    public static function put(string $file, int $size, string $fileId, string $name = ''): void
    {
        BotSetting::set(self::key($file), json_encode([
            'file_id' => $fileId,
            'size' => $size,
            'name' => $name,
            'at' => time(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Drops what is known about [file]: its id, and that the admins were asked for it. */
    public static function forget(string $file): void
    {
        BotSetting::whereIn('key', [self::key($file), self::askedKey($file)])->delete();
    }

    /**
     * Every file link of the smart bot whose copy is on disk.
     *
     * @return list<array{platform: string, label: string, file: string, size: int, name: string}>
     */
    public static function fileLinks(): array
    {
        $out = [];
        foreach (self::PLATFORMS as $platform) {
            $links = json_decode(BotText::get('help_' . $platform . '_links', '[]'), true);
            foreach (is_array($links) ? $links : [] as $lk) {
                if (($lk['type'] ?? '') !== 'file' || empty($lk['file'])) {
                    continue;
                }
                $path = public_path(ltrim((string) $lk['file'], '/'));
                $size = is_file($path) ? (int) filesize($path) : 0;
                if ($size <= 0) {
                    continue;
                }
                $out[] = [
                    'platform' => $platform,
                    'label' => trim((string) ($lk['label'] ?? '')),
                    'file' => (string) $lk['file'],
                    'size' => $size,
                    'name' => self::originalName($lk),
                ];
            }
        }
        return $out;
    }

    /** File links whose copy has exactly [size] bytes - what a document the admin sends is matched by. */
    public static function matching(int $size): array
    {
        return array_values(array_filter(self::fileLinks(), fn ($l) => $l['size'] === $size));
    }

    /** Files too big to upload that no admin has sent yet. */
    public static function missing(): array
    {
        return array_values(array_filter(
            self::fileLinks(),
            fn ($l) => $l['size'] > self::$uploadLimit && self::get($l['file'], $l['size']) === null,
        ));
    }

    /** "Android — new phones" as an admin would recognise it. */
    public static function describe(array $link): string
    {
        $platform = self::PLATFORM_NAMES[$link['platform']] ?? $link['platform'];
        return $link['label'] !== '' ? "{$platform} — {$link['label']}" : $platform;
    }

    /**
     * Tells the admins, once per file, which big files to send to the bot.
     * Best effort: a Telegram that cannot be reached must not break the caller.
     */
    public static function askAdmins(?array $links = null): void
    {
        $links = array_values(array_filter(
            $links ?? self::missing(),
            fn ($l) => BotSetting::get(self::askedKey($l['file'])) === null,
        ));
        $token = (string) config('v2board.telegram_bot_token', '');
        if (!$links || $token === '') {
            return;
        }
        $admins = User::where('is_admin', 1)->whereNotNull('telegram_id')->pluck('telegram_id')
            ->map(fn ($v) => (string) $v)->filter()->unique()->values()->all();
        if (!$admins) {
            return;
        }

        $lines = array_map(
            fn ($l) => '• ' . self::describe($l) . "\n   {$l['name']} (" . self::humanSize($l['size']) . ')',
            $links,
        );
        $text = "📦 این فایل‌ها از ۵۰ مگابایت بزرگ‌ترند و تلگرام اجازه نمی‌دهد ربات خودش آپلودشان کند؛ "
            . "فعلاً کاربران لینک دانلود می‌گیرند.\n\n" . implode("\n", $lines)
            . "\n\nهر کدام را یک بار برای همین ربات بفرستید تا از این به بعد به‌صورت فایل برای کاربران ارسال شود.";

        $delivered = false;
        foreach ($admins as $chatId) {
            try {
                $resp = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
                $delivered = $delivered || $resp->json('ok') === true;
            } catch (\Throwable $e) {
                Log::warning('bot file request to admin failed', ['msg' => $e->getMessage()]);
            }
        }
        // Only a request someone received counts as asked; otherwise the next
        // tap or save tries again.
        if (!$delivered) {
            return;
        }
        foreach ($links as $l) {
            BotSetting::set(self::askedKey($l['file']), (string) time());
        }
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    /** The release's own file name, from the link the admin entered; the copy on disk is renamed. */
    private static function originalName(array $link): string
    {
        $name = basename((string) (parse_url((string) ($link['url'] ?? ''), PHP_URL_PATH) ?: ''));
        return $name !== '' ? rawurldecode($name) : basename((string) $link['file']);
    }

    private static function key(string $file): string
    {
        return 'tgfile:' . basename($file);
    }

    private static function askedKey(string $file): string
    {
        return 'tgfile_asked:' . basename($file);
    }
}
