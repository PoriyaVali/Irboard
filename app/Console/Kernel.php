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
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🔧 System Maintenance
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->call(function () {
            $directory = base_path('storage/logs');
            exec("sudo chown -R www:www " . escapeshellarg($directory));
            exec("sudo chmod -R 775 " . escapeshellarg($directory));
        })
            ->everyMinute()
            ->name('fix-logs-permissions')
            ->onSuccess(function () {
                // Silent - هر دقیقه لاگ نزنیم
            })
            ->onFailure(function () {
                Log::error('✗ Fix logs permissions failed');
            });

        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 📊 Scheduler Heartbeat (Monitoring)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->call(function () {
            Cache::put('schedule_last_run', time(), 86400);
            Log::info('✓ Scheduler heartbeat', [
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);
        })
            ->everyFiveMinutes()
            ->name('scheduler-heartbeat');

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🚀 V2Board Core Commands
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->command('traffic:update')
            ->everyMinute()
            ->withoutOverlapping()
            ->name('traffic-update')
            ->onSuccess(function () {
                // Silent - خیلی پر تکراره
            })
            ->onFailure(function () {
                Log::error('✗ Traffic update failed');
            });

        $schedule->command('check:order')
            ->everyMinute()
            ->withoutOverlapping()
            ->name('check-orders')
            ->onSuccess(function () {
                // Silent - خیلی پر تکراره
            })
            ->onFailure(function () {
                Log::error('✗ Check orders failed');
            });

        $schedule->command('check:ticket')
            ->everyMinute()
            ->name('check-tickets')
            ->onSuccess(function () {
                // Silent - خیلی پر تکراره
            })
            ->onFailure(function () {
                Log::error('✗ Check tickets failed');
            });

        $schedule->command('check:commission')
            ->everyFifteenMinutes()
            ->name('check-commissions')
            ->onSuccess(function () {
                Log::info('✓ Check commissions completed');
            })
            ->onFailure(function () {
                Log::error('✗ Check commissions failed');
            });

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 📊 Statistics & Reports
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->command('v2board:statistics')
            ->dailyAt('0:10')
            ->name('daily-statistics')
            ->onSuccess(function () {
                Log::info('✓ Daily statistics completed', [
                    'date' => now()->format('Y-m-d')
                ]);
            })
            ->onFailure(function () {
                Log::error('✗ Daily statistics failed');
            });

        $schedule->command('horizon:snapshot')
            ->everyFiveMinutes()
            ->name('horizon-snapshot')
            ->onSuccess(function () {
                // Silent - خیلی پر تکراره
            })
            ->onFailure(function () {
                Log::error('✗ Horizon snapshot failed');
            });

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🔄 Daily Maintenance Tasks
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->command('reset:traffic')
            ->daily()
            ->name('reset-traffic')
            ->onSuccess(function () {
                Log::info('✓ Daily traffic reset completed', [
                    'date' => now()->format('Y-m-d')
                ]);
            })
            ->onFailure(function () {
                Log::error('✗ Daily traffic reset failed');
            });

        $schedule->command('reset:log')
            ->daily()
            ->name('reset-logs')
            ->onSuccess(function () {
                Log::info('✓ Daily log reset completed', [
                    'date' => now()->format('Y-m-d')
                ]);
            })
            ->onFailure(function () {
                Log::error('✗ Daily log reset failed');
            });

        $schedule->command('send:remindMail')
            ->dailyAt('11:30')
            ->name('send-reminder-emails')
            ->onSuccess(function () {
                Log::info('✓ Reminder emails sent', [
                    'time' => now()->format('Y-m-d H:i:s')
                ]);
            })
            ->onFailure(function () {
                Log::error('✗ Send reminder emails failed');
            });

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🔄 Auto Renewal System v2.0 (Enhanced)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        /**
         * 🔄 Auto Renewal Check - Every 10 Minutes
         * 
         * Features:
         * - Checks subscription expiry (2 days before)
         * - Monitors traffic usage (95% threshold)
         * - Automatic balance deduction
         * - Email notifications (success/failure)
         * - Auto-disable on insufficient balance
         */
        $schedule->command('check:renewal')
            ->everyTenMinutes()
            ->withoutOverlapping(8)
            ->runInBackground()
            ->name('auto-renewal-check')
            ->onSuccess(function () {
                Cache::put('renewal_last_success', time(), 86400);
                Cache::put('renewal_last_run', time(), 86400);
                
                // لاگ فقط در ساعت‌های کامل
                if (now()->minute === 0) {
                    Log::info('✓ Auto renewal check - hourly summary', [
                        'time' => now()->format('Y-m-d H:i:s')
                    ]);
                }
            })
            ->onFailure(function () {
                Log::error('✗ Auto renewal check failed!', [
                    'time' => now()->format('Y-m-d H:i:s')
                ]);
            });

        /**
         * 🔍 Renewal Health Check - Every 3 Hours
         */
        $schedule->call(function () {
            Log::info('🔍 Starting renewal health check...');
            
            $lastRun = Cache::get('renewal_last_run');
            $lastSuccess = Cache::get('renewal_last_success');
            
            // Check if renewal job is running
            if (!$lastRun || (time() - $lastRun) > 3600) { // 1 hour
                Log::critical('🚨 Auto renewal job not running!', [
                    'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never',
                    'hours_ago' => $lastRun ? round((time() - $lastRun) / 3600, 1) : 'N/A',
                ]);
            }
            
            // Check if renewal job is running but never succeeding
            if ($lastRun && (!$lastSuccess || (time() - $lastSuccess) > 7200)) { // 2 hours
                Log::warning('⚠️ Auto renewal job running but not processing', [
                    'last_success' => $lastSuccess ? date('Y-m-d H:i:s', $lastSuccess) : 'never',
                    'hours_ago' => $lastSuccess ? round((time() - $lastSuccess) / 3600, 1) : 'N/A',
                ]);
            }
            
            // Update health check timestamp
            Cache::put('renewal_health_check', time(), 86400);
            
            // Get statistics
            $stats = [
                'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never',
                'last_success' => $lastSuccess ? date('Y-m-d H:i:s', $lastSuccess) : 'never',
                'health_status' => ($lastRun && $lastSuccess && (time() - $lastSuccess) < 7200) 
                    ? 'healthy' 
                    : 'degraded'
            ];
            
            Log::info('✓ Renewal health check completed', $stats);
            Cache::put('renewal_stats', $stats, 86400);
            
        })
            ->everyThreeHours()
            ->name('renewal-health-check');

        /**
         * 📧 Renewal Summary Report - Daily at 10:00
         */
        $schedule->call(function () {
            Log::info('📊 Starting daily renewal summary...');
            
            $startOfDay = now()->startOfDay()->timestamp;
            
            if (class_exists('\App\Models\CommissionLog')) {
                try {
                    $renewals = \App\Models\CommissionLog::where('type', 'auto_renewal')
                        ->where('created_at', '>=', $startOfDay)
                        ->get();
                    
                    $totalRevenue = $renewals->sum('order_amount');
                    $successCount = $renewals->count();
                    
                    Log::info('✓ Daily auto renewal summary completed', [
                        'date' => now()->format('Y-m-d'),
                        'total_renewals' => $successCount,
                        'total_revenue' => number_format($totalRevenue) . ' تومان',
                        'average_per_renewal' => $successCount > 0 
                            ? number_format($totalRevenue / $successCount, 0) . ' تومان'
                            : '0 تومان'
                    ]);
                } catch (\Exception $e) {
                    Log::error('✗ Daily renewal summary failed', [
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                Log::warning('⚠️ CommissionLog model not found - skipping renewal summary');
            }
            
        })
            ->dailyAt('10:00')
            ->name('renewal-daily-report');

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 💳 Payment Recovery System v2.1 (OPTIMIZED)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        /**
         * 🔥 Fast Recovery - Check pending payments every 5 minutes
         */
        $schedule->command('payment:check-pending --refund-after=30 --check-interval=5 --expire-after=30 --max-inquiry-fails=3 --hours=6')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->name('payment-recovery-fast')
            ->onSuccess(function () {
                Cache::put('payment_recovery_last_success', time(), 3600);
                Cache::put('payment_recovery_last_run', time(), 3600);
                Log::info('✓ Payment recovery (fast) completed', [
                    'time' => now()->format('Y-m-d H:i:s')
                ]);
            })
            ->onFailure(function () {
                Cache::put('payment_recovery_last_run', time(), 3600);
                Log::error('✗ Payment recovery (fast) failed', [
                    'time' => now()->format('Y-m-d H:i:s')
                ]);
            });

        /**
         * 🔍 Deep Recovery - Check cancelled orders every hour
         */
        $schedule->command('payment:check-pending --check-cancelled --refund-after=0 --max-inquiry-fails=5 --hours=48')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->name('payment-recovery-deep')
            ->onSuccess(function () {
                Log::info('✓ Payment recovery (deep) completed', [
                    'time' => now()->format('Y-m-d H:i:s')
                ]);
            })
            ->onFailure(function () {
                Log::error('✗ Payment recovery (deep) failed', [
                    'time' => now()->format('Y-m-d H:i:s')
                ]);
            });

        /**
         * 🔎 Daily Audit at 9 AM
         */
        $schedule->command('payment:audit --hours=72')
            ->dailyAt('09:00')
            ->name('payment-audit-daily')
            ->onSuccess(function () {
                Log::info('✓ Payment audit completed', [
                    'date' => now()->format('Y-m-d'),
                    'time' => '09:00'
                ]);
            })
            ->onFailure(function () {
                Log::error('✗ Payment audit failed', [
                    'date' => now()->format('Y-m-d')
                ]);
            });

        /**
         * ⏰ Expire old unused payment tracks at 2 AM
         */
        $schedule->call(function () {
            Log::info('⏰ Starting expire old tracks...');
            
            try {
                $expired = \App\Models\PaymentTrack::expireOld(48);
                
                Log::info('✓ Old tracks expired successfully', [
                    'expired_count' => $expired ?? 0,
                    'time' => now()->format('Y-m-d H:i:s')
                ]);
            } catch (\Exception $e) {
                Log::error('✗ Expire old tracks failed', [
                    'error' => $e->getMessage()
                ]);
            }
        })
            ->dailyAt('02:00')
            ->name('expire-old-tracks');

        /**
         * 🧹 Cleanup old payment tracks daily at 3 AM
         */
        $schedule->command('payment:cleanup-tracks --hours=48')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->name('payment-tracks-cleanup')
            ->onSuccess(function () {
                Log::info('✓ Payment tracks cleanup completed', [
                    'date' => now()->format('Y-m-d'),
                    'time' => '03:00'
                ]);
            })
            ->onFailure(function () {
                Log::error('✗ Payment tracks cleanup failed', [
                    'date' => now()->format('Y-m-d')
                ]);
            });
            
        /**
         * 📊 Payment Health Check - Every 10 minutes
         */
        $schedule->call(function () {
            $lastRun = Cache::get('payment_recovery_last_run');
            $lastSuccess = Cache::get('payment_recovery_last_success');
            
            // Check if job is running at all
            if (!$lastRun || (time() - $lastRun) > 900) { // 15 minutes
                Log::critical('🚨 Payment recovery not running!', [
                    'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never',
                    'minutes_ago' => $lastRun ? floor((time() - $lastRun) / 60) : 'N/A',
                ]);
            }
            
            // Check if job is running but never succeeding
            if ($lastRun && (!$lastSuccess || (time() - $lastSuccess) > 3600)) { // 1 hour
                Log::warning('⚠️ Payment recovery running but not recovering', [
                    'last_success' => $lastSuccess ? date('Y-m-d H:i:s', $lastSuccess) : 'never',
                    'minutes_ago' => $lastSuccess ? floor((time() - $lastSuccess) / 60) : 'N/A',
                ]);
            } else {
                // فقط در ساعت‌های کامل لاگ موفقیت
                if (now()->minute === 0) {
                    Log::info('✓ Payment health check - system healthy', [
                        'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never',
                        'last_success' => $lastSuccess ? date('Y-m-d H:i:s', $lastSuccess) : 'never'
                    ]);
                }
            }
            
            // Update health check timestamp
            Cache::put('payment_health_check', time(), 3600);
            
        })
            ->everyTenMinutes()
            ->name('payment-health-check');
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
