<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AddonBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Metered add-on tiers, bought by the customer for themselves.
 *
 * This is the self-serve half of the grants an operator can already hand out
 * from V1\Admin\UserGroupController. The rules are deliberately the same ones
 * that controller and ServerService already apply, so a tier behaves
 * identically whether an admin switched it on or the customer did:
 *
 *   - a tier only ever ADDS nodes on top of the plan's own group,
 *   - it needs one gigabyte's worth in the wallet to switch on, and then
 *     runs down to the last rial,
 *   - it ends when the base plan does.
 *
 * The customer is always taken from the authenticated session. Nothing here
 * reads a user id from the request: the admin endpoint accepts one because an
 * operator acts on somebody else, which is exactly what a customer must never
 * be able to do.
 */
class AddonController extends Controller
{
    private function self(Request $request): User
    {
        $user = User::find($request->user['id']);
        if (!$user) abort(500, 'کاربر یافت نشد');
        return $user;
    }

    /**
     * Every tier on sale, each already answered for this customer: can they
     * switch it on, is it on now, and what would it cost them.
     */
    public function list(Request $request)
    {
        $user = $this->self($request);
        $priced = AddonBillingService::paidGroups();

        // A tier rides on top of a plan and cannot replace one. Without a base
        // plan the user's transfer_enable is 0, and getAvailableUsers filters
        // on `u + d < transfer_enable`, so they would pay and still reach no
        // node at all. Refusing here is the difference between an explanation
        // and a silent loss.
        $hasPlan = (int)$user->plan_id > 0;

        // ALL grants, not just the bought ones. A tier the admin granted free
        // (is_paid=0 - today that is the owner's own test account) used to be
        // invisible here, so the screen offered it for sale and enable() then
        // died with "already active" - a dead-end button. It is reported as its
        // own state below instead.
        $now = time();
        $grants = UserGroup::where('user_id', $user->id)->get()->keyBy('group_id');

        $balance = (int)$user->balance;
        $items = [];
        foreach ($priced as $gid => $group) {
            $price = (int)$group->price_per_gb;
            $need  = AddonBillingService::minimumBalance($group);
            $grant = $grants->get($gid);
            // Liveness mirrors enable(): a row past its expired_at is a dead
            // leftover that enable() happily re-activates, so it must read as
            // OFF here too - not as an active tier with a date in the past.
            $live    = $grant !== null && ($grant->expired_at === null || (int)$grant->expired_at > $now);
            $on      = $live && (bool)$grant->is_paid;
            $granted = $live && !$grant->is_paid;

            $items[] = [
                'group_id'     => (int)$gid,
                'name'         => $group->name,
                'price_per_gb' => $price,
                'min_balance'  => $need,
                'active'       => $on,
                // An admin's free grant: served in full, never billed, and not
                // the customer's to switch on or off. A separate flag so the
                // app can show it as what it is; older app builds that ignore
                // it still can't dead-end, because can_enable is false and the
                // reason says why.
                'granted'      => $granted,
                // What the wallet is worth in the unit the customer thinks in.
                'balance_gb'   => $price > 0 ? (int)floor($balance / $price) : 0,
                'expired_at'   => $on ? $grant->expired_at : null,
                'can_enable'   => !$on && !$granted && $hasPlan && $balance >= $need,
                'reason'       => $this->whyNot($on, $granted, $hasPlan, $balance, $need),
            ];
        }

        return response([
            'data' => [
                'balance'  => $balance,
                'has_plan' => $hasPlan,
                'items'    => $items,
            ]
        ]);
    }

    /**
     * Why the enable button is unavailable, in the customer's words. Returning
     * this alongside the flag keeps the app from having to re-derive the rules
     * and drift out of step with them.
     */
    private function whyNot(bool $on, bool $granted, bool $hasPlan, int $balance, int $need): ?string
    {
        if ($on) return null;
        if ($granted) return 'این دسترسی برای حساب شما رایگان فعال شده است.';
        if (!$hasPlan) return 'برای فعال‌سازی این افزودنی، ابتدا باید یک پلن پایه داشته باشید.';
        if ($balance < $need) {
            return sprintf('حداقل %s تومان موجودی لازم است (موجودی شما: %s).',
                number_format($need), number_format($balance));
        }
        return null;
    }

    public function enable(Request $request)
    {
        $user = $this->self($request);
        $gid  = (int)$request->input('group_id');

        $priced = AddonBillingService::paidGroups();
        // Only a group the operator has actually put on sale can be bought.
        // Anything else would grant reach for free, since billing keys off the
        // very same price that is missing here.
        if (!isset($priced[$gid])) abort(500, 'این افزودنی برای فروش فعال نیست');
        $group = $priced[$gid];

        if ((int)$user->plan_id <= 0) {
            abort(500, 'برای فعال‌سازی این افزودنی، ابتدا باید یک پلن پایه داشته باشید.');
        }

        // A grant that is still live means it really is already on. But a grant
        // whose expired_at has passed is a dead row - left behind when a plan
        // lapsed and was renewed without the grant following (see
        // syncGrantExpiry) - and refusing on its mere existence would trap the
        // customer: the tier is dead, yet they cannot switch it back on. So an
        // expired grant is treated as re-activatable, not as "already active".
        $existing = UserGroup::where('user_id', $user->id)->where('group_id', $gid)->first();
        if ($existing && ($existing->expired_at === null || (int)$existing->expired_at > time())) {
            abort(500, 'این افزودنی از قبل فعال است');
        }

        $need = AddonBillingService::minimumBalance($group);
        if ((int)$user->balance < $need) {
            abort(500, sprintf('برای فعال‌سازی «%s» حداقل %s تومان موجودی لازم است (موجودی شما: %s).',
                $group->name, number_format($need), number_format((int)$user->balance)));
        }

        DB::beginTransaction();
        try {
            UserGroup::updateOrCreate(
                ['user_id' => $user->id, 'group_id' => $gid],
                [
                    'is_paid' => 1,
                    // Snapshotted from the plan it rides on. Renewals keep this
                    // in step through AddonBillingService::syncGrantExpiry; on a
                    // re-activation here it is simply taken fresh.
                    'expired_at' => $user->expired_at,
                    // Reset the carry so a revived grant does not inherit a
                    // fraction of a gigabyte left over from its previous life.
                    'unbilled_bytes' => 0,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]
            );
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            abort(500, 'فعال‌سازی ناموفق بود: ' . $e->getMessage());
        }

        return response(['data' => true]);
    }

    /**
     * Switching a tier off drops the grant, so the nodes it added disappear
     * from the next subscription fetch and the node stops serving them within
     * about a minute. Any bytes already carried on the grant go with it -
     * fractions of a gigabyte the customer was never charged for.
     */
    public function disable(Request $request)
    {
        $user = $this->self($request);
        $gid  = (int)$request->input('group_id');

        $deleted = UserGroup::where('user_id', $user->id)
            ->where('group_id', $gid)
            ->where('is_paid', 1)
            ->delete();

        if (!$deleted) abort(500, 'این افزودنی فعال نیست');

        return response(['data' => true]);
    }

    /**
     * What this customer has spent per tier, newest day first, straight from
     * the ledger the billing job writes.
     */
    public function usage(Request $request)
    {
        $user = $this->self($request);
        $names = ServerGroup::pluck('name', 'id');

        $rows = DB::table('v2_user_group_usage')
            ->where('user_id', $user->id)
            ->orderByDesc('record_at')
            ->limit(60)
            ->get();

        return response([
            'data' => $rows->map(function ($r) use ($names) {
                return [
                    // record_at is midnight of the day as a unix timestamp.
                    'record_at' => (int)$r->record_at,
                    'group_id'  => (int)$r->group_id,
                    'name'      => $names[$r->group_id] ?? ('گروه #' . $r->group_id),
                    'bytes'     => (int)$r->u + (int)$r->d,
                    'amount'    => (int)$r->amount,
                ];
            })->values()
        ]);
    }
}
