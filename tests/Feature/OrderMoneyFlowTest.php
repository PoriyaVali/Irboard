<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ReservedPlan;
use App\Models\User;
use App\Services\OrderService;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * Orders: queueing (reservation) against plan-change credit, cancelling with
 * the wallet refund, and a payment arriving for an order in any state.
 */
class OrderMoneyFlowTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
    }

    /** A yearly plan bought a month ago, still running. */
    private function userOnYearlyPlan(): array
    {
        $old = $this->makePlan(['year_price' => 1200000]);
        $user = $this->makeActiveUser($old, ['expired_at' => time() + 330 * 86400]);
        $this->makeOrder($user, [
            'plan_id' => $old->id,
            'period' => 'year_price',
            'total_amount' => 1200000,
            'status' => 3,
            'created_at' => time() - 30 * 86400,
        ]);
        return [$old, $user];
    }

    private function newChangeOrder(User $user, $plan, string $period = 'month_price'): Order
    {
        $order = new Order();
        $service = new OrderService($order);
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $order->period = $period;
        $order->trade_no = md5(uniqid('', true));
        $order->total_amount = $plan->{$period};
        $service->setVipDiscount($user);
        $service->setOrderType($user);
        $order->save();
        return $order->fresh();
    }

    public function testAPlanChangeThatWillQueueGetsNoSurplusCredit()
    {
        [, $user] = $this->userOnYearlyPlan();
        $new = $this->makePlan(['month_price' => 500000]);

        $order = $this->newChangeOrder($user, $new);

        $this->assertSame(3, (int)$order->type);
        $this->assertEmpty($order->surplus_order_ids);
        $this->assertSame(500000, (int)$order->total_amount);
    }

    public function testAQueuedPlanChangeKeepsTheCurrentPlanAndIsReserved()
    {
        [$old, $user] = $this->userOnYearlyPlan();
        $new = $this->makePlan(['month_price' => 500000]);
        $order = $this->newChangeOrder($user, $new);

        $this->assertTrue(OrderService::isReserved($order));
        $this->assertTrue((new OrderService($order))->paid('test'));

        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertTrue(ReservedPlan::where('order_id', $order->id)->where('status', 0)->exists());
        $this->assertSame($old->id, (int)$user->fresh()->plan_id);
    }

    public function testAnOrderCarryingSurplusReplacesThePlanInsteadOfQueueing()
    {
        // An order priced with a surplus credit before this fix: it must be
        // applied as a change, not queued behind the plan it was credited for.
        [$old, $user] = $this->userOnYearlyPlan();
        $new = $this->makePlan(['month_price' => 500000]);
        $oldOrderIds = Order::where('user_id', $user->id)->pluck('id')->all();
        $order = $this->makeOrder($user, [
            'plan_id' => $new->id,
            'type' => 3,
            'total_amount' => 0,
            'surplus_amount' => 800000,
            'refund_amount' => 300000,
            'surplus_order_ids' => json_encode($oldOrderIds),
        ]);

        $this->assertFalse(OrderService::isReserved($order));
        (new OrderService($order))->paid('test');

        $user->refresh();
        $this->assertSame($new->id, (int)$user->plan_id);
        $this->assertFalse(ReservedPlan::where('order_id', $order->id)->exists());
        $this->assertSame(300000, (int)$user->balance, 'refund of the credit beyond the new price');
        foreach ($oldOrderIds as $id) {
            $this->assertSame(4, (int)Order::find($id)->status, 'credited orders are offset');
        }
    }

    public function testReservationLimitIsCheckedBeforeAnOrderAndNeverAbortsAPaidOne()
    {
        [, $user] = $this->userOnYearlyPlan();
        $new = $this->makePlan();
        for ($i = 0; $i < OrderService::MAX_RESERVED_PLANS; $i++) {
            ReservedPlan::create([
                'user_id' => $user->id, 'order_id' => 1000 + $i, 'plan_id' => $new->id,
                'period' => 'month_price', 'status' => 0, 'created_at' => time(), 'updated_at' => time(),
            ]);
        }
        $this->assertTrue(OrderService::reservationLimitReached($user, 'month_price'));
        $this->assertFalse(OrderService::reservationLimitReached($user, 'reset_price'));

        // Already paid (e.g. an admin-assigned order): queued anyway, not stuck.
        $order = $this->makeOrder($user, ['plan_id' => $new->id, 'type' => 3]);
        (new OrderService($order))->paid('test');
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame(11, ReservedPlan::where('user_id', $user->id)->where('status', 0)->count());
    }

    public function testCancelRefundsTheWalletPartExactlyOnce()
    {
        $user = $this->makeUser(['balance' => 0]);
        $order = $this->makeOrder($user, ['balance_amount' => 40000, 'total_amount' => 60000]);

        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertTrue((new OrderService(Order::find($order->id)))->cancel(), 'second cancel is a no-op');
        // A stale copy still saying "pending" must not refund again either.
        $this->assertTrue((new OrderService($order->replicate()->setRawAttributes($order->getAttributes())))->cancel());

        $this->assertSame(2, (int)$order->fresh()->status);
        $this->assertSame(40000, (int)$user->fresh()->balance);
    }

    public function testAPaidOrderIsNotCancelled()
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['balance_amount' => 40000, 'status' => 3]);
        $stale = Order::find($order->id);
        $stale->status = 0; // what a copy loaded before the payment says

        $this->assertFalse((new OrderService($stale))->cancel());
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame(0, (int)$user->fresh()->balance);
    }

    public function testALatePaymentOnACancelledOrderWithAWalletPartIsCreditedNotOpened()
    {
        $plan = $this->makePlan();
        $user = $this->makeUser(['balance' => 50000]);
        $order = $this->makeOrder($user, [
            'plan_id' => $plan->id, 'balance_amount' => 50000, 'total_amount' => 50000,
        ]);
        // the wallet part was taken when the order was created
        User::where('id', $user->id)->update(['balance' => 0]);

        (new OrderService($order))->cancel();
        $this->assertSame(50000, (int)$user->fresh()->balance, 'cancel gave the wallet part back');

        $this->assertTrue((new OrderService(Order::find($order->id)))->paid('late'));

        $order->refresh();
        $user->refresh();
        $this->assertSame(4, (int)$order->status);
        $this->assertSame(100000, (int)$user->balance, 'wallet part + gateway part, nothing more');
        $this->assertNull($user->plan_id, 'plan not handed over for the gateway share alone');
    }

    public function testALatePaymentOnACancelledGatewayOnlyOrderStillOpensIt()
    {
        $plan = $this->makePlan();
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['plan_id' => $plan->id, 'status' => 2]);

        (new OrderService($order))->paid('late');

        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame($plan->id, (int)$user->fresh()->plan_id);
    }

    public function testAStaleCopyCannotReopenAnOpenedDeposit()
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['period' => 'deposit', 'type' => 9, 'total_amount' => 70000]);
        $stale = Order::find($order->id); // loaded while still pending

        (new OrderService(Order::find($order->id)))->paid('callback');
        $this->assertSame(70000, (int)$user->fresh()->balance);

        // the recovery job's copy, minutes old, says 0
        $this->assertSame(0, (int)$stale->status);
        $this->assertTrue((new OrderService($stale))->paid('recovery'));

        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame(70000, (int)$user->fresh()->balance, 'credited once');
    }
}
