<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Rebind extends Telegram {
   public $command = '/rebind';
   public $description = 'اتصال مجدد اکانت تلگرام به وب‌سایت';

   public function handle($message, $match = []) {
       if (!$message->is_private) return;
       
       if (!isset($message->args[0])) {
           $this->telegramService->sendMessage($message->chat_id, 
               "📝 فرمت دستور:\n\n" .
               "🔹 یوزر → یوزر (بدون امنیت):\n" .
               "/rebind [آدرس_اشتراک]\n\n" .
               "🔹 یوزر → ادمین (نیاز به رمز):\n" .
               "/rebind [آدرس_اشتراک] [رمز_ادمین]\n\n" .
               "🔹 ادمین → ادمین (نیاز به رمز):\n" .
               "/rebind [آدرس_اشتراک] [رمز_ادمین]\n\n" .
               "🔹 ادمین → یوزر (بدون امنیت):\n" .
               "/rebind [آدرس_اشتراک]\n\n" .
               "مثال:\n" .
               "/rebind https://site.com/subscribe?token=abc123\n" .
               "/rebind https://site.com/subscribe?token=xyz789 admin_password", 
               'markdown');
           return;
       }
       $subscribeUrl = $message->args[0];
       $password = $message->args[1] ?? null;
       $parsedUrl = parse_url($subscribeUrl);
       if (!isset($parsedUrl['query'])) {
           $this->telegramService->sendMessage($message->chat_id, '❌ فرمت آدرس اشتراک نامعتبر است', 'markdown');
           return;
       }
       parse_str($parsedUrl['query'], $query);
       $token = $query['token'] ?? null;
       if (!$token) {
           $this->telegramService->sendMessage($message->chat_id, '❌ توکن معتبری در آدرس اشتراک یافت نشد', 'markdown');
           return;
       }
       $targetUser = User::where('token', $token)->first();
       if (!$targetUser) {
           $this->telegramService->sendMessage($message->chat_id, '❌ کاربر وجود ندارد، لطفاً آدرس اشتراک را بررسی کنید', 'markdown');
           return;
       }
       $currentUser = User::where('telegram_id', $message->chat_id)->first();
       $isCurrentUserAdmin = $currentUser ? $currentUser->is_admin : false;
       // A reseller account is guarded like an admin one: it can sell plans
       // from its balance, so a subscription link alone is not enough.
       $isTargetUserAdmin = $targetUser->is_admin || $targetUser->is_staff;
       if ($this->needsPasswordVerification($isCurrentUserAdmin, $isTargetUserAdmin)) {
           if (!$password) {
               $this->telegramService->sendMessage($message->chat_id, 
                   "🔐 برای اتصال به اکانت ادمین، رمز عبور لازم است\n\n" .
                   "فرمت: /rebind [آدرس_اشتراک] [رمز_عبور]\n\n" .
                   "مثال: /rebind {$subscribeUrl} admin_password", 
                   'markdown');
               return;
           }
           // The login page's check and wrong-password limit, so this is not a
           // second door with unlimited guesses (and old-algorithm passwords
           // that password_verify cannot read still work).
           $verdict = \App\Services\AuthService::checkPasswordWithLimit($targetUser, (string)$password);
           if ($verdict === 'locked') {
               $this->telegramService->sendMessage($message->chat_id, '❌ تعداد تلاش‌های ناموفق زیاد است؛ بعداً دوباره تلاش کنید', 'markdown');
               return;
           }
           if ($verdict !== null) {
               $this->telegramService->sendMessage($message->chat_id, '❌ رمز عبور اشتباه است', 'markdown');
               return;
           }
       }
       // بررسی تداخل
       $existingUser = User::where('telegram_id', $message->chat_id)->first();
       if ($existingUser && $existingUser->id !== $targetUser->id) {
           $this->telegramService->sendMessage($message->chat_id, 
               "❌ اکانت تلگرام فعلی به کاربر دیگری متصل است\n\n" .
               "📧 اکانت فعلی: {$existingUser->email}\n" .
               "📧 اکانت درخواستی: {$targetUser->email}\n\n" .
               "برای قطع اتصال /unbind ارسال کنید", 
               'markdown');
           return;
       }
       $targetUser->telegram_id = $message->chat_id;
       if (!$targetUser->save()) {
           $this->telegramService->sendMessage($message->chat_id, '❌ اتصال مجدد ناموفق بود، لطفاً دوباره تلاش کنید', 'markdown');
           return;
       }
       $targetUserType = $targetUser->is_admin ? '👑 ادمین' : '👤 کاربر';
       $currentUserType = $isCurrentUserAdmin ? '👑 ادمین' : '👤 کاربر';
       $this->telegramService->sendMessage($message->chat_id, 
           "✅ اتصال مجدد با موفقیت انجام شد!\n\n" .
           "🔄 {$currentUserType} → {$targetUserType}\n\n" .
           "📧 ایمیل: {$targetUser->email}\n" .
           "🆔 شناسه: {$targetUser->id}\n" .
           "🔰 نوع کاربر: {$targetUserType}\n\n" .
           "حالا می‌توانید درخواست عضویت در کانال را ارسال کنید.", 
           'markdown');
   }

   private function needsPasswordVerification($isCurrentUserAdmin, $isTargetUserAdmin)
   {
       if (!$isCurrentUserAdmin && !$isTargetUserAdmin) {
           return false;
       }
       if (!$isCurrentUserAdmin && $isTargetUserAdmin) {
           return true;
       }
       if ($isCurrentUserAdmin && $isTargetUserAdmin) {
           return true;
       }
       if ($isCurrentUserAdmin && !$isTargetUserAdmin) {
           return false;
       }
       return false;
   }
}
