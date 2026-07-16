<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RenewalHealthCheckCommand extends Command
{
    protected $signature = 'renewal:health-check';
    protected $description = 'Check auto renewal system health';

    public function handle(): int
    {
        $lastRun = Cache::get('renewal_last_run');
        $lastSuccess = Cache::get('renewal_last_success');

        if (!$lastRun || (time() - $lastRun) > 3600) {
            Log::critical('🚨 Auto renewal job not running!', [
                'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never',
            ]);
            $this->error('Auto renewal job not running!');
        }

        if ($lastRun && (!$lastSuccess || (time() - $lastSuccess) > 7200)) {
            Log::warning('⚠️ Auto renewal job running but not processing');
            $this->warn('Auto renewal job running but not processing');
        }

        Cache::put('renewal_health_check', time(), 86400);
        Cache::put('renewal_stats', [
            'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never',
            'last_success' => $lastSuccess ? date('Y-m-d H:i:s', $lastSuccess) : 'never',
            'health_status' => ($lastRun && $lastSuccess && (time() - $lastSuccess) < 7200) ? 'healthy' : 'degraded'
        ], 86400);

        Log::info('✓ Renewal health check completed');
        
        return self::SUCCESS;
    }
}
