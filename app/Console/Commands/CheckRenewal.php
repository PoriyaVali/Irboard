<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckRenewal extends Command
{
    protected $signature = 'check:renewal {--force : اجرای اجباری برای تست}';
    protected $description = 'بررسی و تمدید خودکار اشتراک کاربران (جلوگیری از هدر رفتن بسته)';

    // ✅ فقط 2 شرط برای جلوگیری از هدر رفتن
    const TRAFFIC_REMAINING_MB = 200;   // 200 مگابایت باقیمانده
    const HOURS_BEFORE_EXPIRY = 1;      // 1 ساعت قبل انقضا
    const DAYS_AFTER_EXPIRY = 7;        // 7 روز بعد انقضا (بازیابی)

    public function handle()
    {
        $this->info('🔄 شروع فرآیند بررسی تمدید خودکار...');
        $this->info('📅 زمان اجرا: ' . now()->format('Y-m-d H:i:s'));
        
        try {
            $users = $this->getUsersNeedingRenewal();
            
            if ($users->isEmpty()) {
                $this->info('✅ هیچ کاربری برای تمدید یافت نشد.');
                return Command::SUCCESS;
            }

            $this->info("📊 تعداد کاربران: {$users->count()}");
            $this->newLine();
            
            $stats = [
                'success' => 0,
                'failure' => 0,
                'skipped' => 0,
                'expired_recovered' => 0,
                'traffic_critical' => 0,
                'time_critical' => 0,
                'total_revenue' => 0
            ];

            $progressBar = $this->output->createProgressBar($users->count());
            $progressBar->start();

            foreach ($users as $user) {
                $result = $this->processUserRenewal($user);
                
                if (isset($result['status'])) {
                    $stats[$result['status']]++;
                    
                    if (isset($result['reason'])) {
                        $stats[$result['reason']]++;
                    }
                    
                    if (isset($result['revenue'])) {
                        $stats['total_revenue'] += $result['revenue'];
                    }
                }
                
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            $this->displayResults($stats);
            Log::info('CheckRenewal completed', $stats);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در فرآیند تمدید: {$e->getMessage()}");
            Log::error('CheckRenewal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return Command::FAILURE;
        }
    }

    /**
     * ✅ فقط 3 شرط برای تمدید:
     * 1. حجم باقیمانده <= 200 مگابایت
     * 2. زمان باقیمانده <= 1 ساعت
     * 3. اشتراک منقضی شده (تا 7 روز گذشته) - برای بازیابی
     */
    protected function getUsersNeedingRenewal()
    {
        $now = Carbon::now();
        $criticalTimeThreshold = $now->copy()->addHours(self::HOURS_BEFORE_EXPIRY); // 1 ساعت
        $recoveryThreshold = $now->copy()->subDays(self::DAYS_AFTER_EXPIRY); // 7 روز پیش
        $criticalTrafficBytes = self::TRAFFIC_REMAINING_MB * 1024 * 1024; // 200 MB

        return User::whereNotNull('plan_id')
            ->where('auto_renewal', 1)
            ->where(function ($query) use ($criticalTimeThreshold, $recoveryThreshold, $criticalTrafficBytes, $now) {
                
                // ✅ شرط 1: حجم باقیمانده کمتر از 200 مگابایت
                $query->whereRaw('(transfer_enable - (u + d)) <= ?', [$criticalTrafficBytes])
                
                // ✅ شرط 2: زمان باقیمانده کمتر از 1 ساعت (و هنوز منقضی نشده)
                ->orWhere(function ($subQuery) use ($criticalTimeThreshold, $now) {
                    $subQuery->where('expired_at', '>', $now->timestamp)
                             ->where('expired_at', '<=', $criticalTimeThreshold->timestamp);
                })
                
                // ✅ شرط 3: بازیابی اشتراک‌های منقضی شده (تا 7 روز گذشته)
                ->orWhere(function ($subQuery) use ($recoveryThreshold, $now) {
                    $subQuery->where('expired_at', '>=', $recoveryThreshold->timestamp)
                             ->where('expired_at', '<=', $now->timestamp);
                });
            })
            ->orderBy('expired_at', 'asc')
            ->get();
    }

    protected function processUserRenewal(User $user)
    {
        try {
            $plan = Plan::find($user->plan_id);
            
            if (!$plan) {
                $this->warn("⚠️ کاربر #{$user->id} پلن فعالی ندارد");
                return ['status' => 'skipped'];
            }

            $price = $this->getPlanPrice($plan, $user);
            
            if ($price === null || $price <= 0) {
                $this->error("⚠️ کاربر #{$user->id} - قیمت بسته نامعتبر است");
                Log::error('Invalid plan price', [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'plan_type' => $user->plan_type ?? 'null',
                    'month_price' => $plan->month_price,
                ]);
                return ['status' => 'skipped'];
            }

            $renewalInfo = $this->analyzeRenewalReason($user);

            $this->info("🔍 کاربر #{$user->id} ({$user->email})");
            $this->line("   دلیل: {$renewalInfo['reason_text']}");
            $this->line("   وضعیت: {$renewalInfo['status_text']}");
            $this->line("   قیمت بسته: " . number_format($price) . " تومان");
            $this->line("   موجودی فعلی: " . number_format($user->balance) . " تومان");

            if ($user->balance < $price) {
                $this->warn("   ❌ موجودی کافی نیست");
                $this->handleInsufficientBalance($user, $plan, $renewalInfo, $price);
                return ['status' => 'failure'];
            }

            DB::beginTransaction();
            
            try {
                $this->performRenewal($user, $plan, $renewalInfo, $price);
                DB::commit();
                
                $user->refresh();
                
                $this->info("   ✅ تمدید موفق");
                $this->info("   💰 مبلغ کسر شده: " . number_format($price) . " تومان");
                $this->info("   💳 موجودی جدید: " . number_format($user->balance) . " تومان");
                $this->info("   📊 حجم جدید: " . round($user->transfer_enable / (1024*1024*1024), 2) . " GB");
                
                return [
                    'status' => 'success',
                    'reason' => $renewalInfo['reason_type'],
                    'revenue' => $price
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->error("   ❌ خطا: {$e->getMessage()}");
            
            Log::error('User renewal error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return ['status' => 'failure'];
        }
    }

    protected function getPlanPrice(Plan $plan, User $user = null)
    {
        if ($user && isset($user->plan_type)) {
            $planType = $user->plan_type;
            
            $priceMap = [
                'month' => 'month_price',
                'quarter' => 'quarter_price',
                'half_year' => 'half_year_price',
                'year' => 'year_price',
                'two_year' => 'two_year_price',
                'three_year' => 'three_year_price',
                'onetime' => 'onetime_price',
                'reset' => 'reset_price'
            ];
            
            if (isset($priceMap[$planType]) && isset($plan->{$priceMap[$planType]})) {
                $price = $plan->{$priceMap[$planType]};
                if ($price !== null && $price > 0) {
                    return $price;
                }
            }
        }
        
        $priceFields = [
            'month_price',
            'quarter_price',
            'half_year_price',
            'year_price',
            'two_year_price',
            'three_year_price',
            'onetime_price',
            'reset_price'
        ];
        
        foreach ($priceFields as $field) {
            if (isset($plan->$field) && $plan->$field !== null && $plan->$field > 0) {
                return $plan->$field;
            }
        }
        
        return null;
    }

    /**
     * ✅ تحلیل دلیل تمدید (فقط 3 حالت)
     */
    protected function analyzeRenewalReason(User $user)
    {
        $now = Carbon::now();
        $expiredAt = Carbon::createFromTimestamp($user->expired_at);
        
        $totalUsed = $user->u + $user->d;
        $totalRemaining = max(0, $user->transfer_enable - $totalUsed);
        $usagePercent = $user->transfer_enable > 0 
            ? ($totalUsed / $user->transfer_enable) * 100 
            : 0;

        $hoursUntilExpiry = $now->diffInHours($expiredAt, false);
        $minutesUntilExpiry = $now->diffInMinutes($expiredAt, false);
        $daysUntilExpiry = $now->diffInDays($expiredAt, false);
        $isExpired = $expiredAt->isPast();
        
        // ✅ شرایط اصلی
        $remainingMB = round($totalRemaining / (1024 * 1024), 0);
        $isTrafficCritical = $totalRemaining <= (self::TRAFFIC_REMAINING_MB * 1024 * 1024);
        $isTimeCritical = !$isExpired && $minutesUntilExpiry <= (self::HOURS_BEFORE_EXPIRY * 60) && $minutesUntilExpiry > 0;

        $reasonType = null;
        $reasonText = '';
        $statusText = '';

        // اولویت‌بندی دلایل
        if ($isExpired) {
            $daysExpired = abs($daysUntilExpiry);
            $reasonType = 'expired_recovered';
            $statusText = "⏰ منقضی شده ({$daysExpired} روز پیش)";
            $reasonText = "بازیابی اشتراک منقضی شده";
        } 
        // ✅ وضعیت بحرانی زمانی (1 ساعت مانده)
        elseif ($isTimeCritical) {
            $reasonType = 'time_critical';
            if ($minutesUntilExpiry >= 60) {
                $hoursLeft = floor($minutesUntilExpiry / 60);
                $minsLeft = $minutesUntilExpiry % 60;
                $reasonText = "فقط {$hoursLeft} ساعت و {$minsLeft} دقیقه تا انقضا";
            } else {
                $reasonText = "فقط {$minutesUntilExpiry} دقیقه تا انقضا";
            }
            $statusText = "🚨 وضعیت بحرانی زمانی";
        }
        // ✅ وضعیت بحرانی حجمی (200 MB مانده)
        elseif ($isTrafficCritical) {
            $reasonType = 'traffic_critical';
            $reasonText = "فقط {$remainingMB} MB حجم باقی‌مانده";
            $statusText = "🚨 وضعیت بحرانی حجم";
        }
        // این شرط نباید اتفاق بیفته چون query فقط کاربرانی رو برمی‌گردونه که یکی از 3 شرط بالا رو دارن
        else {
            $reasonType = 'unknown';
            $reasonText = "دلیل نامشخص";
            $statusText = "❓ وضعیت نامشخص";
        }

        return [
            'reason_type' => $reasonType,
            'reason_text' => $reasonText,
            'status_text' => $statusText,
            'is_expired' => $isExpired,
            'is_traffic_critical' => $isTrafficCritical,
            'is_time_critical' => $isTimeCritical,
            'days_until_expiry' => $daysUntilExpiry,
            'hours_until_expiry' => $hoursUntilExpiry,
            'minutes_until_expiry' => $minutesUntilExpiry,
            'usage_percent' => $usagePercent,
            'used_gb' => round($totalUsed / (1024 * 1024 * 1024), 2),
            'total_gb' => round($user->transfer_enable / (1024 * 1024 * 1024), 2),
            'remaining_mb' => $remainingMB
        ];
    }

    protected function performRenewal(User $user, Plan $plan, array $renewalInfo, $price)
    {
        $oldExpiredAt = $user->expired_at;
        $oldTransferEnable = $user->transfer_enable;
        $oldU = $user->u;
        $oldD = $user->d;
        $oldBalance = $user->balance;

        $user->balance -= $price;
        $newExpiredAt = $this->calculateNewExpiry($user, $plan, $renewalInfo['is_expired']);
        $user->expired_at = $newExpiredAt;
        $user->u = 0;
        $user->d = 0;
        $user->transfer_enable = $this->convertTransferEnable($plan->transfer_enable);
        $user->save();

        $this->logRenewal($user, $plan, $renewalInfo, [
            'old_expired_at' => $oldExpiredAt,
            'new_expired_at' => $newExpiredAt,
            'old_transfer' => $oldTransferEnable,
            'old_used' => $oldU + $oldD,
            'old_balance' => $oldBalance,
            'price' => $price
        ]);

        $this->sendSuccessEmail($user, $plan, $renewalInfo, $price);
    }

    protected function convertTransferEnable($value)
    {
        if ($value < 1000) {
            return $value * 1024 * 1024 * 1024;
        }
        return $value;
    }

    protected function calculateNewExpiry(User $user, Plan $plan, bool $isExpired)
    {
        $now = Carbon::now();
        $currentExpiry = Carbon::createFromTimestamp($user->expired_at);
        $duration = $plan->duration ?? 30;

        if ($isExpired) {
            return $now->addDays($duration)->timestamp;
        } else {
            return $currentExpiry->addDays($duration)->timestamp;
        }
    }

    protected function logRenewal(User $user, Plan $plan, array $renewalInfo, array $details)
    {
        Log::info('Auto renewal successful', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price' => $details['price'],
            'reason' => $renewalInfo['reason_text'],
            'reason_type' => $renewalInfo['reason_type'],
            'was_expired' => $renewalInfo['is_expired'],
            'was_traffic_critical' => $renewalInfo['is_traffic_critical'] ?? false,
            'was_time_critical' => $renewalInfo['is_time_critical'] ?? false,
            'old_expired_at' => date('Y-m-d H:i:s', $details['old_expired_at']),
            'new_expired_at' => date('Y-m-d H:i:s', $details['new_expired_at']),
            'old_transfer_gb' => round($details['old_transfer'] / (1024 * 1024 * 1024), 2),
            'old_used_gb' => round($details['old_used'] / (1024 * 1024 * 1024), 2),
            'new_transfer_gb' => round($user->transfer_enable / (1024 * 1024 * 1024), 2),
            'usage_percent' => round($renewalInfo['usage_percent'], 1),
            'remaining_mb' => $renewalInfo['remaining_mb'] ?? 0,
            'minutes_until_expiry' => $renewalInfo['minutes_until_expiry'] ?? 0,
            'old_balance' => $details['old_balance'],
            'new_balance' => $user->balance,
            'deducted_amount' => $details['price'],
            'timestamp' => Carbon::now()->toDateTimeString()
        ]);

        $this->recordCommissionLog($user, $plan, $details['price']);
    }

    protected function recordCommissionLog(User $user, Plan $plan, $price)
    {
        if (!class_exists('\App\Models\CommissionLog')) {
            return;
        }

        try {
            $data = [
                'user_id' => $user->id,
                'trade_no' => Helper::guid(),
                'amount' => $price,
                'order_amount' => $price,
                'get_amount' => 0,
                'type' => 'auto_renewal',
                'created_at' => time(),
                'updated_at' => time()
            ];

            if (isset($user->invite_user_id) && $user->invite_user_id !== null) {
                $data['invite_user_id'] = $user->invite_user_id;
            } else {
                $data['invite_user_id'] = 0;
            }

            if (isset($plan->id)) {
                $data['plan_id'] = $plan->id;
            }

            \App\Models\CommissionLog::create($data);

        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('Failed to create commission log entry', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'sql_code' => $e->getCode()
            ]);
        } catch (\Exception $e) {
            Log::warning('Unexpected error creating commission log', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function sendSuccessEmail(User $user, Plan $plan, array $renewalInfo, $price)
    {
        try {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => config('v2board.app_name', '') . ' - تمدید خودکار انجام شد',
                'template_name' => 'auto_renewal_success',
                'template_value' => [
                    'name' => $user->email,
                    'plan_name' => $plan->name,
                    'price' => number_format($price),
                    'balance' => number_format($user->balance),
                    'expired_at' => date('Y-m-d H:i:s', $user->expired_at),
                    'reason' => $renewalInfo['reason_text'],
                    'was_expired' => $renewalInfo['is_expired'],
                    'used_gb' => $renewalInfo['used_gb'],
                    'total_gb' => $renewalInfo['total_gb'],
                    'usage_percent' => round($renewalInfo['usage_percent'], 1),
                    'app_name' => config('v2board.app_name', ''),
                    'app_url' => config('v2board.app_url', '')
                ]
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send renewal success email', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function handleInsufficientBalance(User $user, Plan $plan, array $renewalInfo, $price)
    {
        $user->auto_renewal = 0;
        $user->save();

        Log::warning('Auto renewal failed - insufficient balance', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'plan_id' => $plan->id,
            'plan_price' => $price,
            'user_balance' => $user->balance,
            'needed' => $price - $user->balance,
            'reason' => $renewalInfo['reason_text'],
            'was_expired' => $renewalInfo['is_expired']
        ]);

        $this->sendInsufficientBalanceEmail($user, $plan, $renewalInfo, $price);
    }

    protected function sendInsufficientBalanceEmail(User $user, Plan $plan, array $renewalInfo, $price)
    {
        try {
            $needed = $price - $user->balance;

            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => config('v2board.app_name', '') . ' - هشدار: تمدید خودکار انجام نشد',
                'template_name' => 'auto_renewal_failed',
                'template_value' => [
                    'name' => $user->email,
                    'plan_name' => $plan->name,
                    'price' => number_format($price),
                    'balance' => number_format($user->balance),
                    'needed' => number_format($needed),
                    'reason' => $renewalInfo['reason_text'],
                    'was_expired' => $renewalInfo['is_expired'],
                    'used_gb' => $renewalInfo['used_gb'],
                    'total_gb' => $renewalInfo['total_gb'],
                    'usage_percent' => round($renewalInfo['usage_percent'], 1),
                    'expired_at' => date('Y-m-d H:i:s', $user->expired_at),
                    'days_left' => max(0, $renewalInfo['days_until_expiry']),
                    'app_name' => config('v2board.app_name', ''),
                    'app_url' => config('v2board.app_url', '')
                ]
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send insufficient balance email', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function displayResults(array $stats)
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 خلاصه نتایج تمدید خودکار');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        $this->line('');
        $this->info("✅ موفق: {$stats['success']}");
        $this->error("❌ ناموفق: {$stats['failure']}");
        $this->comment("⏭️  رد شده: {$stats['skipped']}");
        
        $this->line('');
        $this->info('📋 تفکیک بر اساس دلیل:');
        $this->line("   🔄 بازیابی اشتراک منقضی: {$stats['expired_recovered']}");
        $this->line("   🚨 وضعیت بحرانی زمانی (≤1 ساعت): {$stats['time_critical']}");
        $this->line("   🚨 وضعیت بحرانی حجم (≤200 MB): {$stats['traffic_critical']}");
        
        $this->line('');
        $this->info("💰 درآمد کل: " . number_format($stats['total_revenue']) . " تومان");
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}