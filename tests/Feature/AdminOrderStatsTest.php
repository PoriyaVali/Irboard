<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\StatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * The admin's daily statistics (stat/getOrder): what the web admin's order
 * chart and the app's dashboard chart are drawn from.
 */
class AdminOrderStatsTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createInstallTables(['v2_stat']);
    }

    private function day(int $recordAt, array $values): void
    {
        DB::table('v2_stat')->insert(array_merge([
            'record_at' => $recordAt,
            'record_type' => 'd',
            'order_count' => 0,
            'order_total' => 0,
            'register_count' => 0,
            'invite_count' => 0,
            'transfer_used_total' => '0',
            'paid_count' => 0,
            'paid_total' => 0,
            'commission_count' => 0,
            'commission_total' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $values));
    }

    private function fetch(): array
    {
        return (new StatController())->getOrder(new Request())['data'];
    }

    /** Amounts are kept in toman here, as the dashboard shows them. */
    public function testAmountsAreAsStoredNotAHundredthOfThem()
    {
        $day = strtotime('2026-11-24');
        $this->day($day, ['paid_total' => 1250000, 'commission_total' => 75000, 'paid_count' => 4, 'register_count' => 7]);

        $byKey = [];
        foreach ($this->fetch() as $entry) {
            $byKey[$entry['key']] = $entry;
        }
        $this->assertSame(1250000, $byKey['paid_total']['value']);
        $this->assertSame(75000, $byKey['commission_total']['value']);
        $this->assertSame(4, $byKey['paid_count']['value']);
        $this->assertSame(7, $byKey['register_count']['value']);
        $this->assertSame($day, $byKey['paid_total']['record_at']);
    }

    /** The web admin's chart still finds its labels, dates and order. */
    public function testTheWebChartShapeIsKept()
    {
        $this->day(strtotime('2026-11-23'), ['register_count' => 1]);
        $this->day(strtotime('2026-11-24'), ['register_count' => 2]);
        $data = $this->fetch();

        $this->assertCount(10, $data);
        foreach ($data as $entry) {
            $this->assertArrayHasKey('type', $entry);
            $this->assertMatchesRegularExpression('/^\d{2}-\d{2}$/', $entry['date']);
        }
        // Oldest day first, and each day's entries reversed, as before.
        $this->assertSame('11-23', $data[0]['date']);
        $this->assertSame('commission_count', $data[0]['key']);
        $this->assertSame('register_count', $data[4]['key']);
        $this->assertSame(1, $data[4]['value']);
        $this->assertSame('11-24', $data[9]['date']);
        $this->assertSame(2, $data[9]['value']);
    }
}
