<?php
namespace App\Http\Controllers\V1\Admin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
class StaffController extends Controller
{
    public function list(Request $request)
    {
        $users = User::where(function ($q) {
                $q->where('is_admin', 1)->orWhere('is_staff', 1);
            })
            ->select(['id', 'email', 'is_admin', 'is_staff', 'last_login_at', 'created_at'])
            ->orderBy('is_admin', 'desc')
            ->orderBy('is_staff', 'desc')
            ->orderBy('id', 'asc')
            ->get();
        return response(['data' => $users]);
    }

    public function tree(Request $request)
    {
        $admins = User::where('is_admin', 1)
            ->select(['id', 'email', 'invite_user_id', 'last_login_at', 'created_at'])
            ->orderBy('id', 'asc')->get();

        $staffs = User::where('is_staff', 1)->where('is_admin', 0)
            ->select(['id', 'email', 'invite_user_id', 'balance', 'last_login_at', 'created_at'])
            ->orderBy('id', 'asc')->get();

        $staffIds = $staffs->pluck('id')->all();
        $counts = [];
        if (!empty($staffIds)) {
            $rows = User::whereIn('invite_user_id', $staffIds)
                ->selectRaw('invite_user_id, COUNT(*) as c')
                ->groupBy('invite_user_id')->get();
            foreach ($rows as $r) {
                $counts[$r->invite_user_id] = (int) $r->c;
            }
        }

        $adminIds = $admins->pluck('id')->all();

        $staffNodes = [];
        foreach ($staffs as $s) {
            $staffNodes[] = [
                'id' => $s->id,
                'email' => $s->email,
                'invite_user_id' => $s->invite_user_id,
                'balance' => $s->balance,
                'last_login_at' => $s->last_login_at,
                'created_at' => $s->created_at,
                'users_count' => $counts[$s->id] ?? 0,
            ];
        }

        $adminNodes = [];
        foreach ($admins as $a) {
            $children = [];
            foreach ($staffNodes as $sn) {
                if ($sn['invite_user_id'] == $a->id) {
                    $children[] = $sn;
                }
            }
            $adminNodes[] = [
                'id' => $a->id,
                'email' => $a->email,
                'last_login_at' => $a->last_login_at,
                'created_at' => $a->created_at,
                'staff' => $children,
            ];
        }

        $orphanStaff = [];
        foreach ($staffNodes as $sn) {
            if (!in_array($sn['invite_user_id'], $adminIds)) {
                $orphanStaff[] = $sn;
            }
        }

        return response([
            'data' => [
                'admins' => $adminNodes,
                'orphan_staff' => $orphanStaff,
            ],
        ]);
    }

    public function users(Request $request)
    {
        $staffId = (int) $request->input('staff_id');
        if ($staffId <= 0) {
            return response(['data' => []]);
        }
        $users = User::where('v2_user.invite_user_id', $staffId)
            ->leftJoin('v2_plan', 'v2_plan.id', '=', 'v2_user.plan_id')
            ->select([
                'v2_user.id', 'v2_user.email', 'v2_user.telegram_id',
                'v2_user.banned', 'v2_user.is_admin', 'v2_user.is_staff',
                'v2_user.created_at', 'v2_user.last_login_at', 'v2_user.last_login_ip',
                'v2_user.t', 'v2_user.expired_at', 'v2_user.transfer_enable',
                'v2_user.u', 'v2_user.d', 'v2_user.device_limit', 'v2_user.plan_id',
                'v2_plan.name as plan_name',
            ])
            ->orderBy('v2_user.id', 'desc')
            ->limit(1000)
            ->get();
        return response(['data' => $users]);
    }
}
