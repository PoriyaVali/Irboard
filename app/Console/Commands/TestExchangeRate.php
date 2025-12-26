<?php

namespace App\Console\Commands;

use App\Services\ExchangeService;
use Illuminate\Console\Command;

class TestExchangeRate extends Command
{
    protected $signature = 'exchange:test {--clear} {--all}';
    protected $description = 'تست سیستم نرخ ارز';

    public function handle()
    {
        $this->info('═══════════════════════════════════════════════════');
        $this->info('🧪 تست سیستم نرخ ارز');
        $this->info('═══════════════════════════════════════════════════');
        
        if ($this->option('clear')) {
            ExchangeService::clearCache();
            $this->warn('✓ کش پاک شد');
        }
        
        if ($this->option('all')) {
            $this->info("\n🔍 تست تمام منابع:");
            $results = ExchangeService::testAll();
            
            foreach ($results as $source => $result) {
                $status = $result['success'] && ($result['valid'] ?? false) ? '✓' : '✗';
                $rate = isset($result['rate']) ? number_format($result['rate']) : 'N/A';
                $time = $result['time_ms'] ?? 0;
                
                $this->line(sprintf(
                    "  %s %-12s %s تومان (%dms)",
                    $status,
                    $source . ':',
                    str_pad($rate, 10, ' ', STR_PAD_LEFT),
                    $time
                ));
                
                if (isset($result['error'])) {
                    $this->error("     Error: " . $result['error']);
                }
            }
        }
        
        $this->info("\n📊 دریافت نرخ نهایی:");
        
        $rate = ExchangeService::getCurrentRate();
        $data = ExchangeService::getRateData();
        
        $this->info("  نرخ: " . number_format($rate) . " تومان");
        $this->info("  زمان: " . $data['date']);
        
        if ($rate == config('exchange.fallback_rate', 107000)) {
            $this->warn("\n⚠️  از fallback استفاده شد!");
        }
        
        $this->info("\n═══════════════════════════════════════════════════");
        
        return 0;
    }
}