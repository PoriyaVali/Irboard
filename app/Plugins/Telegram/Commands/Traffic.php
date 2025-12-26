<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

class Traffic extends Telegram {
    public $command = '/traffic';
    public $description = 'استعلام اطلاعات ترافیک';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, 'اطلاعات کاربری شما یافت نشد، لطفاً ابتدا حساب خود را متصل کنید', 'markdown');
            return;
        }
        
        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d));
        
        // تبدیل تاریخ انقضا به شمسی
        $expiryDate = 'نامحدود';
        $remainingDays = '';
        
        if ($user->expired_at) {
            // تبدیل به Carbon object
            $carbonDate = is_numeric($user->expired_at) 
                ? Carbon::createFromTimestamp($user->expired_at) 
                : Carbon::parse($user->expired_at);
            
            // تبدیل به تاریخ شمسی
            $jalalianDate = Jalalian::fromCarbon($carbonDate);
            $expiryDate = $jalalianDate->format('Y/m/d H:i:s');
            
            // محاسبه روزهای باقیمانده
            $now = Carbon::now();
            if ($carbonDate->isFuture()) {
                $days = $now->diffInDays($carbonDate);
                $remainingDays = "\nروزهای باقیمانده: `{$days} روز`";
            } else {
                $remainingDays = "\n⚠️ اشتراک منقضی شده";
            }
        }
        
        $text = "🚥استعلام ترافیک\n———————————————\n"
              . "ترافیک پلن: `{$transferEnable}`\n"
              . "آپلود مصرفی: `{$up}`\n"
              . "دانلود مصرفی: `{$down}`\n"
              . "ترافیک باقیمانده: `{$remaining}`\n"
              . "———————————————\n"
              . "📅 تاریخ انقضا: `{$expiryDate}`"
              . $remainingDays;
              
        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
