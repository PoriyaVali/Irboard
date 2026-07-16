<?php
namespace App\Console\Commands;

use App\Models\ReservedPlan;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ActivateReservedPlans extends Command
{
    protected $signature = 'reserved:activate';
    protected $description = 'فعال‌سازی بسته‌های رزرو شده';

    public function handle()
    {
        $users = User::whereIn('id', function ($query) {
            $query->select('user_id')->from('v2_reserved_plans')->where('status', 0);
        })->get();

        foreach ($users as $user) {
            if (!$this->needsActivation($user)) continue;

            $reserved = ReservedPlan::where('user_id', $user->id)
                ->where('status', 0)
                ->orderBy('created_at', 'asc')
                ->first();

            if (!$reserved) continue;

            $plan = Plan::find($reserved->plan_id);
            if (!$plan) continue;

            $order = Order::find($reserved->order_id);
            if (!$order) continue;

            try {
                DB::beginTransaction();

                $orderService = new OrderService($order);
                $orderService->user = $user;

                // ریست ترافیک
                $user->u = 0;
                $user->d = 0;
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->device_limit = $plan->device_limit;
                $user->speed_limit = $plan->speed_limit;
                $user->expired_at = $orderService->getTimePublic($order->period, time());

                if (!$user->save()) {
                    DB::rollBack();
                    continue;
                }

                $reserved->status = 1;
                $reserved->activated_at = time();
                $reserved->updated_at = time();
                $reserved->save();

                DB::commit();
                Log::info("بسته رزرو #{$reserved->id} برای کاربر #{$user->id} فعال شد");
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("خطا در فعال‌سازی بسته رزرو #{$reserved->id}: " . $e->getMessage());
            }
        }
    }

    private function needsActivation($user)
    {
        if ($user->plan_id === null) return true;
        if ($user->expired_at !== null && $user->expired_at <= time()) return true;

        // حجم کمتر از 100 مگابایت
        $remaining = $user->transfer_enable - ($user->u + $user->d);
        if ($remaining <= 104857600) return true;

        // زمان کمتر از 1 ساعت
        if ($user->expired_at !== null && ($user->expired_at - time()) <= 3600) return true;

        return false;
    }
}
