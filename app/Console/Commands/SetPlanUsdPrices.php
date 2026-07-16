<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetPlanUsdPrices extends Command
{
    protected $signature = 'plan:set-usd';
    protected $description = 'تنظیم قیمت‌های دلاری پلن‌ها';

    public function handle(): int
    {
        $plans = Plan::all();

        foreach ($plans as $plan) {
            $this->info("\n📦 {$plan->name} (ID: {$plan->id})");
            
            $periods = [
                'month' => 'ماهانه',
                'quarter' => 'سه‌ماهه',
                'half_year' => 'شش‌ماهه',
                'year' => 'یک‌ساله',
                'two_year' => 'دوساله',
                'three_year' => 'سه‌ساله',
                'onetime' => 'یکبار',
                'reset' => 'ریست',
            ];

            $changes = [];

            foreach ($periods as $period => $label) {
                $usdField = "{$period}_price_usd";
                $currentUsd = $plan->$usdField;

                $newUsd = $this->ask(
                    "   {$label} (فعلی: \${$currentUsd}) - قیمت USD جدید (خالی=بدون تغییر, 0=حذف)",
                    $currentUsd
                );

                if ($newUsd !== $currentUsd && $newUsd !== null) {
                    $changes[$usdField] = $newUsd ?: null;
                }
            }

            if (!empty($changes)) {
                DB::table('v2_plan')->where('id', $plan->id)->update($changes);
                $this->line("   ✅ ذخیره شد");
            } else {
                $this->line("   ⏭ بدون تغییر");
            }
        }

        $this->newLine();
        $this->info('✅ تمام شد. حالا اجرا کنید: php artisan plan:update-prices --force');

        return 0;
    }
}
