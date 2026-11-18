<?php

namespace App\Http\Controllers\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Staff\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function getUserInfoById(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, 'پارامتر نادرست است');
        }
        // A staff account is a reseller: it sees its own customers only.
        $user = User::where('is_admin', 0)
            ->where('id', $request->input('id'))
            ->where('is_staff', 0)
            ->where('invite_user_id', $request->user['id'])
            ->first();
        if (!$user) abort(500, 'کاربر یافت نشد');
        return response([
            'data' => $user
        ]);
    }

    /**
     * Fields a reseller may change on its own customer. Everything that is
     * money or service - balance, commission, discount, plan, expiry, traffic -
     * is sold through ResellerController::assignPlan, which charges the
     * reseller's balance; setting it here gave it away.
     */
    private const EDITABLE = ['email', 'password', 'banned'];

    public function update(UserUpdate $request)
    {
        $params = $request->validated();
        // Only the reseller's own customers, never an admin or another
        // reseller (this could rewrite any account, the caller's own balance
        // and an admin's password included).
        $user = User::where('id', $request->input('id'))
            ->where('invite_user_id', $request->user['id'])
            ->where('is_admin', 0)
            ->where('is_staff', 0)
            ->first();
        if (!$user) {
            abort(500, 'کاربر یافت نشد');
        }
        // An edit form may post the whole record back; values it did not
        // change are fine, a changed one is refused rather than dropped.
        foreach ($params as $key => $value) {
            if (in_array($key, self::EDITABLE, true)) continue;
            $current = $user->{$key};
            $same = ($value === null && $current === null)
                || ($value !== null && $current !== null && (string)$value === (string)$current);
            if (!$same) {
                abort(500, 'این مورد توسط نماینده قابل تغییر نیست؛ برای تخصیص پلن از بخش نمایندگی استفاده کنید');
            }
            unset($params[$key]);
        }
        if (User::where('email', $params['email'])->first() && $user->email !== $params['email']) {
            abort(500, 'این ایمیل قبلاً استفاده شده است');
        }
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        try {
            $user->update($params);
            if (isset($params['password'])) {
                (new \App\Services\AuthService($user))->removeAllSession();
            }
        } catch (\Exception $e) {
            abort(500, 'ذخیره ناموفق بود');
        }
        return response([
            'data' => true
        ]);
    }

    /**
     * The staff member's own customers (the users it invited), narrowed by
     * the panel's filter rows. sendMail and ban called a filter() this class
     * never had, so both always failed; the admin's filter would have
     * reached every account on the panel.
     */
    private function filter(Request $request, $builder)
    {
        $builder->where('invite_user_id', $request->user['id']);
        $filters = $request->input('filter');
        if (!is_array($filters)) return;
        $keys = ['id', 'email', 'plan_id', 'banned', 'transfer_enable', 'd', 'expired_at', 'device_limit', 'remarks'];
        $conditions = ['=', '!=', '>', '<', '>=', '<=', 'like'];
        foreach ($filters as $filter) {
            if (!is_array($filter) || !in_array($filter['key'] ?? null, $keys, true) || !isset($filter['value'])) continue;
            $condition = $filter['condition'] ?? '=';
            $value = $filter['value'];
            if ($condition === '模糊' || $condition === 'تقریبی') {
                $condition = 'like';
                $value = "%{$value}%";
            }
            if (!in_array($condition, $conditions, true)) continue;
            if ($filter['key'] === 'plan_id' && $value === 'null') {
                $builder->whereNull('plan_id');
                continue;
            }
            if (in_array($filter['key'], ['d', 'transfer_enable'], true) && is_numeric($value)) {
                $value = $value * 1073741824;
            }
            $builder->where($filter['key'], $condition, $value);
        }
    }

    public function sendMail(UserSendMail $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        $users = $builder->get();
        foreach ($users as $user) {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $request->input('subject'),
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => $request->input('content')
                ]
            ]);
        }

        return response([
            'data' => true
        ]);
    }

    public function ban(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        try {
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            abort(500, 'پردازش ناموفق بود');
        }

        return response([
            'data' => true
        ]);
    }
}
