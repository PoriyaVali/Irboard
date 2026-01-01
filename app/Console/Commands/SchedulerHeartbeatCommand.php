<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'system:heartbeat';
    protected $description = 'Scheduler heartbeat - confirms scheduler is running';

    public function handle(): int
    {
        Cache::put('schedule_last_run', time(), 86400);
        Log::info('✓ Scheduler heartbeat', ['timestamp' => now()->format('Y-m-d H:i:s')]);
        
        return self::SUCCESS;
    }
}
