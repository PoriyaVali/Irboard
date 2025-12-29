<?php

namespace App\Console;

use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected $commands = [];

    protected function schedule(Schedule $schedule)
    {
        // System Maintenance
        $schedule->call(function () {
            $directory = base_path('storage/logs');
            exec("sudo chown -R www:www " . escapeshellarg($directory));
            exec("sudo chmod -R 775 " . escapeshellarg($directory));
        })->everyMinute()->name('fix-logs-permissions')
          ->onFailure(fn() => Log::error('✗ Fix logs permissions failed'));

        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());

        // Scheduler Heartbeat
        $schedule->call(function () {
            Cache::put('schedule_last_run', time(), 86400);
            Log::info('✓ Scheduler heartbeat', ['timestamp' => now()->format('Y-m-d H:i:s')]);
        })->everyFiveMinutes()->name('scheduler-heartbeat');

        // V2Board Core Commands
        $schedule->command('traffic:update')
            ->everyMinute()->withoutOverlapping()->name('traffic-update')
            ->onFailure(fn() => Log::error('✗ Traffic update failed'));

        $schedule->command('check:order')
            ->everyMinute()->withoutOverlapping()->name('check-orders')
            ->onFailure(fn() => Log::error('✗ Check orders failed'));

        $schedule->command('check:ticket')
            ->everyMinute()->name('check-tickets')
            ->onFailure(fn() => Log::error('✗ Check tickets failed'));

        $schedule->command('check:commission')
            ->everyFifteenMinutes()->name('check-commissions')
            ->onSuccess(fn() => Log::info('✓ Check commissions completed'))
            ->onFailure(fn() => Log::error('✗ Check commissions failed'));

        // Statistics & Reports
        $schedule->command('v2board:statistics')
            ->dailyAt('0:10')->name('daily-statistics')
            ->onSuccess(fn() => Log::info('✓ Daily statistics completed', ['date' => now()->format('Y-m-d')]))
            ->onFailure(fn() => Log::error('✗ Daily statistics failed'));

        $schedule->command('horizon:snapshot')
            ->everyFiveMinutes()->name('horizon-snapshot')
            ->onFailure(fn() => Log::error('✗ Horizon snapshot failed'));

        // Daily Maintenance
        $schedule->command('reset:traffic')
            ->daily()->name('reset-traffic')
            ->onSuccess(fn() => Log::info('✓ Daily traffic reset completed'))
            ->onFailure(fn() => Log::error('✗ Daily traffic reset failed'));

        $schedule->command('reset:log')
            ->daily()->name('reset-logs')
            ->onSuccess(fn() => Log::info('✓ Daily log reset completed'))
            ->onFailure(fn() => Log::error('✗ Daily log reset failed'));

        $schedule->command('send:remindMail')
            ->dailyAt('11:30')->name('send-reminder-emails')
            ->onSuccess(fn() => Log::info('✓ Reminder emails sent'))
            ->onFailure(fn() => Log::error('✗ Send reminder emails failed'));

        // Auto Renewal System
        $schedule->command('check:renewal')
            ->everyTenMinutes()->withoutOverlapping(8)->runInBackground()->name('auto-renewal-check')
            ->onSuccess(function () {
                Cache::put('renewal_last_success', time(), 86400);
                Cache::put('renewal_last_run', time(), 86400);
                if (now()->minute === 0) {
                    Log::info('✓ Auto renewal check - hourly summary');
                }
            })
            ->onFailure(fn() => Log::error('✗ Auto renewal check failed!'));

        // Renewal Health Check (every 3 hours)
        $schedule->call(function () {
            $lastRun = Cache::get('renewal_last_run');
            $lastSuccess = Cache::get('renewal_last_success');

            if (!$lastRun || (time() - $lastRun) > 3600) {
                Log::critical('🚨 Auto renewal job not running!', [
                    'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never'
                ]);
            }

            if ($lastRun && (!$lastSuccess || (time() - $lastSuccess) > 7200)) {
                Log::warning('⚠️ Auto renewal job running but not processing');
            }

            $stats = [
                'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never',
                'last_success' => $lastSuccess ? date('Y-m-d H:i:s', $lastSuccess) : 'never',
                'health_status' => ($lastRun && $lastSuccess && (time() - $lastSuccess) < 7200) ? 'healthy' : 'degraded'
            ];
            Cache::put('renewal_health_check', time(), 86400);
            Cache::put('renewal_stats', $stats, 86400);
            Log::info('✓ Renewal health check completed', $stats);
        })->everyThreeHours()->name('renewal-health-check');

        // Renewal Daily Report
        $schedule->call(function () {
            if (!class_exists('\App\Models\CommissionLog')) {
                Log::warning('⚠️ CommissionLog model not found');
                return;
            }
            try {
                $renewals = \App\Models\CommissionLog::where('type', 'auto_renewal')
                    ->where('created_at', '>=', now()->startOfDay()->timestamp)
                    ->get();

                Log::info('✓ Daily auto renewal summary', [
                    'date' => now()->format('Y-m-d'),
                    'total_renewals' => $renewals->count(),
                    'total_revenue' => number_format($renewals->sum('order_amount')) . ' تومان'
                ]);
            } catch (\Exception $e) {
                Log::error('✗ Daily renewal summary failed', ['error' => $e->getMessage()]);
            }
        })->dailyAt('10:00')->name('renewal-daily-report');

        // Payment Recovery System
        $schedule->command('payment:check-pending --refund-after=30 --check-interval=5 --expire-after=30 --max-inquiry-fails=3 --hours=6')
            ->everyFiveMinutes()->withoutOverlapping(10)->runInBackground()->name('payment-recovery-fast')
            ->onSuccess(function () {
                Cache::put('payment_recovery_last_success', time(), 3600);
                Cache::put('payment_recovery_last_run', time(), 3600);
                Log::info('✓ Payment recovery (fast) completed');
            })
            ->onFailure(function () {
                Cache::put('payment_recovery_last_run', time(), 3600);
                Log::error('✗ Payment recovery (fast) failed');
            });

        $schedule->command('payment:check-pending --check-cancelled --refund-after=0 --max-inquiry-fails=5 --hours=48')
            ->hourly()->withoutOverlapping()->runInBackground()->name('payment-recovery-deep')
            ->onSuccess(fn() => Log::info('✓ Payment recovery (deep) completed'))
            ->onFailure(fn() => Log::error('✗ Payment recovery (deep) failed'));

        $schedule->command('payment:audit --hours=72')
            ->dailyAt('09:00')->name('payment-audit-daily')
            ->onSuccess(fn() => Log::info('✓ Payment audit completed'))
            ->onFailure(fn() => Log::error('✗ Payment audit failed'));

        // Expire old tracks
        $schedule->call(function () {
            try {
                $expired = \App\Models\PaymentTrack::expireOld(48);
                Log::info('✓ Old tracks expired', ['count' => $expired ?? 0]);
            } catch (\Exception $e) {
                Log::error('✗ Expire old tracks failed', ['error' => $e->getMessage()]);
            }
        })->dailyAt('02:00')->name('expire-old-tracks');

        $schedule->command('payment:cleanup-tracks --hours=48')
            ->dailyAt('03:00')->withoutOverlapping()->runInBackground()->name('payment-tracks-cleanup')
            ->onSuccess(fn() => Log::info('✓ Payment tracks cleanup completed'))
            ->onFailure(fn() => Log::error('✗ Payment tracks cleanup failed'));

        // Payment Health Check
        $schedule->call(function () {
            $lastRun = Cache::get('payment_recovery_last_run');
            $lastSuccess = Cache::get('payment_recovery_last_success');

            if (!$lastRun || (time() - $lastRun) > 900) {
                Log::critical('🚨 Payment recovery not running!');
            } elseif ($lastRun && (!$lastSuccess || (time() - $lastSuccess) > 3600)) {
                Log::warning('⚠️ Payment recovery running but not recovering');
            } elseif (now()->minute === 0) {
                Log::info('✓ Payment health check - system healthy');
            }

            Cache::put('payment_health_check', time(), 3600);
        })->everyTenMinutes()->name('payment-health-check');

        // Plan Price Sync (Exchange Rate)
        $schedule->command('plan:sync-prices')
            ->hourly()->withoutOverlapping()->runInBackground()->name('plan-price-sync')
            ->onSuccess(function () {
                Cache::put('plan_price_sync_last_run', time(), 86400);
                Log::info('✓ Plan prices synced with exchange rate');
            })
            ->onFailure(fn() => Log::error('✗ Plan price sync failed'));
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}