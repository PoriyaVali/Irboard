<?php

namespace App\Console;

use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        
        // V2Board Core
        $schedule->command('traffic:update')->everyMinute()->withoutOverlapping();
        $schedule->command('v2board:statistics')->dailyAt('0:10');
        $schedule->command('check:order')->everyMinute()->withoutOverlapping();
        $schedule->command('check:commission')->everyFifteenMinutes();
        $schedule->command('check:ticket')->everyMinute();
        $schedule->command('reset:traffic')->daily();
        $schedule->command('reset:log')->daily();
        $schedule->command('send:remindMail')->dailyAt('11:30');
        $schedule->command('horizon:snapshot')->everyFiveMinutes();

        // System Maintenance
        $schedule->command('system:fix-logs-permissions')->everyMinute();
        $schedule->command('system:heartbeat')->everyFiveMinutes();

        // Auto Renewal
        $schedule->command('check:renewal')->everyTenMinutes()->withoutOverlapping(8)->runInBackground();
        $schedule->command('renewal:health-check')->everyThreeHours();
        $schedule->command('renewal:daily-report')->dailyAt('10:00');

        // Payment Recovery
        $schedule->command('payment:check-pending --refund-after=30 --check-interval=5 --expire-after=30 --max-inquiry-fails=3 --hours=6')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
        $schedule->command('payment:check-pending --check-cancelled --refund-after=0 --max-inquiry-fails=5 --hours=48')->hourly()->withoutOverlapping()->runInBackground();
        $schedule->command('payment:audit --hours=72')->dailyAt('09:00');
        $schedule->command('payment:expire-old-tracks')->dailyAt('02:00');
        $schedule->command('payment:cleanup-tracks --hours=48')->dailyAt('03:00')->withoutOverlapping()->runInBackground();
        $schedule->command('payment:health-check')->everyTenMinutes();

        // Price Sync
        $schedule->command('plan:sync-prices')->hourly()->withoutOverlapping()->runInBackground();
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}