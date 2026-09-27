<?php

namespace Tests\Feature\Concerns;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\InstallSchema;

/**
 * The tables and rows the money-path tests share. Tables come from
 * database/install.sql (see InstallSchema), rows are made with just the
 * columns a scenario needs.
 */
trait PanelFixtures
{
    use InstallSchema;

    protected function setUpPanel(): void
    {
        $this->createInstallTables([
            'v2_user', 'v2_plan', 'v2_order', 'v2_reserved_plans', 'v2_coupon',
            'v2_card_payments', 'v2_user_group', 'v2_server_group', 'v2_user_group_usage',
            'v2_payment', 'v2_giftcard', 'v2_invite_code', 'v2_bot_channels',
            'v2_bot_settings', 'v2_commission_log', 'payment_tracks',
            'v2_ticket', 'v2_ticket_message',
        ]);
        // Order creation reads the dollar rate; never fetch it over the network.
        Cache::put('exchange_rate', ['rate' => 60000, 'time' => time(), 'date' => date('Y-m-d H:i:s')], 3600);
    }

    protected function makePlan(array $attrs = []): Plan
    {
        $id = DB::table('v2_plan')->insertGetId(array_merge([
            'group_id' => 1,
            'transfer_enable' => 100,
            'name' => 'plan ' . uniqid(),
            'show' => 1,
            'renew' => 1,
            'month_price' => 100000,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        return Plan::find($id);
    }

    protected function makeUser(array $attrs = []): User
    {
        $id = DB::table('v2_user')->insertGetId(array_merge([
            'email' => uniqid('u', true) . '@example.com',
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'uuid' => (string)\Illuminate\Support\Str::uuid(),
            'token' => md5(uniqid('', true)),
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        return User::find($id);
    }

    /** A user holding $plan with plenty of time and traffic left. */
    protected function makeActiveUser(Plan $plan, array $attrs = []): User
    {
        return $this->makeUser(array_merge([
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => $plan->transfer_enable * 1073741824,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 20 * 86400,
        ], $attrs));
    }

    protected function makeOrder(User $user, array $attrs = []): Order
    {
        $id = DB::table('v2_order')->insertGetId(array_merge([
            'user_id' => $user->id,
            'plan_id' => 0,
            'type' => 1,
            'period' => 'month_price',
            'trade_no' => md5(uniqid('', true)),
            'total_amount' => 100000,
            'status' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        return Order::find($id);
    }
}
