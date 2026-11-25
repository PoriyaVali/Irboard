<?php

namespace App\Services\Telegram;

use App\Models\BotSetting;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Facades\DB;

class TelegramTrialService
{
    /** Use the panel's real trial plan; never claim an external account was made. */
    public function activate(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $plan = Plan::find((int) config('v2board.try_out_plan_id', 0));
            $hours = (float) config('v2board.try_out_hour', 1);
            $limit = (int) BotSetting::get('test_limit', 1);
            if (!$plan || $hours <= 0 || $plan->transfer_enable <= 0 || $limit <= 0) {
                return ['success' => false, 'message' => 'اکانت تست در حال حاضر فعال نیست.'];
            }
            if ($user->banned || $user->is_admin || $user->is_staff || $user->bot_test_count >= $limit
                || $user->plan_id || Order::where('user_id', $user->id)->whereIn('status', [1, 3, 4])->exists()) {
                return ['success' => false, 'message' => 'اکانت تست مخصوص حساب‌های جدید و بدون اشتراک است؛ سرویس فعلی شما حفظ می‌شود.'];
            }
            if (!(new PlanService($plan->id))->haveCapacity()) {
                return ['success' => false, 'message' => 'ظرفیت اکانت تست تکمیل شده است.'];
            }
            $user->forceFill([
                'plan_id' => $plan->id,
                'group_id' => $plan->group_id,
                'transfer_enable' => (int) ($plan->transfer_enable * 1073741824),
                'u' => 0,
                'd' => 0,
                'expired_at' => time() + (int) ($hours * 3600),
                'speed_limit' => $plan->speed_limit,
                'device_limit' => $plan->device_limit,
                'bot_test_count' => (int) $user->bot_test_count + 1,
                'bot_step' => null,
                'bot_data' => null,
            ])->save();
            return ['success' => true, 'message' => 'اکانت تست فعال شد.'];
        });
    }
}
