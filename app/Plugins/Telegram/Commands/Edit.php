<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\DB;

class Edit extends Telegram {
    public $command = '/edit';
    public $description = 'ویرایش جمعی کاربران';

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
            $this->showEditMenu($message);
            return;
        }
        
        $action = $message->args[0];
        
        switch ($action) {
            case 'expire':
                $this->bulkExpireEdit($message);
                break;
            case 'traffic':
                $this->bulkTrafficEdit($message);
                break;
            case 'plan':
                $this->bulkPlanEdit($message);
                break;
            case 'reset':
                $this->bulkResetTraffic($message);
                break;
            case 'balance':
                $this->bulkBalanceEdit($message);
                break;
            case 'device':
                $this->bulkDeviceEdit($message);
                break;
            case 'status':
                $this->bulkStatusEdit($message);
                break;
            case 'count':
                $this->showUserCounts($message);
                break;
            default:
                $this->showEditMenu($message);
        }
    }
    
    private function showEditMenu($message)
    {
        $menuText = "⚡ ویرایش جمعی کاربران\n\n" .
                   "🎯 عملیات جمعی:\n" .
                   "• /edit expire [زمان] [فیلتر] - اضافه کردن زمان به انقضا\n" .
                   "• /edit traffic [GB] [فیلتر] - اضافه کردن ترافیک\n" .
                   "• /edit reset [فیلتر] - صفر کردن ترافیک مصرفی\n" .
                   "• /edit plan [پلن_ID] [فیلتر] - تغییر پلن\n" .
                   "• /edit balance [مبلغ] [فیلتر] - اضافه کردن موجودی\n" .
                   "• /edit device [تعداد] [فیلتر] - تنظیم محدودیت دستگاه\n" .
                   "• /edit status [وضعیت] [فیلتر] - تغییر وضعیت\n\n" .
                   "📊 اطلاعات:\n" .
                   "• /edit count [فیلتر] - شمارش کاربران\n\n" .
                   "📅 فرمت‌های زمانی:\n" .
                   "• 30 یا 30d = 30 روز\n" .
                   "• 6m = 6 ماه\n" .
                   "• 2y = 2 سال\n" .
                   "• 1y6m15d = 1 سال و 6 ماه و 15 روز\n" .
                   "• -30d = کم کردن 30 روز\n\n" .
                   "🔍 فیلترهای موجود:\n" .
                   "• all - همه کاربران\n" .
                   "• active - کاربران فعال\n" .
                   "• expired - کاربران منقضی\n" .
                   "• banned - کاربران مسدود\n" .
                   "• telegram - متصل به تلگرام\n" .
                   "• notelegram - غیرمتصل به تلگرام\n" .
                   "• admin - ادمین‌ها\n" .
                   "• user - کاربران عادی\n\n" .
                   "مثال:\n" .
                   "/edit expire 1y6m active\n" .
                   "/edit traffic 50 all\n" .
                   "/edit count expired";
        
        $this->telegramService->sendMessage($message->chat_id, $menuText);
    }
    
    private function bulkExpireEdit($message)
    {
        if (!isset($message->args[1]) || !isset($message->args[2])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ پارامترهای کافی وارد نشده\n\n" .
                "فرمت: /edit expire [زمان] [فیلتر]\n\n" .
                "📅 فرمت‌های زمانی:\n" .
                "• 30 یا 30d = 30 روز\n" .
                "• 6m = 6 ماه\n" .
                "• 2y = 2 سال\n" .
                "• 1y6m = 1 سال و 6 ماه\n" .
                "• 6m15d = 6 ماه و 15 روز\n" .
                "• 1y6m15d = 1 سال و 6 ماه و 15 روز\n" .
                "• -30d = کم کردن 30 روز\n" .
                "• -6m = کم کردن 6 ماه\n\n" .
                "مثال:\n" .
                "/edit expire 1y active - اضافه کردن 1 سال\n" .
                "/edit expire 6m15d all - اضافه کردن 6 ماه و 15 روز\n" .
                "/edit expire -1m expired - کم کردن 1 ماه");
            return;
        }
        
        $timeString = $message->args[1];
        $filter = $message->args[2];
        
        // تجزیه رشته زمان
        $timeData = $this->parseTimeString($timeString);
        
        if ($timeData === false) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ فرمت زمان نامعتبر است\n\n" .
                "فرمت‌های صحیح:\n" .
                "• 30 یا 30d (روز)\n" .
                "• 6m (ماه)\n" .
                "• 2y (سال)\n" .
                "• 1y6m15d (ترکیبی)\n\n" .
                "مثال: /edit expire 1y6m all");
            return;
        }
        
        if ($timeData['total_seconds'] == 0) {
            $this->telegramService->sendMessage($message->chat_id, "❌ زمان نمی‌تواند صفر باشد");
            return;
        }
        
        // گرفتن کاربران بر اساس فیلتر
        $users = $this->getUsersByFilter($filter);
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربری با این فیلتر پیدا نشد");
            return;
        }
        
        $updatedCount = 0;
        $noExpiryCount = 0;
        $examples = [];
        
        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                $oldExpiry = $user->expired_at;
                
                if ($oldExpiry === null) {
                    // اگر کاربر تاریخ انقضا ندارد، از زمان فعلی شروع کن
                    $newExpiry = time() + $timeData['total_seconds'];
                    $noExpiryCount++;
                } else {
                    // به تاریخ انقضای فعلی کاربر اضافه کن
                    $newExpiry = $this->addTimeToTimestamp($oldExpiry, $timeData);
                }
                
                // جلوگیری از تاریخ خیلی قدیم در صورت کم کردن زیاد
                if ($newExpiry < (time() - (365 * 24 * 60 * 60))) {
                    $newExpiry = time() + (7 * 24 * 60 * 60); // حداقل 7 روز از الان
                }
                
                $user->expired_at = $newExpiry;
                $user->save();
                $updatedCount++;
                
                // نمونه‌هایی برای نمایش (فقط 3 مورد اول)
                if (count($examples) < 3) {
                    $oldDate = $oldExpiry ? date('Y-m-d H:i', $oldExpiry) : 'نامحدود';
                    $newDate = date('Y-m-d H:i', $newExpiry);
                    $examples[] = "📧 " . substr($user->email, 0, 20) . "...\n" .
                                 "   قبل: {$oldDate}\n" .
                                 "   بعد: {$newDate}";
                }
            }
            
            DB::commit();
            
            $action = $timeData['total_seconds'] > 0 ? "اضافه شد" : "کم شد";
            $timeDisplay = $this->formatTimeDisplay($timeData);
            
            $successText = "✅ تاریخ انقضا با موفقیت تغییر کرد\n\n" .
                          "📊 آمار عملیات:\n" .
                          "• تعداد کل کاربران: {$updatedCount}\n" .
                          "• کاربران بدون تاریخ انقضا: {$noExpiryCount}\n" .
                          "• زمان {$action}: {$timeDisplay}\n" .
                          "• فیلتر اعمال شده: {$filter}\n\n" .
                          "📋 نمونه تغییرات:\n" .
                          implode("\n\n", $examples) . 
                          ($updatedCount > 3 ? "\n\n... و " . ($updatedCount - 3) . " کاربر دیگر" : "") . "\n\n" .
                          "💡 تمام تاریخ‌های انقضا بر اساس تاریخ قبلی خود هر کاربر محاسبه شد.";
            
            $this->telegramService->sendMessage($message->chat_id, $successText);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در بروزرسانی: " . $e->getMessage());
        }
    }
    
    private function parseTimeString($timeString)
    {
        $timeString = trim($timeString);
        $isNegative = strpos($timeString, '-') === 0;
        $timeString = ltrim($timeString, '-');
        
        $years = 0;
        $months = 0;
        $days = 0;
        
        // اگر فقط عدد باشد، آن را روز در نظر بگیر
        if (is_numeric($timeString)) {
            $days = (int)$timeString;
        } else {
            // استخراج سال، ماه و روز
            if (preg_match('/(\d+)y/i', $timeString, $matches)) {
                $years = (int)$matches[1];
            }
            
            if (preg_match('/(\d+)m/i', $timeString, $matches)) {
                $months = (int)$matches[1];
            }
            
            if (preg_match('/(\d+)d/i', $timeString, $matches)) {
                $days = (int)$matches[1];
            }
            
            // اگر هیچ الگویی پیدا نشد
            if ($years == 0 && $months == 0 && $days == 0) {
                return false;
            }
        }
        
        // محاسبه کل ثانیه‌ها (تقریبی)
        $totalSeconds = 0;
        $totalSeconds += $years * 365 * 24 * 60 * 60;   // سال
        $totalSeconds += $months * 30 * 24 * 60 * 60;   // ماه (تقریبی 30 روز)
        $totalSeconds += $days * 24 * 60 * 60;          // روز
        
        if ($isNegative) {
            $totalSeconds = -$totalSeconds;
        }
        
        return [
            'years' => $isNegative ? -$years : $years,
            'months' => $isNegative ? -$months : $months,
            'days' => $isNegative ? -$days : $days,
            'total_seconds' => $totalSeconds,
            'is_negative' => $isNegative
        ];
    }
    
    private function addTimeToTimestamp($timestamp, $timeData)
    {
        // تبدیل timestamp به تاریخ
        $date = new \DateTime();
        $date->setTimestamp($timestamp);
        
        // اضافه کردن سال، ماه و روز به صورت دقیق
        if ($timeData['years'] != 0) {
            $date->modify(($timeData['years'] > 0 ? '+' : '') . $timeData['years'] . ' years');
        }
        
        if ($timeData['months'] != 0) {
            $date->modify(($timeData['months'] > 0 ? '+' : '') . $timeData['months'] . ' months');
        }
        
        if ($timeData['days'] != 0) {
            $date->modify(($timeData['days'] > 0 ? '+' : '') . $timeData['days'] . ' days');
        }
        
        return $date->getTimestamp();
    }
    
    private function formatTimeDisplay($timeData)
    {
        $parts = [];
        
        if (abs($timeData['years']) > 0) {
            $parts[] = abs($timeData['years']) . ' سال';
        }
        
        if (abs($timeData['months']) > 0) {
            $parts[] = abs($timeData['months']) . ' ماه';
        }
        
        if (abs($timeData['days']) > 0) {
            $parts[] = abs($timeData['days']) . ' روز';
        }
        
        if (empty($parts)) {
            return '0 روز';
        }
        
        return implode(' و ', $parts);
    }
    
    private function bulkTrafficEdit($message)
    {
        if (!isset($message->args[1]) || !isset($message->args[2])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ پارامترهای کافی وارد نشده\n\n" .
                "فرمت: /edit traffic [GB] [فیلتر]\n\n" .
                "مثال:\n" .
                "/edit traffic 50 active - اضافه کردن 50GB به کاربران فعال\n" .
                "/edit traffic 100 all - اضافه کردن 100GB به همه\n" .
                "/edit traffic -10 expired - کم کردن 10GB از منقضی‌ها");
            return;
        }
        
        $trafficGB = (float)$message->args[1];
        $filter = $message->args[2];
        
        if ($trafficGB == 0) {
            $this->telegramService->sendMessage($message->chat_id, "❌ مقدار ترافیک نمی‌تواند صفر باشد");
            return;
        }
        
        $users = $this->getUsersByFilter($filter);
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربری با این فیلتر پیدا نشد");
            return;
        }
        
        $trafficBytes = $trafficGB * 1024 * 1024 * 1024; // تبدیل GB به Byte
        $updatedCount = 0;
        $examples = [];
        
        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                $currentTraffic = $user->transfer_enable ?: 0;
                $oldTrafficGB = round($currentTraffic / (1024*1024*1024), 2);
                $newTraffic = $currentTraffic + $trafficBytes;
                
                // جلوگیری از ترافیک منفی
                if ($newTraffic < 0) {
                    $newTraffic = 0;
                }
                
                $user->transfer_enable = $newTraffic;
                $user->save();
                $updatedCount++;
                
                // نمونه‌هایی برای نمایش (فقط 3 مورد اول)
                if (count($examples) < 3) {
                    $newTrafficGB = round($newTraffic / (1024*1024*1024), 2);
                    $examples[] = "📧 " . substr($user->email, 0, 20) . "...\n" .
                                 "   قبل: {$oldTrafficGB} GB\n" .
                                 "   بعد: {$newTrafficGB} GB";
                }
            }
            
            DB::commit();
            
            $action = $trafficGB > 0 ? "اضافه شد" : "کم شد";
            $absTraffic = abs($trafficGB);
            
            $successText = "✅ ترافیک با موفقیت تغییر کرد\n\n" .
                          "📊 آمار عملیات:\n" .
                          "• تعداد کاربران: {$updatedCount}\n" .
                          "• ترافیک {$action}: {$absTraffic} GB\n" .
                          "• فیلتر اعمال شده: {$filter}\n\n" .
                          "📋 نمونه تغییرات:\n" .
                          implode("\n\n", $examples) . 
                          ($updatedCount > 3 ? "\n\n... و " . ($updatedCount - 3) . " کاربر دیگر" : "") . "\n\n" .
                          "💡 تمام کاربران انتخاب شده بروزرسانی شدند.";
            
            $this->telegramService->sendMessage($message->chat_id, $successText);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در بروزرسانی: " . $e->getMessage());
        }
    }
    
    private function bulkResetTraffic($message)
    {
        if (!isset($message->args[1])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ فیلتر مشخص نشده\n\n" .
                "فرمت: /edit reset [فیلتر]\n\n" .
                "مثال:\n" .
                "/edit reset active - صفر کردن ترافیک مصرفی کاربران فعال\n" .
                "/edit reset all - صفر کردن ترافیک مصرفی همه کاربران");
            return;
        }
        
        $filter = $message->args[1];
        $users = $this->getUsersByFilter($filter);
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربری با این فیلتر پیدا نشد");
            return;
        }
        
        $updatedCount = 0;
        $totalResetGB = 0;
        
        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                $usedBytes = ($user->u ?: 0) + ($user->d ?: 0);
                $usedGB = round($usedBytes / (1024*1024*1024), 2);
                $totalResetGB += $usedGB;
                
                $user->u = 0; // آپلود
                $user->d = 0; // دانلود
                $user->save();
                $updatedCount++;
            }
            
            DB::commit();
            
            $successText = "✅ ترافیک مصرفی صفر شد\n\n" .
                          "📊 آمار عملیات:\n" .
                          "• تعداد کاربران: {$updatedCount}\n" .
                          "• کل ترافیک صفر شده: {$totalResetGB} GB\n" .
                          "• فیلتر اعمال شده: {$filter}\n\n" .
                          "💡 ترافیک مصرفی تمام کاربران انتخاب شده صفر شد.";
            
            $this->telegramService->sendMessage($message->chat_id, $successText);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در بروزرسانی: " . $e->getMessage());
        }
    }
    
    private function bulkBalanceEdit($message)
    {
        if (!isset($message->args[1]) || !isset($message->args[2])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ پارامترهای کافی وارد نشده\n\n" .
                "فرمت: /edit balance [مبلغ_تومان] [فیلتر]\n\n" .
                "مثال:\n" .
                "/edit balance 10000 active - اضافه کردن 10هزار تومان\n" .
                "/edit balance -5000 banned - کم کردن 5هزار تومان");
            return;
        }
        
        $amount = (int)$message->args[1];
        $filter = $message->args[2];
        
        if ($amount == 0) {
            $this->telegramService->sendMessage($message->chat_id, "❌ مبلغ نمی‌تواند صفر باشد");
            return;
        }
        
        $users = $this->getUsersByFilter($filter);
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربری با این فیلتر پیدا نشد");
            return;
        }
        
        $amountInCents = $amount * 100; // تبدیل تومان به سنت
        $updatedCount = 0;
        $examples = [];
        
        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                $currentBalance = $user->balance ?: 0;
                $oldBalanceToman = $currentBalance / 100;
                $newBalance = $currentBalance + $amountInCents;
                
                // جلوگیری از موجودی منفی
                if ($newBalance < 0) {
                    $newBalance = 0;
                }
                
                $user->balance = $newBalance;
                $user->save();
                $updatedCount++;
                
                // نمونه‌هایی برای نمایش (فقط 3 مورد اول)
                if (count($examples) < 3) {
                    $newBalanceToman = $newBalance / 100;
                    $examples[] = "📧 " . substr($user->email, 0, 20) . "...\n" .
                                 "   قبل: {$oldBalanceToman} تومان\n" .
                                 "   بعد: {$newBalanceToman} تومان";
                }
            }
            
            DB::commit();
            
            $action = $amount > 0 ? "اضافه شد" : "کم شد";
            $absAmount = abs($amount);
            
            $successText = "✅ موجودی با موفقیت تغییر کرد\n\n" .
                          "📊 آمار عملیات:\n" .
                          "• تعداد کاربران: {$updatedCount}\n" .
                          "• مبلغ {$action}: {$absAmount} تومان\n" .
                          "• فیلتر اعمال شده: {$filter}\n\n" .
                          "📋 نمونه تغییرات:\n" .
                          implode("\n\n", $examples) . 
                          ($updatedCount > 3 ? "\n\n... و " . ($updatedCount - 3) . " کاربر دیگر" : "") . "\n\n" .
                          "💡 موجودی تمام کاربران انتخاب شده بروزرسانی شد.";
            
            $this->telegramService->sendMessage($message->chat_id, $successText);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در بروزرسانی: " . $e->getMessage());
        }
    }
    
    private function bulkDeviceEdit($message)
    {
        if (!isset($message->args[1]) || !isset($message->args[2])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ پارامترهای کافی وارد نشده\n\n" .
                "فرمت: /edit device [تعداد] [فیلتر]\n\n" .
                "مثال:\n" .
                "/edit device 5 active - تنظیم محدودیت 5 دستگاه\n" .
                "/edit device 0 all - حذف محدودیت دستگاه");
            return;
        }
        
        $deviceLimit = (int)$message->args[1];
        $filter = $message->args[2];
        
        if ($deviceLimit < 0) {
            $this->telegramService->sendMessage($message->chat_id, "❌ تعداد دستگاه نمی‌تواند منفی باشد");
            return;
        }
        
        $users = $this->getUsersByFilter($filter);
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربری با این فیلتر پیدا نشد");
            return;
        }
        
        $updatedCount = 0;
        
        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                $user->device_limit = $deviceLimit ?: null;
                $user->save();
                $updatedCount++;
            }
            
            DB::commit();
            
            $limitText = $deviceLimit > 0 ? "{$deviceLimit} دستگاه" : "نامحدود";
            
            $successText = "✅ محدودیت دستگاه تغییر کرد\n\n" .
                          "📊 آمار عملیات:\n" .
                          "• تعداد کاربران: {$updatedCount}\n" .
                          "• محدودیت جدید: {$limitText}\n" .
                          "• فیلتر اعمال شده: {$filter}\n\n" .
                          "💡 محدودیت دستگاه تمام کاربران تنظیم شد.";
            
            $this->telegramService->sendMessage($message->chat_id, $successText);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در بروزرسانی: " . $e->getMessage());
        }
    }
    
    private function bulkPlanEdit($message)
    {
        if (!isset($message->args[1]) || !isset($message->args[2])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ پارامترهای کافی وارد نشده\n\n" .
                "فرمت: /edit plan [پلن_ID] [فیلتر]\n\n" .
                "مثال:\n" .
                "/edit plan 2 active - تغییر پلن به ID شماره 2\n" .
                "/edit plan 0 expired - حذف پلن (تنظیم به null)");
            return;
        }
        
        $planId = (int)$message->args[1];
        $filter = $message->args[2];
        
        $users = $this->getUsersByFilter($filter);
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربری با این فیلتر پیدا نشد");
            return;
        }
        
        $updatedCount = 0;
        
        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                $user->plan_id = $planId ?: null;
                $user->save();
                $updatedCount++;
            }
            
            DB::commit();
            
            $planText = $planId > 0 ? "پلن ID: {$planId}" : "حذف پلن";
            
            $successText = "✅ پلن کاربران تغییر کرد\n\n" .
                          "📊 آمار عملیات:\n" .
                          "• تعداد کاربران: {$updatedCount}\n" .
                          "• پلن جدید: {$planText}\n" .
                          "• فیلتر اعمال شده: {$filter}\n\n" .
                          "💡 پلن تمام کاربران انتخاب شده تغییر کرد.";
            
            $this->telegramService->sendMessage($message->chat_id, $successText);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در بروزرسانی: " . $e->getMessage());
        }
    }
    
    private function bulkStatusEdit($message)
    {
        if (!isset($message->args[1]) || !isset($message->args[2])) {
            $this->telegramService->sendMessage($message->chat_id, 
                "❌ پارامترهای کافی وارد نشده\n\n" .
                "فرمت: /edit status [وضعیت] [فیلتر]\n\n" .
                "وضعیت‌های مجاز:\n" .
                "• ban - مسدود کردن\n" .
                "• unban - رفع مسدودیت\n\n" .
                "مثال:\n" .
                "/edit status ban expired - مسدود کردن منقضی‌ها\n" .
                "/edit status unban active - رفع مسدودیت فعال‌ها");
            return;
        }
        
        $status = $message->args[1];
        $filter = $message->args[2];
        
        if (!in_array($status, ['ban', 'unban'])) {
            $this->telegramService->sendMessage($message->chat_id, "❌ وضعیت نامعتبر. فقط 'ban' یا 'unban' مجاز است.");
            return;
        }
        
        $users = $this->getUsersByFilter($filter);
        
        if ($users->isEmpty()) {
            $this->telegramService->sendMessage($message->chat_id, "❌ کاربری با این فیلتر پیدا نشد");
            return;
        }
        
        $bannedValue = ($status === 'ban') ? 1 : 0;
        $updatedCount = 0;
        $adminSkipped = 0;
        
        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                // جلوگیری از مسدود کردن ادمین‌ها
                if ($status === 'ban' && $user->is_admin) {
                    $adminSkipped++;
                    continue;
                }
                
                $user->banned = $bannedValue;
                $user->save();
                $updatedCount++;
            }
            
            DB::commit();
            
            $statusText = ($status === 'ban') ? 'مسدود' : 'فعال';
            $warningText = $adminSkipped > 0 ? "\n\n⚠️ {$adminSkipped} ادمین از تغییر وضعیت مستثنی شدند." : "";
            
            $successText = "✅ وضعیت کاربران تغییر کرد\n\n" .
                          "📊 آمار عملیات:\n" .
                          "• کاربران تغییر یافته: {$updatedCount}\n" .
                          "• وضعیت جدید: {$statusText}\n" .
                          "• فیلتر اعمال شده: {$filter}" . 
                          $warningText . "\n\n" .
                          "💡 وضعیت تمام کاربران انتخاب شده تغییر کرد.";
            
            $this->telegramService->sendMessage($message->chat_id, $successText);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->telegramService->sendMessage($message->chat_id, "❌ خطا در بروزرسانی: " . $e->getMessage());
        }
    }
    
    private function showUserCounts($message)
    {
        $filter = isset($message->args[1]) ? $message->args[1] : 'all';
        
        $users = $this->getUsersByFilter($filter);
        $count = $users->count();
        
        // محاسبه آمار اضافی
        $totalTraffic = 0;
        $totalUsed = 0;
        $expiredCount = 0;
        $activeCount = 0;
        $bannedCount = 0;
        $telegramCount = 0;
        
        foreach ($users as $user) {
            $totalTraffic += $user->transfer_enable ?: 0;
            $totalUsed += ($user->u + $user->d) ?: 0;
            
            if ($user->expired_at && $user->expired_at <= time()) {
                $expiredCount++;
            }
            
            if (!$user->banned && ($user->expired_at > time() || !$user->expired_at)) {
                $activeCount++;
            }
            
            if ($user->banned) {
                $bannedCount++;
            }
            
            if ($user->telegram_id) {
                $telegramCount++;
            }
        }
        
        $totalTrafficGB = round($totalTraffic / (1024*1024*1024), 2);
        $totalUsedGB = round($totalUsed / (1024*1024*1024), 2);
        $usagePercent = $totalTrafficGB > 0 ? round(($totalUsedGB / $totalTrafficGB) * 100, 1) : 0;
        
        $countText = "📊 آمار کاربران (فیلتر: {$filter})\n\n" .
                    "👥 تعداد کل: {$count}\n" .
                    "🟢 فعال: {$activeCount}\n" .
                    "🔴 منقضی: {$expiredCount}\n" .
                    "🚫 مسدود: {$bannedCount}\n" .
                    "📱 متصل به تلگرام: {$telegramCount}\n\n" .
                    "📈 آمار ترافیک:\n" .
                    "💾 کل ترافیک: {$totalTrafficGB} GB\n" .
                    "📊 کل مصرف: {$totalUsedGB} GB\n" .
                    "🔋 درصد مصرف: {$usagePercent}%\n\n" .
                    "💡 برای عملیات جمعی از دستورات /edit استفاده کنید.";
        
        $this->telegramService->sendMessage($message->chat_id, $countText);
    }
    
    private function getUsersByFilter($filter)
    {
        $query = User::query();
        
        switch ($filter) {
            case 'active':
                $query->where('banned', 0)
                      ->where(function($q) {
                          $q->where('expired_at', '>', time())
                            ->orWhereNull('expired_at');
                      });
                break;
            case 'expired':
                $query->where('expired_at', '<=', time())
                      ->whereNotNull('expired_at');
                break;
            case 'banned':
                $query->where('banned', 1);
                break;
            case 'telegram':
                $query->whereNotNull('telegram_id');
                break;
            case 'notelegram':
                $query->whereNull('telegram_id');
                break;
            case 'admin':
                $query->where('is_admin', 1);
                break;
            case 'user':
                $query->where('is_admin', 0);
                break;
            case 'all':
            default:
                // همه کاربران
                break;
        }
        
        return $query->get();
    }
}
