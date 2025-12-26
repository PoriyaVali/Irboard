<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class UnBind extends Telegram {
    public $command = '/unbind';
    public $description = 'جداسازی حساب تلگرام از وب‌سایت';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        $telegramService = $this->telegramService;
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, 'اطلاعات کاربری شما یافت نشد، لطفاً ابتدا حساب خود را متصل کنید', 'markdown');
            return;
        }
        $user->telegram_id = NULL;
        if (!$user->save()) {
            abort(500, 'جداسازی ناموفق بود');
        }
        $telegramService->sendMessage($message->chat_id, 'جداسازی با موفقیت انجام شد', 'markdown');
    }
}

?>
