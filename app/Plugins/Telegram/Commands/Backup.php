<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\BotSetting;
use App\Plugins\Telegram\Telegram;
use App\Console\Commands\SendScheduledBackup;

class Backup extends Telegram {
    public $command = '/backup';
    public $description = 'بکاپ خودکار دیتابیس (مخصوص ادمین)';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user || !$user->is_admin) {
            $this->telegramService->sendMessage($message->chat_id, "❌ این دستور فقط برای ادمین است.");
            return;
        }

        $arg = isset($message->args[0]) ? trim($message->args[0]) : null;

        if ($arg === null || $arg === 'status') {
            $interval = (int) BotSetting::get('backup_interval', 0);
            $status = $interval > 0 ? "فعال — هر {$interval} دقیقه" : "خاموش";
            $this->telegramService->sendMessage($message->chat_id,
                "🗄 وضعیت بکاپ خودکار: {$status}\n\n" .
                "• تنظیم بازه: /backup 60  (هر ۶۰ دقیقه)\n" .
                "• خاموش کردن: /backup 0\n" .
                "• بکاپ فوری: /backup now");
            return;
        }

        if ($arg === 'now') {
            $this->telegramService->sendMessage($message->chat_id, "⏳ در حال تهیه‌ی بکاپ...");
            $err = SendScheduledBackup::performBackup((int) $message->chat_id);
            if ($err) {
                $this->telegramService->sendMessage($message->chat_id, "❌ خطا در بکاپ:\n" . $err);
            }
            return;
        }

        $minutes = (int) $arg;
        if ($minutes <= 0) {
            BotSetting::set('backup_interval', 0);
            $this->telegramService->sendMessage($message->chat_id, "🛑 بکاپ خودکار خاموش شد.");
            return;
        }

        BotSetting::set('backup_interval', $minutes);
        BotSetting::set('backup_admin_chat', (string) $message->chat_id);
        BotSetting::set('backup_last_run', (string) time());
        $this->telegramService->sendMessage($message->chat_id,
            "✅ بکاپ خودکار فعال شد.\nهر {$minutes} دقیقه یک بکاپ گرفته و همین‌جا برایتان ارسال می‌شود.\n\n(خاموش کردن: /backup 0)");
    }
}
