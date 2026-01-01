<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewalDailyReportCommand extends Command
{
    protected $signature = 'renewal:daily-report';
    protected $description = 'Generate daily auto renewal summary report';

    public function handle(): int
    {
        if (!class_exists('\App\Models\CommissionLog')) {
            Log::warning('⚠️ CommissionLog model not found');
            $this->warn('CommissionLog model not found');
            return self::FAILURE;
        }

        try {
            $renewals = \App\Models\CommissionLog::where('type', 'auto_renewal')
                ->where('created_at', '>=', now()->startOfDay())
                ->get();

            Log::info('✓ Daily auto renewal summary', [
                'date' => now()->format('Y-m-d'),
                'total_renewals' => $renewals->count(),
                'total_revenue' => number_format($renewals->sum('order_amount')) . ' تومان',
            ]);

            $this->info("Renewals: {$renewals->count()} | Revenue: " . number_format($renewals->sum('order_amount')) . " تومان");
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error('✗ Daily renewal summary failed', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
