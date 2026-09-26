<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RenewalDailyReportCommand extends Command
{
    protected $signature = 'renewal:daily-report';
    protected $description = 'Generate daily auto renewal summary report';

    /**
     * Yesterday's auto-renewal totals, counted by check:renewal as it renews.
     *
     * This used to query v2_commission_log for type = 'auto_renewal': that is the
     * referral commission table, it has no type column, so the report failed
     * every day with "Unknown column 'type'".
     */
    public function handle(): int
    {
        $day = date('Y-m-d', strtotime('-1 day'));
        $count = (int)Cache::get("renewal_daily:{$day}:count", 0);
        $revenue = (int)Cache::get("renewal_daily:{$day}:revenue", 0);

        Log::info('✓ Daily auto renewal summary', [
            'date' => $day,
            'total_renewals' => $count,
            'total_revenue' => number_format($revenue) . ' تومان',
        ]);
        $this->info("{$day} | Renewals: {$count} | Revenue: " . number_format($revenue) . " تومان");

        return self::SUCCESS;
    }
}
