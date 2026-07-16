<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;
use Illuminate\Support\Facades\DB;

class Search extends Telegram {
    public $command = '/search';
    public $description = 'جستجوی پیشرفته کاربران (ویژه ادمین)';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        
        // بررسی دسترسی ادمین
        $currentUser = User::where('telegram_id', $message->chat_id)->first();
        if (!$currentUser || !$currentUser->is_admin) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ شما دسترسی ادمین ندارید\n\n" .
                "این دستور فقط برای ادمین‌ها قابل استفاده است.");
            return;
        }
        
        if (!isset($message->args[0])) {
            $this->showSearchMenu($message);
            return;
        }
        
        $searchType = strtolower($message->args[0]);
        
        switch ($searchType) {
            case 'email':
                $this->searchByEmail($message);
                break;
            case 'uuid':
                $this->searchByUuid($message);
                break;
            case 'token':
                $this->searchByToken($message);
                break;
            case 'info':
                $this->showUserFullInfo($message);
                break;
            default:
                // اگر نوع جستجو مشخص نشده، فرض کن که ایمیل وارد شده
                $this->quickSearch($message);
        }
    }
    
    private function showSearchMenu($message)
    {
        $menuText = "╔═══════════════════════════════\n";
        $menuText .= "║ 🔍 **جستجوی پیشرفته کاربران**\n";
        $menuText .= "╚═══════════════════════════════\n\n";
        
        $menuText .= "┏━━━ 📋 **دستورات موجود** ━━━\n";
        $menuText .= "┃\n";
        $menuText .= "┃ 📧 `/search email [text]`\n";
        $menuText .= "┃    جستجو در ایمیل‌ها\n";
        $menuText .= "┃\n";
        $menuText .= "┃ 🆔 `/search uuid [UUID]`\n";
        $menuText .= "┃    جستجو با شناسه یکتا\n";
        $menuText .= "┃\n";
        $menuText .= "┃ 🎫 `/search token [token]`\n";
        $menuText .= "┃    جستجو با توکن\n";
        $menuText .= "┃\n";
        $menuText .= "┃ 📊 `/search info [email]`\n";
        $menuText .= "┃    اطلاعات کامل کاربر\n";
        $menuText .= "┃\n";
        $menuText .= "┃ ⚡ `/search [text]`\n";
        $menuText .= "┃    جستجوی سریع\n";
        $menuText .= "┗━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $menuText .= "┏━━━ 💡 **مثال‌ها** ━━━\n";
        $menuText .= "┃\n";
        $menuText .= "┃ `/search email gmail`\n";
        $menuText .= "┃ `/search uuid abc123`\n";
        $menuText .= "┃ `/search info user@example.com`\n";
        $menuText .= "┃ `/search john`\n";
        $menuText .= "┗━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $menuText .= "🔐 **توجه:** این دستورات فقط برای ادمین‌ها قابل استفاده است.";
        
        $this->telegramService->sendMessage($message->chat_id, $menuText, 'markdown');
    }
    
    private function searchByEmail($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً بخشی از ایمیل را وارد کنید\n\n" .
                "مثال: /search email gmail");
            return;
        }
        
        $searchTerm = strtolower($message->args[1]);
        
        // جستجو در ایمیل‌ها
        $users = User::whereRaw('LOWER(email) LIKE ?', ['%' . $searchTerm . '%'])
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get();
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با این مشخصات یافت نشد");
            return;
        }
        
        $resultText = "╔═══════════════════════════════\n";
        $resultText .= "║ 🔍 **نتایج جستجو**\n";
        $resultText .= "╚═══════════════════════════════\n\n";
        
        $resultText .= "🔎 **عبارت جستجو:** `{$searchTerm}`\n";
        $resultText .= "📊 **تعداد نتایج:** {$users->count()} کاربر\n\n";
        
        $resultText .= "┏━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        foreach ($users as $index => $user) {
            $num = $index + 1;
            $statusEmoji = $this->getStatusEmoji($user);
            $statusText = $this->getUserStatus($user);
            
            // محاسبه ترافیک
            $total = $user->transfer_enable ?: 0;
            $used = ($user->u ?: 0) + ($user->d ?: 0);
            $remaining = $total - $used;
            
            if ($total > 0) {
                $remainingGB = round($remaining / (1024*1024*1024), 1);
                $percent = round(($used / $total) * 100, 0);
                $trafficInfo = "{$remainingGB}GB ({$percent}%)";
            } else {
                $trafficInfo = "نامحدود";
            }
            
            // تاریخ ثبت‌نام
            $regTime = Carbon::parse($user->created_at)->diffForHumans();
            
            $resultText .= "┃\n";
            $resultText .= "┃ **{$num}.** 📧 `{$user->email}`\n";
            $resultText .= "┃     ├ {$statusEmoji} {$statusText}\n";
            $resultText .= "┃     ├ 💾 ترافیک: {$trafficInfo}\n";
            $resultText .= "┃     ├ 🆔 `{$user->uuid}`\n";
            $resultText .= "┃     └ 📅 {$regTime}\n";
            
            // جلوگیری از طولانی شدن پیام
            if ($index >= 9) {
                $remaining = $users->count() - 10;
                if ($remaining > 0) {
                    $resultText .= "┃\n";
                    $resultText .= "┃ ... و **{$remaining}** کاربر دیگر\n";
                }
                break;
            }
        }
        
        $resultText .= "┗━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $resultText .= "💡 **راهنما:**\n";
        $resultText .= "برای مشاهده اطلاعات کامل کاربر:\n";
        $resultText .= "`/search info [ایمیل کامل]`";
        
        $this->telegramService->sendMessage($message->chat_id, $resultText, 'markdown');
    }
    
    private function searchByUuid($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً UUID را وارد کنید\n\n" .
                "مثال: /search uuid abc123-def456");
            return;
        }
        
        $uuid = $message->args[1];
        
        $user = User::where('uuid', $uuid)->first();
        
        if (!$user) {
            // جستجو برای بخشی از UUID
            $user = User::whereRaw('uuid LIKE ?', ['%' . $uuid . '%'])->first();
        }
        
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با این UUID یافت نشد");
            return;
        }
        
        // نمایش اطلاعات کامل کاربر
        $this->displayUserInfo($message, $user);
    }
    
    private function searchByToken($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً توکن را وارد کنید\n\n" .
                "مثال: /search token abc123xyz");
            return;
        }
        
        $token = $message->args[1];
        
        $user = User::where('token', $token)->first();
        
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با این توکن یافت نشد");
            return;
        }
        
        // نمایش اطلاعات کامل کاربر
        $this->displayUserInfo($message, $user);
    }
    
    private function showUserFullInfo($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ لطفاً ایمیل کامل کاربر را وارد کنید\n\n" .
                "مثال: /search info user@example.com");
            return;
        }
        
        $email = $message->args[1];
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با این ایمیل یافت نشد");
            return;
        }
        
        $this->displayUserInfo($message, $user);
    }
    
    private function quickSearch($message)
    {
        $searchTerm = implode(' ', $message->args);
        
        if (empty($searchTerm)) {
            $this->showSearchMenu($message);
            return;
        }
        
        // جستجو در ایمیل
        $users = User::whereRaw('LOWER(email) LIKE ?', ['%' . strtolower($searchTerm) . '%'])
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ کاربری با این مشخصات یافت نشد");
            return;
        }
        
        if ($users->count() == 1) {
            // اگر فقط یک کاربر پیدا شد، اطلاعات کامل را نمایش بده
            $this->displayUserInfo($message, $users->first());
        } else {
            // نمایش لیست نتایج
            $this->searchByEmail($message);
        }
    }
    
    private function displayUserInfo($message, $user)
    {
        // Header
        $infoText = "╔═══════════════════════════════\n";
        $infoText .= "║ 👤 **اطلاعات کامل کاربر**\n";
        $infoText .= "╚═══════════════════════════════\n\n";
        
        // بخش 1: اطلاعات اصلی
        $infoText .= "┏━━━━━ 📋 **اطلاعات حساب** ━━━━━\n";
        $infoText .= "┃\n";
        $infoText .= "┃ 📧 **ایمیل:** `{$user->email}`\n";
        $infoText .= "┃ 🔑 **رمز عبور:** `{$user->password}`\n";
        $infoText .= "┃ 🆔 **UUID:** `{$user->uuid}`\n";
        $infoText .= "┃ 🎫 **توکن:** `" . substr($user->token, 0, 20) . "...`\n";
        
        $statusEmoji = $this->getStatusEmoji($user);
        $statusText = $this->getUserStatus($user);
        $infoText .= "┃ 📊 **وضعیت:** {$statusEmoji} {$statusText}\n";
        
        $roleEmoji = $user->is_admin ? "👑" : "👤";
        $roleText = $user->is_admin ? "مدیر سیستم" : "کاربر عادی";
        $infoText .= "┃ 🎭 **نقش:** {$roleEmoji} {$roleText}\n";
        $infoText .= "┗━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        // بخش 2: تاریخ انقضا
        $infoText .= "┏━━━━━ 📅 **اطلاعات زمانی** ━━━━━\n";
        $infoText .= "┃\n";
        
        if ($user->expired_at) {
            $carbonDate = is_numeric($user->expired_at) 
                ? Carbon::createFromTimestamp($user->expired_at) 
                : Carbon::parse($user->expired_at);
            
            $jalalianDate = Jalalian::fromCarbon($carbonDate);
            $shamsiDate = $jalalianDate->format('Y/m/d H:i');
            $miladiDate = $carbonDate->format('Y-m-d H:i');
            
            $now = Carbon::now();
            $remainingDays = $carbonDate->isFuture() ? $now->diffInDays($carbonDate) : 0;
            
            $infoText .= "┃ 📆 **تاریخ انقضا (شمسی):** `{$shamsiDate}`\n";
            $infoText .= "┃ 🗓 **تاریخ انقضا (میلادی):** `{$miladiDate}`\n";
            
            if ($remainingDays > 0) {
                $daysEmoji = $remainingDays > 30 ? "🟢" : ($remainingDays > 7 ? "🟡" : "🔴");
                $infoText .= "┃ ⏳ **مدت باقیمانده:** {$daysEmoji} {$remainingDays} روز\n";
            } else {
                $expiredDays = abs($now->diffInDays($carbonDate));
                $infoText .= "┃ ⚠️ **وضعیت:** منقضی شده ({$expiredDays} روز پیش)\n";
            }
        } else {
            $infoText .= "┃ ♾ **تاریخ انقضا:** نامحدود\n";
        }
        
        $createdCarbon = Carbon::parse($user->created_at);
        $createdJalali = Jalalian::fromCarbon($createdCarbon);
        $infoText .= "┃ 📝 **تاریخ ثبت‌نام:** {$createdJalali->format('Y/m/d')}\n";
        $infoText .= "┃ 🕐 **مدت عضویت:** " . $createdCarbon->diffForHumans() . "\n";
        $infoText .= "┗━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        // بخش 3: ترافیک
        $transferEnable = $user->transfer_enable ?: 0;
        $up = $user->u ?: 0;
        $down = $user->d ?: 0;
        $totalUsed = $up + $down;
        $remaining = $transferEnable - $totalUsed;
        
        $infoText .= "┏━━━━━ 📊 **آمار ترافیک** ━━━━━\n";
        $infoText .= "┃\n";
        
        if ($transferEnable > 0) {
            $usagePercent = round(($totalUsed / $transferEnable) * 100, 1);
            $progressBar = $this->createProgressBar($usagePercent);
            
            $infoText .= "┃ 💾 **کل ترافیک:** " . Helper::trafficConvert($transferEnable) . "\n";
            $infoText .= "┃ 📤 **آپلود:** " . Helper::trafficConvert($up) . "\n";
            $infoText .= "┃ 📥 **دانلود:** " . Helper::trafficConvert($down) . "\n";
            $infoText .= "┃ 📊 **مجموع مصرف:** " . Helper::trafficConvert($totalUsed) . "\n";
            $infoText .= "┃ 💎 **باقیمانده:** " . Helper::trafficConvert($remaining) . "\n";
            $infoText .= "┃ 📈 **درصد مصرف:** {$progressBar} {$usagePercent}%\n";
        } else {
            $infoText .= "┃ ♾ **ترافیک:** نامحدود\n";
            $infoText .= "┃ 📤 **آپلود:** " . Helper::trafficConvert($up) . "\n";
            $infoText .= "┃ 📥 **دانلود:** " . Helper::trafficConvert($down) . "\n";
        }
        
        $infoText .= "┗━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        // بخش 4: اطلاعات مالی
        $balance = $user->balance ?: 0;
        $balanceToman = $balance / 100;
        
        $infoText .= "┏━━━━━ 💰 **اطلاعات مالی** ━━━━━\n";
        $infoText .= "┃\n";
        $infoText .= "┃ 💳 **موجودی حساب:** " . number_format($balanceToman) . " تومان\n";
        
        try {
            if (DB::getSchemaBuilder()->hasTable('v2_order')) {
                $orderColumns = DB::getSchemaBuilder()->getColumnListing('v2_order');
                
                // بررسی وجود ستون‌های مورد نیاز
                $userColumn = 'user_id';
                if (!in_array('user_id', $orderColumns) && in_array('uid', $orderColumns)) {
                    $userColumn = 'uid';
                }
                
                $amountColumn = 'total_amount';
                if (!in_array('total_amount', $orderColumns) && in_array('amount', $orderColumns)) {
                    $amountColumn = 'amount';
                }
                
                $orderCount = DB::table('v2_order')->where($userColumn, $user->id)->count();
                $successfulOrders = DB::table('v2_order')
                                    ->where($userColumn, $user->id)
                                    ->where('status', 3)
                                    ->count();
                $totalPaid = DB::table('v2_order')
                               ->where($userColumn, $user->id)
                               ->where('status', 3)
                               ->sum($amountColumn);
                $totalPaidToman = $totalPaid / 100;
                
                $infoText .= "┃ 🛒 **تعداد سفارشات:** {$orderCount}\n";
                $infoText .= "┃ ✅ **سفارشات موفق:** {$successfulOrders}\n";
                $infoText .= "┃ 💸 **مجموع پرداختی:** " . number_format($totalPaidToman) . " تومان\n";
            }
        } catch (\Exception $e) {
            // Skip if error
        }
        
        $infoText .= "┗━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        // بخش 5: آخرین اتصال
        try {
            $statQuery = DB::table('v2_stat');
            $columns = DB::getSchemaBuilder()->getColumnListing('v2_stat');
            
            $userColumn = 'user_id';
            if (in_array('uid', $columns)) {
                $userColumn = 'uid';
            } elseif (in_array('user', $columns)) {
                $userColumn = 'user';
            }
            
            $lastStat = $statQuery->where($userColumn, $user->id)
                                 ->orderBy('created_at', 'desc')
                                 ->first();
            
            if ($lastStat) {
                $infoText .= "┏━━━━━ 🌐 **آخرین اتصال** ━━━━━\n";
                $infoText .= "┃\n";
                
                $lastConnectCarbon = Carbon::parse($lastStat->created_at);
                $lastConnectJalali = Jalalian::fromCarbon($lastConnectCarbon);
                
                $isOnline = $lastConnectCarbon->greaterThan(Carbon::now()->subMinutes(5));
                $onlineEmoji = $isOnline ? "🟢" : "⚫";
                $onlineText = $isOnline ? "آنلاین" : "آفلاین";
                
                $infoText .= "┃ {$onlineEmoji} **وضعیت:** {$onlineText}\n";
                $infoText .= "┃ 🕐 **زمان:** {$lastConnectJalali->format('Y/m/d H:i')}\n";
                $infoText .= "┃ ⏱ **مدت:** " . $lastConnectCarbon->diffForHumans() . "\n";
                
                $serverName = 'نامشخص';
                if (property_exists($lastStat, 'server_name')) {
                    $serverName = $lastStat->server_name;
                } elseif (property_exists($lastStat, 'node_name')) {
                    $serverName = $lastStat->node_name;
                }
                
                if ($serverName != 'نامشخص') {
                    $infoText .= "┃ 🖥 **سرور:** {$serverName}\n";
                }
                
                $infoText .= "┗━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            }
        } catch (\Exception $e) {
            // Skip if error
        }
        
        // بخش 6: اطلاعات تکمیلی
        $infoText .= "┏━━━━━ 📱 **سایر اطلاعات** ━━━━━\n";
        $infoText .= "┃\n";
        
        $deviceLimit = $user->device_limit ?: 'نامحدود';
        $infoText .= "┃ 📱 **محدودیت دستگاه:** {$deviceLimit}\n";
        
        if ($user->plan_id) {
            $infoText .= "┃ 📋 **پلن:** ID {$user->plan_id}\n";
        }
        
        $telegramStatus = $user->telegram_id ? "✅ متصل (ID: {$user->telegram_id})" : "❌ غیرمتصل";
        $infoText .= "┃ 🤖 **تلگرام:** {$telegramStatus}\n";
        
        if ($user->banned_reason) {
            $infoText .= "┃ 🚫 **دلیل مسدودی:** {$user->banned_reason}\n";
        }
        
        $infoText .= "┗━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        // بخش 7: دستورات سریع
        $infoText .= "⚡ **دستورات سریع:**\n";
        $infoText .= "```\n";
        $infoText .= "/edit expire 30d {$user->email}\n";
        $infoText .= "/edit traffic 10 {$user->email}\n";
        $infoText .= "/edit balance 10000 {$user->email}\n";
        
        if ($user->banned) {
            $infoText .= "/edit status unban {$user->email}\n";
        } else {
            $infoText .= "/edit status ban {$user->email}\n";
        }
        
        $infoText .= "```";
        
        $this->telegramService->sendMessage($message->chat_id, $infoText, 'markdown');
    }
    
    private function getStatusEmoji($user)
    {
        if ($user->banned) {
            return "🚫";
        }
        
        if ($user->expired_at) {
            $expiry = is_numeric($user->expired_at) 
                ? Carbon::createFromTimestamp($user->expired_at)
                : Carbon::parse($user->expired_at);
            
            if ($expiry->isFuture()) {
                $days = Carbon::now()->diffInDays($expiry);
                if ($days > 30) return "🟢";
                if ($days > 7) return "🟡";
                return "🔴";
            } else {
                return "⏰";
            }
        }
        
        return "♾";
    }
    
    private function createProgressBar($percent)
    {
        $filled = round($percent / 10);
        $empty = 10 - $filled;
        
        $bar = "";
        for ($i = 0; $i < $filled; $i++) {
            $bar .= "█";
        }
        for ($i = 0; $i < $empty; $i++) {
            $bar .= "░";
        }
        
        return $bar;
    }
    
    private function getUserStatus($user)
    {
        if ($user->banned) {
            return "🚫 مسدود";
        }
        
        if ($user->expired_at) {
            $expiry = is_numeric($user->expired_at) 
                ? Carbon::createFromTimestamp($user->expired_at)
                : Carbon::parse($user->expired_at);
            
            if ($expiry->isFuture()) {
                return "✅ فعال";
            } else {
                return "⏰ منقضی";
            }
        }
        
        return "✅ فعال (نامحدود)";
    }
    
    private function getTrafficInfo($user)
    {
        $total = $user->transfer_enable ?: 0;
        $used = ($user->u ?: 0) + ($user->d ?: 0);
        $remaining = $total - $used;
        
        if ($total == 0) {
            return "نامحدود";
        }
        
        $percent = round(($used / $total) * 100, 1);
        $remainingGB = round($remaining / (1024*1024*1024), 2);
        
        return "{$remainingGB} GB ({$percent}% مصرف شده)";
    }
}
