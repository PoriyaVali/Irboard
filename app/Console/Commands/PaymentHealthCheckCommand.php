<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PaymentHealthCheckCommand extends Command
{
    protected $signature = 'payment:health-check';
    protected $description = 'Check payment recovery system health';

    public function handle(): int
    {
        $lastRun = Cache::get('payment_recovery_last_run');
        $lastSuccess = Cache::get('payment_recovery_last_success');

        if (!$lastRun || (time() - $lastRun) > 900) {
            Log::critical('🚨 Payment recovery not running!');
            $this->error('Payment recovery not running!');
        } elseif ($lastRun && (!$lastSuccess || (time() - $lastSuccess) > 3600)) {
            Log::warning('⚠️ Payment recovery running but not recovering');
            $this->warn('Payment recovery running but not recovering');
        } elseif (now()->minute === 0) {
            Log::info('✓ Payment health check - system healthy');
            $this->info('System healthy');
        }

        Cache::put('payment_health_check', time(), 3600);
        
        return self::SUCCESS;
    }
}
