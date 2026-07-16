<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BotSetting;
use Illuminate\Support\Facades\Log;

class SendScheduledBackup extends Command
{
    protected $signature = 'backup:scheduled';
    protected $description = 'ارسال خودکار بکاپ دیتابیس به ادمین طبق بازه‌ی تنظیم‌شده';

    public function handle()
    {
        $interval = (int) BotSetting::get('backup_interval', 0);
        if ($interval <= 0) return;

        $chatId = BotSetting::get('backup_admin_chat');
        if (!$chatId) return;

        $last = (int) BotSetting::get('backup_last_run', 0);
        if (time() - $last < $interval * 60) return;

        BotSetting::set('backup_last_run', (string) time());

        $err = self::performBackup((int) $chatId);
        if ($err) {
            Log::error('Scheduled backup failed', ['error' => $err]);
        }
    }

    public static function performBackup(int $chatId): ?string
    {
        $token = config('v2board.telegram_bot_token');
        if (!$token) return 'توکن ربات تنظیم نشده است.';

        $conn = config('database.default', 'mysql');
        $db   = config("database.connections.{$conn}");
        $host = $db['host'] ?? '127.0.0.1';
        $port = $db['port'] ?? 3306;
        $name = $db['database'] ?? '';
        $user = $db['username'] ?? '';
        $pass = (string) ($db['password'] ?? '');
        if (!$name) return 'تنظیمات دیتابیس ناقص است.';

        $ts   = date('Ymd_His');
        $dir  = storage_path('app/backups');
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $file = $dir . "/v2b_backup_{$name}_{$ts}.sql.gz";

        $cmd = sprintf(
            'mysqldump --no-tablespaces --single-transaction --quick --default-character-set=utf8mb4 -h%s -P%s -u%s %s | gzip > %s',
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $user),
            escapeshellarg((string) $name),
            escapeshellarg($file)
        );

        if (!function_exists('proc_open')) {
            return 'proc_open غیرفعال است؛ نمی‌توان mysqldump را اجرا کرد.';
        }

        $env  = array_merge(getenv() ?: [], ['MYSQL_PWD' => $pass]);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, null, $env);
        if (!is_resource($proc)) return 'اجرای mysqldump ناموفق بود.';
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        proc_close($proc);

        if (!file_exists($file) || filesize($file) < 200) {
            if (file_exists($file)) @unlink($file);
            return 'بکاپ خالی/ناموفق: ' . trim((string) $stderr);
        }

        $size = filesize($file);
        self::pruneBackups($dir, 3);
        if ($size > 49 * 1024 * 1024) {
            return 'حجم بکاپ ' . round($size / 1048576, 1) . 'MB است؛ روی سرور ذخیره شد ولی برای ارسال در تلگرام بزرگ است (سقف ۵۰MB).';
        }

        $when = \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::now('Asia/Tehran'))->format('Y/m/d H:i');
        $caption = "🗄 بکاپ دیتابیس\n📅 {$when}\n💾 " . round($size / 1024, 1) . " KB";

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendDocument");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id'  => $chatId,
                'caption'  => $caption,
                'document' => new \CURLFile($file, 'application/gzip', basename($file)),
            ],
            CURLOPT_TIMEOUT => 180,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($code !== 200) {
            return 'ارسال فایل به تلگرام ناموفق: ' . ($cerr ?: substr((string) $resp, 0, 300));
        }
        return null;
    }

    private static function pruneBackups(string $dir, int $keep): void
    {
        $files = glob($dir . '/v2b_backup_*.sql.gz');
        if (!$files) return;
        usort($files, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
