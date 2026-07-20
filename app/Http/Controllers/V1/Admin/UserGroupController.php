<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AddonBillingService;
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
    /**
     * Resolve the target user from either an id or an email.
     *
     * The web panel identifies the row by email on purpose: its user table
     * declares no rowKey, so the data-row-key antd falls back to is the row
     * INDEX, not the user id - reading it would edit whoever happens to sit at
     * that position. The email is on screen, unique, and cannot drift.
     */
    private function target(Request $request): User
    {
        $email = trim((string)$request->input('email', ''));
        $user = $email !== ''
            ? User::where('email', $email)->first()
            : User::find((int)$request->input('user_id'));
        if (!$user) abort(500, 'کاربر یافت نشد');
        return $user;
    }

    public function fetch(Request $request)
    {
        $user = $this->target($request);

        return response([
            'data' => [
                'user_id'         => $user->id,
                'email'           => $user->email,
                'balance'         => (int)$user->balance,
                'primary_group_id' => $user->group_id,        // from their plan
                'extra_group_ids' => UserGroup::where('user_id', $user->id)
                    ->pluck('group_id')
                    ->map(function ($v) { return (int)$v; })
                    ->values(),
                // price_per_gb travels with each group so the panel can say
                // plainly which of these will start charging the wallet.
                'groups'          => ServerGroup::select(['id', 'name', 'addon_enabled', 'price_per_gb'])
                    ->get()
                    ->map(function ($g) {
                        return [
                            'id'           => (int)$g->id,
                            'name'         => $g->name,
                            'metered'      => (bool)$g->addon_enabled && (int)$g->price_per_gb > 0,
                            'price_per_gb' => (int)$g->price_per_gb,
                        ];
                    }),
            ]
        ]);
    }

    /**
     * Replace a user's extra groups with exactly the set given, so the UI can
     * just post whatever its multi-select currently shows.
     */
    public function save(Request $request)
    {
        $user = $this->target($request);

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

        // Whether a grant is metered is decided by the GROUP, not per grant:
        // a group carrying a price is charged to the wallet, one without a
        // price is a plain admin grant. That keeps the whole arrangement in one
        // place, so pricing a tier later does not leave older grants free by
        // accident.
        $priced = AddonBillingService::paidGroups();
        $metered = [];

        DB::beginTransaction();
        try {
            if ($remove) {
                UserGroup::where('user_id', $user->id)->whereIn('group_id', $remove)->delete();
            }
            foreach ($add as $gid) {
                $isPaid = isset($priced[$gid]);
                if ($isPaid) $metered[] = $gid;
                UserGroup::updateOrCreate(
                    ['user_id' => $user->id, 'group_id' => $gid],
                    [
                        'is_paid' => $isPaid ? 1 : 0,
                        // A paid tier rides along with the plan the user already
                        // holds and ends when it does, so it can never outlive
                        // the subscription it was added to.
                        'expired_at' => $isPaid ? $user->expired_at : null,
                        'unbilled_bytes' => 0,
                        'updated_at' => time(),
                        'created_at' => time(),
                    ]
                );
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            abort(500, 'ذخیره گروه‌ها ناموفق بود: ' . $e->getMessage());
        }

        return response([
            'data' => [
                'user_id' => $user->id,
                'email'   => $user->email,   // so the caller can prove it hit the right row
                'added'   => array_values($add),
                'removed' => array_values($remove),
                'extra_group_ids' => $wanted,
            ]
        ]);
    }
}
