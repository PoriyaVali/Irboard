<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\ExchangeRateService;

class SyncPlanPrices extends Command
{
    protected $signature = 'plan:sync-prices 
                            {--force : بروزرسانی همه حتی اگر نرخ تغییر نکرده}
                            {--reverse : محاسبه قیمت دلاری از تومانی}';
    
    protected $description = 'بروزرسانی قیمت پلن‌ها بر اساس نرخ دلار';

    public function handle()
    {
        $this->info('شروع بروزرسانی قیمت‌ها...');

        $rate = ExchangeRateService::getUsdSellPriceFresh();
        if (!$rate) {
            $this->error('خطا در دریافت نرخ دلار');
            return 1;
        }
        $this->info("نرخ دلار: {$rate} تومان");

        if ($this->option('reverse')) {
            $this->reverseSync($rate);
        } else {
            $this->normalSync($rate);
        }

        $this->info('پایان');
        return 0;
    }

    /**
     * گرد کردن به پایین به نزدیک‌ترین هزار تومان
     * مثال: 109,990 → 109,000
     */
    private function roundDownToThousand(int $price): int
    {
        return (int) floor($price / 1000) * 1000;
    }

    private function normalSync(int $rate)
    {
        $prices = DB::table('v2_plan_prices')->get();
        $updated = 0;

        foreach ($prices as $price) {
            if (!$this->option('force') && $price->last_exchange_rate == $rate) {
                continue;
            }

            $planUpdate = [];
            $fields = [
                'month_price_usd' => 'month_price',
                'quarter_price_usd' => 'quarter_price',
                'half_year_price_usd' => 'half_year_price',
                'year_price_usd' => 'year_price',
                'two_year_price_usd' => 'two_year_price',
                'three_year_price_usd' => 'three_year_price',
                'onetime_price_usd' => 'onetime_price',
                'reset_price_usd' => 'reset_price',
            ];

            foreach ($fields as $usdField => $tomanField) {
                if ($price->$usdField !== null) {
                    // فرمول: USD × نرخ + گرد کردن به پایین
                    $rawPrice = $price->$usdField * $rate;
                    $planUpdate[$tomanField] = $this->roundDownToThousand((int) $rawPrice);
                }
            }

            if (!empty($planUpdate)) {
                DB::table('v2_plan')->where('id', $price->plan_id)->update($planUpdate);
                DB::table('v2_plan_prices')->where('plan_id', $price->plan_id)->update([
                    'last_exchange_rate' => $rate,
                    'price_updated_at' => now(),
                    'updated_at' => now()
                ]);
                $updated++;
                $this->line("  + پلن #{$price->plan_id} بروزرسانی شد");
            }
        }

        $this->info("تعداد {$updated} پلن بروزرسانی شد (دلار → تومان)");
    }

    private function reverseSync(int $rate)
    {
        $plans = DB::table('v2_plan')->get();
        $updated = 0;

        foreach ($plans as $plan) {
            $priceUpdate = [];
            $fields = [
                'month_price' => 'month_price_usd',
                'quarter_price' => 'quarter_price_usd',
                'half_year_price' => 'half_year_price_usd',
                'year_price' => 'year_price_usd',
                'two_year_price' => 'two_year_price_usd',
                'three_year_price' => 'three_year_price_usd',
                'onetime_price' => 'onetime_price_usd',
                'reset_price' => 'reset_price_usd',
            ];

            foreach ($fields as $tomanField => $usdField) {
                if ($plan->$tomanField !== null && $plan->$tomanField > 0) {
                    // فرمول دقیق: تومان ÷ نرخ (بدون گرد کردن)
                    $priceUpdate[$usdField] = round($plan->$tomanField / $rate, 2);
                }
            }

            if (!empty($priceUpdate)) {
                $priceUpdate['last_exchange_rate'] = $rate;
                $priceUpdate['price_updated_at'] = now();
                $priceUpdate['updated_at'] = now();

                DB::table('v2_plan_prices')->where('plan_id', $plan->id)->update($priceUpdate);
                $updated++;
                $this->line("  + پلن #{$plan->id} ({$plan->name}) محاسبه شد");
            }
        }

        $this->info("تعداد {$updated} پلن محاسبه شد (تومان → دلار)");
    }
}
