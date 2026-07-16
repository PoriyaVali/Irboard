<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\ExchangeRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdatePlanPrices extends Command
{
    protected $signature = 'plan:update-prices {--force : آپدیت حتی اگر نرخ تغییر نکرده}';
    protected $description = 'آپدیت قیمت پلن‌ها بر اساس نرخ دلار';

    // مپ فیلدهای USD به تومانی
    private array $priceFields = [
        'month_price_usd' => 'month_price',
        'quarter_price_usd' => 'quarter_price',
        'half_year_price_usd' => 'half_year_price',
        'year_price_usd' => 'year_price',
        'two_year_price_usd' => 'two_year_price',
        'three_year_price_usd' => 'three_year_price',
        'onetime_price_usd' => 'onetime_price',
        'reset_price_usd' => 'reset_price',
    ];

    public function handle(): int
    {
        $this->info('🔄 دریافت نرخ دلار...');
        
        $rate = ExchangeRateService::getUsdSellPriceFresh();
        
        if (!$rate) {
            $this->error('❌ خطا در دریافت نرخ دلار');
            return 1;
        }

        $this->info("💵 نرخ دلار: " . number_format($rate) . " تومان");
        $this->newLine();

        // بررسی آخرین نرخ
        $lastRate = Plan::whereNotNull('last_exchange_rate')->value('last_exchange_rate');

        if (!$this->option('force') && $lastRate == $rate) {
            $this->info('✅ نرخ تغییر نکرده');
            return 0;
        }

        // آپدیت همه پلن‌ها
        $plans = Plan::all();
        $updated = 0;

        foreach ($plans as $plan) {
            $changes = [];
            $details = [];

            foreach ($this->priceFields as $usdField => $irrField) {
                $usdPrice = (float) $plan->$usdField;
                
                if ($usdPrice > 0) {
                    // محاسبه: USD × نرخ ÷ 10 (واحد ده‌تومان)
                    $newPrice = (int) round($usdPrice * $rate / 10);
                    
                    if ($plan->$irrField != $newPrice) {
                        $changes[$irrField] = $newPrice;
                        $periodName = str_replace(['_price_usd', '_'], ['', ' '], $usdField);
                        $details[] = "{$periodName}: \${$usdPrice} → " . number_format($newPrice * 10);
                    }
                }
            }

            if (!empty($changes)) {
                $changes['last_exchange_rate'] = $rate;
                $changes['price_updated_at'] = now();
                
                DB::table('v2_plan')->where('id', $plan->id)->update($changes);
                $updated++;
                
                $this->info("✓ {$plan->name}");
                foreach ($details as $d) {
                    $this->line("    {$d}");
                }
            }
        }

        $this->newLine();
        $this->info("✅ {$updated} پلن آپدیت شد");
        
        Log::info('PlanPrices updated', ['rate' => $rate, 'updated' => $updated]);

        return 0;
    }
}
