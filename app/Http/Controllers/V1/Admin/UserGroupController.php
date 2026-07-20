<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Extra access groups for a single user.
 *
 * A user's own group_id keeps coming from their plan and is not touched here.
 * Rows in v2_user_group only ever ADD reach on top of it, so a user ends up
 * seeing the union of every node tagged with any of their groups. Removing all
 * the rows returns them to exactly the plan-driven behaviour.
 *
 * Changes apply immediately for the panel/app (the subscription reads these
 * rows fresh) and reach the nodes on their next poll, within about a minute.
 */
class UserGroupController extends Controller
{
    public function fetch(Request $request)
    {
        $userId = (int)$request->input('user_id');
        $user = User::find($userId);
        if (!$user) abort(500, 'کاربر یافت نشد');

        return response([
            'data' => [
                'user_id'         => $user->id,
                'primary_group_id' => $user->group_id,        // from their plan
                'extra_group_ids' => UserGroup::where('user_id', $user->id)
                    ->pluck('group_id')
                    ->map(function ($v) { return (int)$v; })
                    ->values(),
                'groups'          => ServerGroup::select(['id', 'name'])->get(),
            ]
        ]);
    }

    /**
     * Replace a user's extra groups with exactly the set given, so the UI can
     * just post whatever its multi-select currently shows.
     */
    public function save(Request $request)
    {
        $userId = (int)$request->input('user_id');
        $user = User::find($userId);
        if (!$user) abort(500, 'کاربر یافت نشد');

        $wanted = $request->input('group_ids', []);
        if (!is_array($wanted)) abort(500, 'group_ids باید آرایه باشد');
        $wanted = array_values(array_unique(array_map('intval', $wanted)));

        // Refuse ids that are not real groups: assigning one is harmless to the
        // database (nothing references v2_server_group) but it would silently
        // grant nothing, which looks like a bug to whoever set it.
        if ($wanted) {
            $known = ServerGroup::whereIn('id', $wanted)->pluck('id')->map(function ($v) { return (int)$v; })->all();
            $unknown = array_diff($wanted, $known);
            if ($unknown) abort(500, 'گروه نامعتبر: ' . implode(',', $unknown));
        }

        $current = UserGroup::where('user_id', $user->id)->pluck('group_id')->map(function ($v) { return (int)$v; })->all();
        $add    = array_diff($wanted, $current);
        $remove = array_diff($current, $wanted);

        DB::beginTransaction();
        try {
            if ($remove) {
                UserGroup::where('user_id', $user->id)->whereIn('group_id', $remove)->delete();
            }
            foreach ($add as $gid) {
                UserGroup::updateOrCreate(
                    ['user_id' => $user->id, 'group_id' => $gid],
                    ['updated_at' => time(), 'created_at' => time()]
                );
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            abort(500, 'ذخیره گروه‌ها ناموفق بود: ' . $e->getMessage());
        }

        return response([
            'data' => [
                'added'   => array_values($add),
                'removed' => array_values($remove),
                'extra_group_ids' => $wanted,
            ]
        ]);
    }
}
