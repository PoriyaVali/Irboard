<?php

namespace App\Http\Controllers\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResellerController extends Controller
{
    /**
     * آمار کلی نماینده
     */
    public function dashboard(Request $request)
    {
        $staffId = $request->user['id'];
        $staff = User::find($staffId);

        $totalUsers = User::where('invite_user_id', $staffId)->count();
        $activeUsers = User::where('invite_user_id', $staffId)
            ->where('expired_at', '>', time())
            ->whereNotNull('plan_id')
            ->count();
        $bannedUsers = User::where('invite_user_id', $staffId)
            ->where('banned', 1)
            ->count();

        return response([
            'data' => [
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'banned_users' => $bannedUsers,
                'balance' => $staff->balance,
                'commission_balance' => $staff->commission_balance,
                'discount' => $staff->discount ?? 0,
            ]
        ]);
    }

    /**
     * لیست کاربران زیرمجموعه
     */
    public function userList(Request $request)
    {
        $staffId = $request->user['id'];
        $current = $request->input('current', 1);
        $pageSize = $request->input('page_size', 15);

        $builder = User::where('invite_user_id', $staffId)
            ->select(['id', 'email', 'plan_id', 'transfer_enable', 'u', 'd', 'expired_at', 'banned', 'created_at', 'token', 'device_limit'])
            ->orderBy('created_at', 'DESC');

        if ($request->input('search')) {
            $builder->where('email', 'like', '%' . $request->input('search') . '%');
        }

        $total = $builder->count();
        $users = $builder->forPage($current, $pageSize)->get();

        // اضافه کردن نام پلن
        $users->transform(function ($user) {
            $plan = Plan::find($user->plan_id);
            $user->plan_name = $plan ? $plan->name : '-';
            $user->used_gb = round(($user->u + $user->d) / 1073741824, 2);
            $user->total_gb = round($user->transfer_enable / 1073741824, 2);
            $user->is_expired = $user->expired_at && $user->expired_at < time();
            // تعداد آنلاین
            $aliveData = \Illuminate\Support\Facades\Cache::get('ALIVE_IP_USER_' . $user->id);
            $user->online_count = $aliveData ? (int)$aliveData['alive_ip'] : 0;
            $user->device_limit = $user->device_limit ?? 0;
            return $user;
        });

        return response([
            'data' => $users,
            'total' => $total
        ]);
    }

    /**
     * ساخت حساب کاربری جدید
     */
    public function createUser(Request $request)
    {
        $staffId = $request->user['id'];
        $email = $request->input('email');
        $password = $request->input('password');

        if (!$email || !$password) {
            abort(500, 'ایمیل و رمز عبور الزامی است');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(500, 'فرمت ایمیل نامعتبر است');
        }

        if (strlen($password) < 6) {
            abort(500, 'رمز عبور باید حداقل 8 کاراکتر باشد');
        }

        if (User::where('email', $email)->first()) {
            abort(500, 'این ایمیل قبلاً ثبت شده است');
        }

        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->invite_user_id = $staffId;
        $user->created_at = time();
        $user->updated_at = time();

        if (!$user->save()) {
            abort(500, 'خطا در ساخت حساب کاربری');
        }

        return response([
            'data' => [
                'id' => $user->id,
                'email' => $user->email
            ],
            'message' => 'حساب کاربری با موفقیت ساخته شد'
        ]);
    }

    /**
     * تخصیص پلن به کاربر (با کسر از موجودی نماینده)
     */
    public function assignPlan(Request $request)
    {
        $staffId = $request->user['id'];
        $staff = User::find($staffId);
        $userId = $request->input('user_id');
        $planId = $request->input('plan_id');
        $period = $request->input('period', 'month_price');

        $user = User::where('id', $userId)
            ->where('invite_user_id', $staffId)
            ->first();

        if (!$user) {
            abort(500, 'کاربر یافت نشد یا متعلق به شما نیست');
        }

        $plan = Plan::find($planId);
        if (!$plan) {
            abort(500, 'پلن یافت نشد');
        }

        // قیمت با تخفیف نماینده
        $price = $plan->$period ?? 0;
        if ($price <= 0) {
            abort(500, 'این دوره برای این پلن فعال نیست');
        }

        $discount = $staff->discount ?? 0;
        $finalPrice = (int)($price * (100 - $discount) / 100);

        if ($staff->balance < $finalPrice) {
            abort(500, 'موجودی کافی نیست. نیاز: ' . number_format($finalPrice) . ' تومان');
        }

        // محاسبه مدت
        $periodDays = [
            'month_price' => 30,
            'quarter_price' => 90,
            'half_year_price' => 180,
            'year_price' => 365,
            'two_year_price' => 730,
            'three_year_price' => 1095,
            'onetime_price' => 365 * 99,
        ];

        $days = $periodDays[$period] ?? 30;

        DB::beginTransaction();
        try {
            // کسر اتمیک از موجودی نماینده (جلوگیری از race condition)
            $affected = DB::table('v2_user')
                ->where('id', $staffId)
                ->where('balance', '>=', $finalPrice)
                ->update(['balance' => DB::raw('balance - ' . (int)$finalPrice)]);

            if (!$affected) {
                DB::rollBack();
                abort(500, 'موجودی کافی نیست یا خطا در کسر موجودی');
            }

            // تنظیم پلن کاربر
            $user->plan_id = $plan->id;
            $user->group_id = $plan->group_id;
            $user->transfer_enable = $plan->transfer_enable * 1073741824;
            $user->speed_limit = $plan->speed_limit ?? 0;
            $user->device_limit = $plan->device_limit ?? null;
            $user->u = 0;
            $user->d = 0;
            $user->expired_at = time() + ($days * 86400);
            $user->save();

            // A reseller re-assigning or renewing a plan moves the user's expiry
            // just like a normal purchase does, so carry any paid add-on grant
            // with it - otherwise the tier the customer paid for would keep the
            // old plan's expiry and be cut off early.
            \App\Services\AddonBillingService::syncGrantExpiry($user->id, $user->expired_at);

            // ثبت لاگ تراکنش
            DB::table('v2_reseller_log')->insert([
                'staff_id' => $staffId,
                'user_id' => $userId,
                'plan_id' => $planId,
                'period' => $period,
                'original_price' => $price,
                'discount' => $discount,
                'final_price' => $finalPrice,
                'staff_balance_after' => DB::table('v2_user')->where('id', $staffId)->value('balance'),
                'description' => 'تخصیص پلن ' . $plan->name . ' (' . self::periodLabel($period) . ') به ' . $user->email,
                'created_at' => time()
            ]);

            DB::commit();

            return response([
                'data' => true,
                'message' => 'پلن با موفقیت تخصیص داده شد. مبلغ کسر شده: ' . number_format($finalPrice) . ' تومان'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, 'خطا در تخصیص پلن: ' . $e->getMessage());
        }
    }

    /**
     * لیست پلن‌های موجود با قیمت تخفیف‌دار
     */
    public function planList(Request $request)
    {
        $staffId = $request->user['id'];
        $staff = User::find($staffId);
        $discount = $staff->discount ?? 0;

        $plans = Plan::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get(['id', 'name', 'month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price', 'onetime_price', 'transfer_enable', 'speed_limit']);

        $plans->transform(function ($plan) use ($discount) {
            $periods = ['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price', 'onetime_price'];
            foreach ($periods as $p) {
                if ($plan->$p && $plan->$p > 0) {
                    $plan->{$p . '_discounted'} = (int)($plan->$p * (100 - $discount) / 100);
                }
            }
            $plan->discount = $discount;
            $plan->transfer_gb = round($plan->transfer_enable / 1073741824, 2);
            return $plan;
        });

        return response([
            'data' => $plans
        ]);
    }

    /**
     * ویرایش حجم و تاریخ انقضای کاربر
     */
    public function updateUser(Request $request)
    {
        $staffId = $request->user['id'];
        $userId = $request->input('user_id');

        $user = User::where('id', $userId)
            ->where('invite_user_id', $staffId)
            ->first();

        if (!$user) {
            abort(500, 'کاربر یافت نشد یا متعلق به شما نیست');
        }

        $updated = false;
        $changes = [];

        // بن/آنبن
        if ($request->has('banned')) {
            $oldVal = $user->banned;
            $user->banned = $request->input('banned') ? 1 : 0;
            $updated = true;
            $changes[] = $user->banned ? 'مسدود شد' : 'رفع مسدودی شد';
        }

        // تغییر ایمیل
        if ($request->input('email')) {
            $newEmail = $request->input('email');
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                abort(500, 'فرمت ایمیل نامعتبر است');
            }
            $exists = User::where('email', $newEmail)->where('id', '!=', $userId)->first();
            if ($exists) {
                abort(500, 'این ایمیل قبلاً استفاده شده است');
            }
            $oldEmail = $user->email;
            $user->email = $newEmail;
            $updated = true;
            $changes[] = 'تغییر ایمیل از ' . $oldEmail . ' به ' . $newEmail;
        }

        // تغییر رمز عبور
        if ($request->input('password')) {
            if (strlen($request->input('password')) < 8) {
                abort(500, 'رمز عبور باید حداقل 8 کاراکتر باشد');
            }
            $user->password = password_hash($request->input('password'), PASSWORD_DEFAULT);
            $user->password_algo = null;
            $updated = true;
            $changes[] = 'رمز عبور تغییر کرد';
        }

        // تغییر لینک اشتراک (ریست uuid و token)
        if ($request->input('reset_subscription')) {
            $user->uuid = \App\Utils\Helper::guid(true);
            $user->token = \App\Utils\Helper::guid();
            $updated = true;
            $changes[] = 'لینک اشتراک تغییر کرد';
        }

        if (!$updated) {
            abort(500, 'هیچ تغییری ارسال نشده');
        }

        $user->save();

        // ثبت لاگ
        foreach ($changes as $change) {
            DB::table('v2_reseller_log')->insert([
                'staff_id' => $staffId,
                'user_id' => $userId,
                'plan_id' => 0,
                'period' => 'update',
                'original_price' => 0,
                'discount' => 0,
                'final_price' => 0,
                'staff_balance_after' => 0,
                'description' => $change . ' - ' . $user->email,
                'created_at' => time()
            ]);
        }

        return response([
            'data' => true,
            'message' => 'اطلاعات کاربر بروزرسانی شد'
        ]);
    }

    /**
     * جزئیات یک کاربر
     */
    public function userDetail(Request $request)
    {
        $staffId = $request->user['id'];
        $userId = $request->input('user_id');

        $user = User::where('id', $userId)
            ->where('invite_user_id', $staffId)
            ->first();

        if (!$user) {
            abort(500, 'کاربر یافت نشد یا متعلق به شما نیست');
        }

        $plan = Plan::find($user->plan_id);

        return response([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'plan_id' => $user->plan_id,
                'plan_name' => $plan ? $plan->name : '-',
                'transfer_enable' => $user->transfer_enable,
                'transfer_enable_gb' => round($user->transfer_enable / 1073741824, 2),
                'used_gb' => round(($user->u + $user->d) / 1073741824, 2),
                'u' => $user->u,
                'd' => $user->d,
                'expired_at' => $user->expired_at,
                'banned' => $user->banned,
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
            ]
        ]);
    }

    private static function periodLabel($period)
    {
        $labels = [
            "month_price" => "ماهانه",
            "quarter_price" => "سه ماهه",
            "half_year_price" => "شش ماهه",
            "year_price" => "سالانه",
            "two_year_price" => "دو ساله",
            "three_year_price" => "سه ساله",
            "onetime_price" => "یکبار مصرف",
        ];
        return $labels[$period] ?? $period;
    }

    public function logs(Request $request)
    {
        $staffId = $request->user['id'];
        $current = $request->input('current', 1);
        $pageSize = $request->input('page_size', 20);

        $builder = DB::table('v2_reseller_log')
            ->where('staff_id', $staffId)
            ->orderBy('created_at', 'DESC');

        $total = $builder->count();
        $logs = $builder->forPage($current, $pageSize)->get();

        $logs->transform(function($log) {
            $log->date = date('Y/m/d H:i', $log->created_at);
            if ($log->period !== 'update' && $log->final_price > 0) {
                $log->type = 'assign';
            } else {
                $log->type = 'update';
            }
            return $log;
        });

        return response([
            'data' => $logs,
            'total' => $total
        ]);
    }
}
