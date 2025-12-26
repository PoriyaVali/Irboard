<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class Admin extends Telegram {
    public $command = '/admin';
    public $description = 'پنل مدیریت ادمین';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        
        // بررسی اینکه کاربر ادمین است یا نه
        $currentUser = User::where('telegram_id', $message->chat_id)->first();
        if (!$currentUser || !$currentUser->is_admin) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ شما دسترسی ادمین ندارید\n\n" .
                "این دستور فقط برای ادمین‌ها قابل استفاده است.");
            return;
        }
        
        if (!isset($message->args[0])) {
            $this->showAdminMenu($message);
            return;
        }
        
        $action = $message->args[0];
        
        switch ($action) {
            case 'stats':
                $this->showStats($message);
                break;
            case 'users':
                $this->showUsers($message);
                break;
            case 'ban':
                $this->banUser($message);
                break;
            case 'unban':
                $this->unbanUser($message);
                break;
            case 'delete':
                $this->deleteUser($message);
                break;
            case 'search':
                $this->searchUser($message);
                break;
            case 'profile':
                $this->showUserProfile($message);
                break;
            case 'admins':
                $this->showAdmins($message);
                break;
            case 'makeadmin':
                $this->makeAdmin($message);
                break;
            case 'removeadmin':
                $this->removeAdmin($message);
                break;
            case 'telegram':
                $this->telegramStats($message);
                break;
            case 'uuid':
                $this->changeUuid($message);
                break;
            case 'resetuuid':
                $this->resetUuid($message);
                break;
            case 'token':
                $this->changeToken($message);
                break;
            case 'resettoken':
                $this->resetToken($message);
                break;
            default:
                $this->showAdminMenu($message);
        }
    }
    
    private function showAdminMenu($message)
    {
        $menuText = "🔰 پنل مدیریت ادمین\n\n" .
                   "📊 آمار و اطلاعات:\n" .
                   "• /admin stats - آمار کلی سیستم\n" .
                   "• /admin users - لیست کاربران\n" .
                   "• /admin admins - لیست ادمین‌ها\n" .
                   "• /admin telegram - آمار تلگرام\n\n" .
                   "👥 مدیریت کاربران:\n" .
                   "• /admin search [ایمیل/ID] - جستجو ساده\n" .
                   "• /admin profile [ایمیل/ID] - پروفایل کامل\n" .
                   "• /admin ban [ایمیل/ID] - مسدود کردن\n" .
                   "• /admin unban [ایمیل/ID] - رفع مسدودیت\n" .
                   "• /admin delete [ایمیل/ID] - حذف کاربر\n\n" .
                   "👑 مدیریت ادمین‌ها:\n" .
                   "• /admin makeadmin [ایمیل/ID] - ادمین کردن\n" .
                   "• /admin removeadmin [ایمیل/ID] - حذف ادمین\n\n" .
                   "🆔 مدیریت UUID:\n" .
                   "• /admin uuid [ایمیل] [UUID_جدید] - تغییر UUID\n" .
                   "• /admin resetuuid [ایمیل] - تولید UUID جدید\n\n" .
                   "🔑 مدیریت Token:\n" .
                   "• /admin token [ایمیل] [Token_جدید] - تغییر Token\n" .
                   "• /admin resettoken [ایمیل] - تولید Token جدید\n\n" .
                   "مثال: /admin profile user@example.com";
        
        $this->telegramService->sendMessage($message->chat_id, $menuText);
    }
    
    private function showStats($message)
    {
        // آمار کلی
        $totalUsers = User::count();
        $activeUsers = User::where('banned', 0)->count();
        $bannedUsers = User::where('banned', 1)->count();
        $admins = User::where('is_admin', 1)->count();
        $telegramConnected = User::whereNotNull('telegram_id')->count();
        
        // آمار ترافیک
        $totalTraffic = User::sum('transfer_enable');
        $usedTraffic = User::sum(DB::raw('u + d'));
        
        // تبدیل به GB
        $totalTrafficGB = round($totalTraffic / (1024*1024*1024), 2);
        $usedTrafficGB = round($usedTraffic / (1024*1024*1024), 2);
        
        // کاربران جدید امروز
        $todayUsers = User::whereDate('created_at', today())->count();
        
        $statsText = "📊 آمار کلی سیستم\n\n" .
                    "👥 کاربران:\n" .
                    "• کل کاربران: {$totalUsers}\n" .
                    "• کاربران فعال: {$activeUsers}\n" .
                    "• کاربران مسدود: {$bannedUsers}\n" .
                    "• ادمین‌ها: {$admins}\n" .
                    "• متصل به تلگرام: {$telegramConnected}\n" .
                    "• عضو جدید امروز: {$todayUsers}\n\n" .
                    "📈 ترافیک:\n" .
                    "• کل ترافیک: {$totalTrafficGB} GB\n" .
                    "• ترافیک مصرفی: {$usedTrafficGB} GB\n" .
                    "• درصد مصرف: " . ($totalTrafficGB > 0 ? round(($usedTrafficGB/$totalTrafficGB)*100, 1) : 0) . "%";
        
        $this->telegramService->sendMessage($message->chat_id, $statsText);
    }
    
    private function showUsers($message)
    {
        $page = isset($message->args[1]) ? (int)$message->args[1] : 1;
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        
        $users = User::orderBy('created_at', 'desc')
                    ->offset($offset)
                    ->limit($perPage)
                    ->get();
        
        $total = User::count();
        $totalPages = ceil($total / $perPage);
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربری یافت نشد");
            return;
        }
        
        $usersText = "👥 لیست کاربران (صفحه {$page} از {$totalPages})\n\n";
        
        foreach ($users as $user) {
            $status = $user->banned ? '🔴 مسدود' : '🟢 فعال';
            $type = $user->is_admin ? '👑' : '👤';
            $telegram = $user->telegram_id ? '📱' : '❌';
            
            $usersText .= "{$type} {$user->email}\n";
            $usersText .= "🆔 ID: {$user->id} | {$status} | TG: {$telegram}\n\n";
        }
        
        if ($totalPages > 1) {
            $usersText .= "📄 صفحه بعدی: /admin users " . ($page + 1);
        }
        
        $this->telegramService->sendMessage($message->chat_id, $usersText);
    }
    
    private function searchUser($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل یا ID کاربر را وارد کنید\n\n" .
                "💡 برای اطلاعات کامل از دستور زیر استفاده کنید:\n" .
                "/admin profile [ایمیل/ID]\n\n" .
                "مثال: /admin search user@example.com");
            return;
        }
        
        $query = $message->args[1];
        
        // جستجو بر اساس ID یا ایمیل
        if (is_numeric($query)) {
            $user = User::find($query);
        } else {
            $user = User::where('email', 'LIKE', "%{$query}%")->first();
        }
        
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربر یافت نشد");
            return;
        }
        
        $status = $user->banned ? '🔴 مسدود' : '🟢 فعال';
        $type = $user->is_admin ? '👑 ادمین' : '👤 کاربر';
        $telegram = $user->telegram_id ? "📱 متصل" : '❌ متصل نیست';
        
        $traffic = $user->transfer_enable ? round($user->transfer_enable / (1024*1024*1024), 2) : 0;
        $used = round(($user->u + $user->d) / (1024*1024*1024), 2);
        
        $userInfo = "👤 جستجو کاربر (خلاصه)\n\n" .
                   "📧 ایمیل: {$user->email}\n" .
                   "🆔 شناسه: {$user->id}\n" .
                   "🔰 نوع: {$type}\n" .
                   "📊 وضعیت: {$status}\n" .
                   "📱 تلگرام: {$telegram}\n" .
                   "💾 ترافیک: {$used}/{$traffic} GB\n" .
                   "📅 عضویت: " . date('Y-m-d', $user->created_at) . "\n\n" .
                   "💡 برای اطلاعات کامل:\n" .
                   "/admin profile {$user->email}";
        
        $this->telegramService->sendMessage($message->chat_id, $userInfo);
    }
    
    private function showUserProfile($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل یا ID کاربر را وارد کنید\n\n" .
                "فرمت: /admin profile [ایمیل/ID]\n\n" .
                "مثال:\n" .
                "/admin profile user@example.com\n" .
                "/admin profile 123");
            return;
        }
        
        $query = $message->args[1];
        
        // جستجو بر اساس ID یا ایمیل
        if (is_numeric($query)) {
            $user = User::find($query);
        } else {
            $user = User::where('email', 'LIKE', "%{$query}%")->first();
        }
        
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربر یافت نشد");
            return;
        }
        
        // محاسبه اطلاعات مفصل
        $status = $user->banned ? '🔴 مسدود' : '🟢 فعال';
        $type = $user->is_admin ? '👑 ادمین' : '👤 کاربر';
        $telegram = $user->telegram_id ? "📱 متصل (ID: {$user->telegram_id})" : '❌ متصل نیست';
        
        // محاسبه ترافیک
        $transferEnable = $user->transfer_enable ?: 0;
        $uploadBytes = $user->u ?: 0;
        $downloadBytes = $user->d ?: 0;
        $totalUsedBytes = $uploadBytes + $downloadBytes;
        
        $transferEnableGB = round($transferEnable / (1024*1024*1024), 2);
        $uploadGB = round($uploadBytes / (1024*1024*1024), 2);
        $downloadGB = round($downloadBytes / (1024*1024*1024), 2);
        $totalUsedGB = round($totalUsedBytes / (1024*1024*1024), 2);
        $remainingGB = $transferEnableGB - $totalUsedGB;
        
        // محاسبه درصد مصرف
        $usagePercent = $transferEnableGB > 0 ? round(($totalUsedGB / $transferEnableGB) * 100, 1) : 0;
        
        // تاریخ‌ها
        $createdDate = date('Y-m-d H:i:s', $user->created_at);
        $updatedDate = date('Y-m-d H:i:s', $user->updated_at);
        $expiredDate = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : 'نامحدود';
        
        // وضعیت انقضا
        $expiredStatus = '🟢 فعال';
        if ($user->expired_at && $user->expired_at <= time()) {
            $expiredStatus = '🔴 منقضی شده';
        } elseif ($user->expired_at && $user->expired_at <= (time() + 86400 * 7)) {
            $expiredStatus = '🟡 به زودی منقضی می‌شود';
        }
        
        // بالانس
        $balance = $user->balance ? ($user->balance / 100) : 0;
        $commissionBalance = $user->commission_balance ? ($user->commission_balance / 100) : 0;
        
        // محدودیت دستگاه
        $deviceLimit = $user->device_limit ?: 'نامحدود';
        
        // گروه و پلن
        $planInfo = 'هیچ پلنی';
        if ($user->plan_id) {
            $plan = \App\Models\Plan::find($user->plan_id);
            $planInfo = $plan ? $plan->name : "پلن {$user->plan_id} (حذف شده)";
        }
        
        // ساخت لینک‌ها
        $baseUrl = config('v2board.subscribe_url', config('app.url'));
        $subscribeLink = $baseUrl . "/api/v1/client/subscribe?token=" . $user->token;
        
        // ساخت پیام کامل
        $profileText = "👤 پروفایل کامل کاربر\n\n" .
                      "══════════════════════════\n" .
                      "📋 اطلاعات اصلی:\n" .
                      "📧 ایمیل: {$user->email}\n" .
                      "🆔 شناسه: {$user->id}\n" .
                      "🔰 نوع کاربر: {$type}\n" .
                      "📊 وضعیت حساب: {$status}\n" .
                      "📱 تلگرام: {$telegram}\n\n" .
                      
                      "══════════════════════════\n" .
                      "💰 اطلاعات مالی:\n" .
                      "💵 موجودی: {$balance} تومان\n" .
                      "🎁 کمیسیون: {$commissionBalance} تومان\n" .
                      "📦 پلن فعلی: {$planInfo}\n" .
                      "🏷️ گروه: " . ($user->group_id ?: 'پیش‌فرض') . "\n\n" .
                      
                      "══════════════════════════\n" .
                      "📈 اطلاعات ترافیک:\n" .
                      "💾 کل ترافیک: {$transferEnableGB} GB\n" .
                      "📤 آپلود: {$uploadGB} GB\n" .
                      "📥 دانلود: {$downloadGB} GB\n" .
                      "📊 کل مصرف: {$totalUsedGB} GB\n" .
                      "📉 باقیمانده: {$remainingGB} GB\n" .
                      "🔋 درصد مصرف: {$usagePercent}%\n" .
                      "📱 محدودیت دستگاه: {$deviceLimit}\n\n" .
                      
                      "══════════════════════════\n" .
                      "⏰ اطلاعات زمانی:\n" .
                      "📅 تاریخ عضویت: {$createdDate}\n" .
                      "🔄 آخرین بروزرسانی: {$updatedDate}\n" .
                      "⏳ انقضای سرویس: {$expiredDate}\n" .
                      "🚦 وضعیت انقضا: {$expiredStatus}\n\n" .
                      
                      "══════════════════════════\n" .
                      "🔑 اطلاعات فنی:\n" .
                      "🎫 Token: {$user->token}\n" .
                      "🆔 UUID: {$user->uuid}\n\n" .
                      
                      "══════════════════════════\n" .
                      "🔗 لینک‌ها:\n" .
                      "📱 لینک اشتراک:\n{$subscribeLink}\n\n" .
                      
                      "══════════════════════════\n" .
                      "⚡ عملیات سریع:\n" .
                      "🔄 /admin resettoken {$user->email}\n" .
                      "🆔 /admin resetuuid {$user->email}\n" .
                      ($user->banned ? "✅ /admin unban {$user->email}" : "🚫 /admin ban {$user->email}");
        
        $this->telegramService->sendMessage($message->chat_id, $profileText);
    }
    
    private function banUser($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل یا ID کاربر را وارد کنید\n" .
                "مثال: /admin ban user@example.com");
            return;
        }
        
        $user = $this->findUser($message->args[1]);
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربر یافت نشد");
            return;
        }
        
        if ($user->is_admin) {
            $this->telegramService->sendMessage($message->chat_id, "❌ نمی‌توانید ادمین را مسدود کنید");
            return;
        }
        
        $user->banned = 1;
        $user->save();
        
        $this->telegramService->sendMessage($message->chat_id, 
            "✅ کاربر مسدود شد\n" .
            "📧 ایمیل: {$user->email}\n" .
            "🆔 شناسه: {$user->id}");
    }
    
    private function unbanUser($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل یا ID کاربر را وارد کنید\n" .
                "مثال: /admin unban user@example.com");
            return;
        }
        
        $user = $this->findUser($message->args[1]);
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربر یافت نشد");
            return;
        }
        
        $user->banned = 0;
        $user->save();
        
        $this->telegramService->sendMessage($message->chat_id, 
            "✅ مسدودیت کاربر رفع شد\n" .
            "📧 ایمیل: {$user->email}\n" .
            "🆔 شناسه: {$user->id}");
    }
    
    private function deleteUser($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل یا ID کاربر را وارد کنید\n" .
                "مثال: /admin delete user@example.com");
            return;
        }
        
        $user = $this->findUser($message->args[1]);
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربر یافت نشد");
            return;
        }
        
        if ($user->is_admin) {
            $this->telegramService->sendMessage($message->chat_id, "❌ نمی‌توانید ادمین را حذف کنید");
            return;
        }
        
        $userEmail = $user->email;
        $userId = $user->id;
        
        $user->delete();
        
        $this->telegramService->sendMessage($message->chat_id, 
            "✅ کاربر حذف شد\n" .
            "📧 ایمیل: {$userEmail}\n" .
            "🆔 شناسه: {$userId}");
    }
    
    private function makeAdmin($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل یا ID کاربر را وارد کنید\n" .
                "مثال: /admin makeadmin user@example.com");
            return;
        }
        
        $user = $this->findUser($message->args[1]);
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربر یافت نشد");
            return;
        }
        
        if ($user->is_admin) {
            $this->telegramService->sendMessage($message->chat_id, "⚠️ این کاربر از قبل ادمین است");
            return;
        }
        
        $user->is_admin = 1;
        $user->save();
        
        $this->telegramService->sendMessage($message->chat_id, 
            "👑 کاربر به ادمین ارتقا یافت\n" .
            "📧 ایمیل: {$user->email}\n" .
            "🆔 شناسه: {$user->id}");
    }
    
    private function removeAdmin($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل یا ID کاربر را وارد کنید\n" .
                "مثال: /admin removeadmin user@example.com");
            return;
        }
        
        $user = $this->findUser($message->args[1]);
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربر یافت نشد");
            return;
        }
        
        if (!$user->is_admin) {
            $this->telegramService->sendMessage($message->chat_id, "⚠️ این کاربر ادمین نیست");
            return;
        }
        
        $user->is_admin = 0;
        $user->save();
        
        $this->telegramService->sendMessage($message->chat_id, 
            "📤 کاربر از ادمین خارج شد\n" .
            "📧 ایمیل: {$user->email}\n" .
            "🆔 شناسه: {$user->id}");
    }
    
    private function showAdmins($message)
    {
        $admins = User::where('is_admin', 1)->orderBy('created_at', 'desc')->get();
        
        if ($admins->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ ادمینی یافت نشد");
            return;
        }
        
        $adminsText = "👑 لیست ادمین‌ها\n\n";
        
        foreach ($admins as $admin) {
            $status = $admin->banned ? '🔴 مسدود' : '🟢 فعال';
            $telegram = $admin->telegram_id ? '📱 متصل' : '❌ متصل نیست';
            
            $adminsText .= "👑 {$admin->email}\n";
            $adminsText .= "🆔 ID: {$admin->id} | {$status} | {$telegram}\n\n";
        }
        
        $this->telegramService->sendMessage($message->chat_id, $adminsText);
    }
    
    private function telegramStats($message)
    {
        $totalConnected = User::whereNotNull('telegram_id')->count();
        $adminConnected = User::where('is_admin', 1)->whereNotNull('telegram_id')->count();
        $userConnected = User::where('is_admin', 0)->whereNotNull('telegram_id')->count();
        
        $statsText = "📱 آمار تلگرام\n\n" .
                    "🔗 کل اتصالات: {$totalConnected}\n" .
                    "👑 ادمین‌های متصل: {$adminConnected}\n" .
                    "👤 کاربران متصل: {$userConnected}\n\n" .
                    "📊 درصد اتصال: " . round(($totalConnected / User::count()) * 100, 1) . "%";
        
        $this->telegramService->sendMessage($message->chat_id, $statsText);
    }
    
    private function changeUuid($message)
    {
        if (!isset($message->args[1]) || !isset($message->args[2])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ پارامترهای کافی وارد نشده\n\n" .
                "فرمت: /admin uuid [ایمیل] [UUID_جدید]\n\n" .
                "مثال:\n" .
                "/admin uuid user@example.com 12345678-1234-1234-1234-123456789abc\n\n" .
                "💡 برای تولید UUID جدید:\n" .
                "/admin resetuuid [ایمیل]");
            return;
        }
        
        $email = $message->args[1];
        $newUuid = $message->args[2];
        
        // بررسی فرمت UUID
        if (!$this->isValidUuid($newUuid)) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ فرمت UUID نامعتبر است\n\n" .
                "فرمت صحیح: 12345678-1234-1234-1234-123456789abc\n" .
                "یا بدون خط تیره: 123456781234123412341234567890ab\n\n" .
                "💡 برای تولید UUID جدید از دستور زیر استفاده کنید:\n" .
                "/admin resetuuid {$email}");
            return;
        }
        
        // پیدا کردن کاربر
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با ایمیل {$email} یافت نشد\n\n" .
                "برای جستجو کاربر: /admin search [ایمیل]");
            return;
        }
        
        // بررسی اینکه UUID جدید قبلاً استفاده نشده باشد
        $existingUser = User::where('uuid', $newUuid)->where('id', '!=', $user->id)->first();
        if ($existingUser) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ این UUID قبلاً به کاربر دیگری تعلق دارد\n\n" .
                "📧 کاربر: {$existingUser->email}\n" .
                "🆔 شناسه: {$existingUser->id}\n\n" .
                "لطفاً UUID دیگری انتخاب کنید یا از دستور زیر استفاده کنید:\n" .
                "/admin resetuuid {$email}");
            return;
        }
        
        // ذخیره UUID قبلی برای نمایش
        $oldUuid = $user->uuid;
        
        // تغییر UUID
        $user->uuid = $newUuid;
        if (!$user->save()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در ذخیره اطلاعات");
            return;
        }
        
        $successText = "✅ UUID کاربر با موفقیت تغییر کرد\n\n" .
                      "📧 ایمیل: {$user->email}\n" .
                      "🆔 شناسه: {$user->id}\n\n" .
                      "🔸 UUID قبلی:\n{$oldUuid}\n\n" .
                      "🔹 UUID جدید:\n{$newUuid}\n\n" .
                      "⚠️ کاربر باید کانفیگ خود را دوباره دانلود کند";
        
        $this->telegramService->sendMessage($message->chat_id, $successText);
    }
    
    private function resetUuid($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل کاربر را وارد کنید\n\n" .
                "فرمت: /admin resetuuid [ایمیل]\n\n" .
                "مثال: /admin resetuuid user@example.com");
            return;
        }
        
        $email = $message->args[1];
        
        // پیدا کردن کاربر
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با ایمیل {$email} یافت نشد\n\n" .
                "برای جستجو کاربر: /admin search [ایمیل]");
            return;
        }
        
        // ذخیره UUID قبلی
        $oldUuid = $user->uuid;
        
        // تولید UUID جدید
        $newUuid = Helper::guid(true); // استفاده از Helper موجود در V2Board
        
        // اطمینان از اینکه UUID جدید تکراری نیست
        while (User::where('uuid', $newUuid)->exists()) {
            $newUuid = Helper::guid(true);
        }
        
        // تغییر UUID
        $user->uuid = $newUuid;
        if (!$user->save()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در ذخیره اطلاعات");
            return;
        }
        
        $successText = "✅ UUID جدید تولید شد\n\n" .
                      "📧 ایمیل: {$user->email}\n" .
                      "🆔 شناسه: {$user->id}\n\n" .
                      "🔸 UUID قبلی:\n{$oldUuid}\n\n" .
                      "🔹 UUID جدید:\n{$newUuid}\n\n" .
                      "⚠️ کاربر باید کانفیگ خود را دوباره دانلود کند";
        
        $this->telegramService->sendMessage($message->chat_id, $successText);
    }
    
    private function changeToken($message)
    {
        if (!isset($message->args[1]) || !isset($message->args[2])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ پارامترهای کافی وارد نشده\n\n" .
                "فرمت: /admin token [ایمیل] [Token_جدید]\n\n" .
                "مثال:\n" .
                "/admin token user@example.com abc123def456789\n\n" .
                "💡 برای تولید Token جدید:\n" .
                "/admin resettoken [ایمیل]");
            return;
        }
        
        $email = $message->args[1];
        $newToken = $message->args[2];
        
        // بررسی طول Token (معمولاً 32 کاراکتر)
        if (strlen($newToken) < 16) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ Token باید حداقل 16 کاراکتر باشد\n\n" .
                "💡 برای تولید Token جدید از دستور زیر استفاده کنید:\n" .
                "/admin resettoken {$email}");
            return;
        }
        
        // پیدا کردن کاربر
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با ایمیل {$email} یافت نشد\n\n" .
                "برای جستجو کاربر: /admin search [ایمیل]");
            return;
        }
        
        // بررسی اینکه Token جدید قبلاً استفاده نشده باشد
        $existingUser = User::where('token', $newToken)->where('id', '!=', $user->id)->first();
        if ($existingUser) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ این Token قبلاً به کاربر دیگری تعلق دارد\n\n" .
                "📧 کاربر: {$existingUser->email}\n" .
                "🆔 شناسه: {$existingUser->id}\n\n" .
                "لطفاً Token دیگری انتخاب کنید یا از دستور زیر استفاده کنید:\n" .
                "/admin resettoken {$email}");
            return;
        }
        
        // ذخیره Token قبلی برای نمایش
        $oldToken = $user->token;
        
        // تغییر Token
        $user->token = $newToken;
        if (!$user->save()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در ذخیره اطلاعات");
            return;
        }
        
        // ساخت لینک اشتراک جدید
        $baseUrl = config('v2board.subscribe_url', config('app.url'));
        $newSubscribeLink = $baseUrl . "/api/v1/client/subscribe?token=" . $newToken;
        
        $successText = "✅ Token کاربر با موفقیت تغییر کرد\n\n" .
                      "📧 ایمیل: {$user->email}\n" .
                      "🆔 شناسه: {$user->id}\n\n" .
                      "🔸 Token قبلی:\n{$oldToken}\n\n" .
                      "🔹 Token جدید:\n{$newToken}\n\n" .
                      "🔗 لینک اشتراک جدید:\n{$newSubscribeLink}\n\n" .
                      "⚠️ لینک اشتراک قبلی دیگر کار نمی‌کند!";
        
        $this->telegramService->sendMessage($message->chat_id, $successText);
    }
    
    private function resetToken($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل کاربر را وارد کنید\n\n" .
                "فرمت: /admin resettoken [ایمیل]\n\n" .
                "مثال: /admin resettoken user@example.com");
            return;
        }
        
        $email = $message->args[1];
        
        // پیدا کردن کاربر
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با ایمیل {$email} یافت نشد\n\n" .
                "برای جستجو کاربر: /admin search [ایمیل]");
            return;
        }
        
        // ذخیره Token قبلی
        $oldToken = $user->token;
        
        // تولید Token جدید
        $newToken = Helper::guid(); // تولید GUID بدون خط تیره
        
        // اطمینان از اینکه Token جدید تکراری نیست
        while (User::where('token', $newToken)->exists()) {
            $newToken = Helper::guid();
        }
        
        // تغییر Token
        $user->token = $newToken;
        if (!$user->save()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در ذخیره اطلاعات");
            return;
        }
        
        // ساخت لینک اشتراک جدید
        $baseUrl = config('v2board.subscribe_url', config('app.url'));
        $newSubscribeLink = $baseUrl . "/api/v1/client/subscribe?token=" . $newToken;
        
        $successText = "✅ Token جدید تولید شد\n\n" .
                      "📧 ایمیل: {$user->email}\n" .
                      "🆔 شناسه: {$user->id}\n\n" .
                      "🔸 Token قبلی:\n{$oldToken}\n\n" .
                      "🔹 Token جدید:\n{$newToken}\n\n" .
                      "🔗 لینک اشتراک جدید:\n{$newSubscribeLink}\n\n" .
                      "⚠️ لینک اشتراک قبلی دیگر کار نمی‌کند!";
        
        $this->telegramService->sendMessage($message->chat_id, $successText);
    }
    
    private function isValidUuid($uuid)
    {
        // حذف خط تیره‌ها برای بررسی
        $cleanUuid = str_replace('-', '', $uuid);
        
        // بررسی طول (32 کاراکتر بدون خط تیره)
        if (strlen($cleanUuid) !== 32) {
            return false;
        }
        
        // بررسی اینکه فقط حروف و اعداد هگزادسیمال باشد
        if (!ctype_xdigit($cleanUuid)) {
            return false;
        }
        
        return true;
    }
    
    private function findUser($identifier)
    {
        if (is_numeric($identifier)) {
            return User::find($identifier);
        } else {
            return User::where('email', $identifier)->first();
        }
    }
}
