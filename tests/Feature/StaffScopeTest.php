<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * A staff member (a reseller) reaches its own customers - the users it
 * invited - and nobody else: not another reseller's customers, not the
 * panel's other users, not the notices every user sees.
 */
class StaffScopeTest extends TestCase
{
    use PanelFixtures;

    private User $reseller;
    private User $mine;
    private User $theirs;
    private User $nobodys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        // The shared fixture has no notices table; the notice routes need one.
        $this->createInstallTables(['v2_notice']);
        $this->reseller = $this->makeUser(['is_staff' => 1]);
        $other = $this->makeUser(['is_staff' => 1]);
        $this->mine = $this->makeUser(['invite_user_id' => $this->reseller->id]);
        $this->theirs = $this->makeUser(['invite_user_id' => $other->id]);
        $this->nobodys = $this->makeUser();
    }

    private function as(): array
    {
        return ['authorization' => (new AuthService($this->reseller))->generateAuthData(Request::create('/', 'POST'))['auth_data']];
    }

    private function ticketFor(User $user): int
    {
        return DB::table('v2_ticket')->insertGetId([
            'user_id' => $user->id, 'subject' => 'help', 'level' => 1, 'status' => 0, 'reply_status' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    public function testBanReachesOnlyTheResellersOwnCustomers()
    {
        // It used to fail on a filter() the staff controller never had.
        $this->withHeaders($this->as())->postJson('/api/v1/staff/user/ban', [])->assertStatus(200);
        $this->assertSame(1, (int)$this->mine->fresh()->banned);
        $this->assertSame(0, (int)$this->theirs->fresh()->banned);
        $this->assertSame(0, (int)$this->nobodys->fresh()->banned);
        $this->assertSame(0, (int)$this->reseller->fresh()->banned);

        // A filter naming someone else's customer matches nobody.
        $this->withHeaders($this->as())->postJson('/api/v1/staff/user/ban', [
            'filter' => [['key' => 'email', 'condition' => '=', 'value' => $this->theirs->email]],
        ])->assertStatus(200);
        $this->assertSame(0, (int)$this->theirs->fresh()->banned);
    }

    public function testMailReachesOnlyTheResellersOwnCustomers()
    {
        Queue::fake();
        $this->withHeaders($this->as())->postJson('/api/v1/staff/user/sendMail', [
            'subject' => 'News', 'content' => 'Hello',
        ])->assertStatus(200);
        Queue::assertPushed(SendEmailJob::class, 1);
        Queue::assertPushed(SendEmailJob::class, function ($job) {
            $params = (fn () => $this->params)->call($job);
            return ($params['email'] ?? null) === $this->mine->email;
        });
    }

    public function testTicketsAreOnlyThoseOfTheResellersOwnCustomers()
    {
        $ownTicket = $this->ticketFor($this->mine);
        $otherTicket = $this->ticketFor($this->theirs);
        $this->ticketFor($this->nobodys);

        $list = $this->withHeaders($this->as())->getJson('/api/v1/staff/ticket/fetch')->assertStatus(200)->json('data');
        $this->assertSame([$ownTicket], array_column($list, 'id'));

        $this->withHeaders($this->as())->getJson('/api/v1/staff/ticket/fetch?id=' . $otherTicket)->assertStatus(500);
        $this->withHeaders($this->as())->postJson('/api/v1/staff/ticket/reply', ['id' => $otherTicket, 'message' => 'x'])
            ->assertStatus(500);
        $this->withHeaders($this->as())->postJson('/api/v1/staff/ticket/close', ['id' => $otherTicket])->assertStatus(500);
        $this->assertSame(0, (int)DB::table('v2_ticket')->where('id', $otherTicket)->value('status'));
        $this->assertSame(0, DB::table('v2_ticket_message')->where('ticket_id', $otherTicket)->count());

        $this->withHeaders($this->as())->postJson('/api/v1/staff/ticket/close', ['id' => $ownTicket])->assertStatus(200);
        $this->assertSame(1, (int)DB::table('v2_ticket')->where('id', $ownTicket)->value('status'));
    }

    public function testNoticesAreReadOnlyForStaff()
    {
        $this->withHeaders($this->as())->getJson('/api/v1/staff/notice/fetch')->assertStatus(200);
        foreach (['save', 'update', 'drop'] as $action) {
            $this->withHeaders($this->as())->postJson('/api/v1/staff/notice/' . $action, ['title' => 'x', 'content' => 'y'])
                ->assertStatus(404);
        }
        $this->assertSame(0, DB::table('v2_notice')->count());
    }
}
