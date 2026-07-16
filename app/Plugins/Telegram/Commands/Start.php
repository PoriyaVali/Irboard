<?php
namespace App\Plugins\Telegram\Commands;
use App\Plugins\Telegram\Telegram;
use App\Models\User;
use App\Models\InviteCode;

class Start extends Telegram {
    public $command = '/start';
    public $description = 'شروع کار با ربات';
    
    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        
        $chatId = $message->chat_id;
        
        // بررسی پارامتر
        if (!empty($message->args[0])) {
            $param = $message->args[0];
            
            // پردازش bind_TOKEN
            if (strpos($param, 'bind_') === 0) {
                $token = substr($param, 5);
                $this->handleBind($chatId, $token);
                return;
            }
            
            // پردازش invite_CODE
            if (strpos($param, 'invite_') === 0) {
                $code = substr($param, 7);
                $this->handleInvite($chatId, $code);
                $this->showWelcome($chatId);
                return;
            }
        }
        
        // نمایش منوی خوش‌آمدگویی
        $this->showWelcome($chatId);
    }
    
    private function handleBind($chatId, $token) {
        $siteUser = User::where('token', $token)->first();
        
        if (!$siteUser) {
            $this->telegramService->sendMessage($chatId, "❌ توکن نامعتبر است.");
            $this->showWelcome($chatId);
            return;
        }
        
        // بررسی اتصال قبلی
        if ($siteUser->telegram_id && $siteUser->telegram_id != $chatId) {
            $this->telegramService->sendMessage($chatId, "❌ این اکانت قبلاً به تلگرام دیگری متصل شده است.");
            $this->showWelcome($chatId);
            return;
        }
        
        // حذف اتصال قبلی این تلگرام
        $currentUser = User::where('telegram_id', $chatId)->first();
        if ($currentUser && $currentUser->id !== $siteUser->id) {
            $currentUser->telegram_id = null;
            $currentUser->save();
        }
        
        // اتصال جدید
        $siteUser->telegram_id = $chatId;
        $siteUser->save();
        
        $this->telegramService->sendMessage($chatId, "✅ اتصال با موفقیت انجام شد!\n\n📧 ایمیل: {$siteUser->email}");
        $this->showWelcome($chatId);
    }
    
    private function handleInvite($chatId, $code) {
        $invite = InviteCode::where('code', $code)->where('status', 0)->first();
        $user = User::where('telegram_id', $chatId)->first();
        
        if (!$invite) {
            $this->telegramService->sendMessage($chatId, "❌ کد دعوت نامعتبر یا منقضی شده است.");
            return;
        }
        
        if (!$user) {
            $this->telegramService->sendMessage($chatId, "❌ ابتدا در ربات ثبت‌نام کنید.");
            return;
        }
        
        if ($invite->user_id === $user->id) {
            $this->telegramService->sendMessage($chatId, "❌ نمی‌توانید از کد دعوت خودتان استفاده کنید.");
            return;
        }
        
        if ($user->invite_user_id) {
            $this->telegramService->sendMessage($chatId, "ℹ️ شما قبلاً با کد دعوت دیگری ثبت‌نام کرده‌اید.");
            return;
        }
        
        // ثبت زیرمجموعه
        $user->invite_user_id = $invite->user_id;
        $user->save();
        
        // افزایش بازدید
        $invite->increment('pv');
        
        // پیدا کردن معرف
        $referrer = User::find($invite->user_id);
        $referrerName = $referrer ? $referrer->email : 'ناشناس';
        
        $this->telegramService->sendMessage($chatId, "✅ شما با کد دعوت *{$code}* وارد شدید.\n👤 معرف: {$referrerName}", 'Markdown');
    }
    
    private function showWelcome($chatId) {
        $siteName = config("v2board.app_name", "سایت ما");
        $text  = "🌟 به *{$siteName}* خوش آمدید!\n";
        $text .= "─────────────\n";
        $text .= "🤖 ربات خرید و مدیریت اشتراک\n\n";
        $text .= "👇 از منوی پایین، گزینه‌ی موردنظر را انتخاب کنید";
        
        $keyboard = [
            'keyboard' => [
                [['text' => '🛒 خرید اشتراک'], ['text' => '👤 حساب کاربری']],
                [['text' => '💰 کیف پول'], ['text' => '📦 سرویس‌های من']],
                [['text' => '🎁 گیفت کارت'], ['text' => '👥 زیرمجموعه']],
                [['text' => '📞 پشتیبانی'], ['text' => '📚 راهنما']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
        
        $this->telegramService->sendMessageWithKeyboard($chatId, $text, $keyboard, 'Markdown');
    }
}
