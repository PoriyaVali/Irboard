<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeService
{
    public static function getCurrentRate(): int
    {
        $cached = Cache::get('exchange_rate');
        
        if ($cached && is_array($cached)) {
            $age = time() - $cached['time'];
            if ($age < 3600) {
                return $cached['rate'];
            }
        }
        
        $rate = self::fetchRate();
        
        if ($rate) {
            Cache::put('exchange_rate', [
                'rate' => $rate,
                'time' => time(),
                'date' => date('Y-m-d H:i:s')
            ], 3600);
            
            Log::info('✓ Rate updated', ['rate' => $rate]);
            return $rate;
        }
        
        if ($cached && isset($cached['rate'])) {
            Log::warning('Using old cache', ['rate' => $cached['rate']]);
            return $cached['rate'];
        }
        
        $fallback = config('exchange.fallback_rate', 107000);
        Log::warning('Using fallback', ['rate' => $fallback]);
        return $fallback;
    }
    
    private static function fetchRate(): ?int
    {
        $sources = [
            'fetchFromAlanchand',
            'fetchFromExchangeRateAPI',
        ];
        
        foreach ($sources as $method) {
            try {
                $rate = self::$method();
                if (self::isValidRate($rate)) {
                    Log::info("✓ {$method}", ['rate' => $rate]);
                    return $rate;
                }
            } catch (\Exception $e) {
                Log::debug("✗ {$method}", ['error' => $e->getMessage()]);
            }
        }
        
        return null;
    }
    
    private static function isValidRate(?int $rate): bool
    {
        return $rate && $rate >= 80000 && $rate <= 200000;
    }
    
    /**
     * تبدیل اعداد فارسی به انگلیسی
     */
    private static function persianToEnglish(string $string): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($persian, $english, $string);
    }
    
    /**
     * Scraping: Alanchand
     */
/**
 * Scraping: Alanchand
 */
	private static function fetchFromAlanchand(): ?int
	{
		$html = Http::withoutVerifying()
			->timeout(15)
			->withHeaders([
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
				'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'fa-IR,fa;q=0.9',
			])
			->get('https://alanchand.com/')
			->body();
    
		if (strlen($html) < 1000) {
			return null;
		}
    
		// تبدیل اعداد فارسی به انگلیسی
		$html = self::persianToEnglish($html);
    
		// Pattern 1: جستجوی مستقیم sellPrice در ردیف دلار آمریکا
		if (preg_match('/<tr[^>]*title="قیمت دلار آمریکا"[^>]*>.*?<td[^>]*class="[^"]*sellPrice[^"]*"[^>]*>([^<]+)<\/td>/su', $html, $match)) {
			$priceText = strip_tags($match[1]);
			$priceText = str_replace(['<span', '</span>'], '', $priceText); // حذف span ها
			$price = (int) str_replace([',', '،', ' '], '', $priceText);
        
			if (self::isValidRate($price)) {
				Log::debug('Alanchand sellPrice (Pattern 1)', [
					'raw' => $match[1],
					'cleaned' => $priceText,
					'price' => $price
				]);
				return $price;
			}
		}
    
		// Pattern 2: جستجوی دقیق‌تر - پیدا کردن buyPrice و بعدش sellPrice
		if (preg_match('/<tr[^>]*title="قیمت دلار آمریکا"[^>]*>.*?buyPrice[^>]*>([^<]+)<\/td>.*?sellPrice[^>]*>([^<]+)<\/td>/su', $html, $match)) {
			$buyPriceText = strip_tags($match[1]);
			$sellPriceText = strip_tags($match[2]);
        
			$buyPrice = (int) str_replace([',', '،', ' '], '', $buyPriceText);
			$sellPrice = (int) str_replace([',', '،', ' '], '', $sellPriceText);
        
			Log::debug('Alanchand prices (Pattern 2)', [
				'buy' => $buyPrice,
				'sell' => $sellPrice
			]);
        
			// استفاده از قیمت فروش (sellPrice)
			if (self::isValidRate($sellPrice)) {
				Log::info('Using SELL price', ['price' => $sellPrice]);
				return $sellPrice;
			}
		}
    
		// Pattern 3: اگر Pattern های بالا کار نکردن، از اعداد موجود استفاده کن
		// ولی از دومین عدد استفاده کن (اولی buyPrice است، دومی sellPrice)
		preg_match_all('/(\d{2,3})[,،](\d{3})/u', $html, $matches, PREG_SET_ORDER);
    
		$candidates = [];
		foreach ($matches as $match) {
			$price = (int) str_replace([',', '،'], '', $match[0]);
        
			if (self::isValidRate($price)) {
				$candidates[] = $price;
			}
		}
    
		// اگر 2 عدد در بازه معتبر داریم، دومی رو برمی‌گردونیم (sellPrice)
		if (count($candidates) >= 2) {
			$sellPrice = $candidates[1]; // دومین عدد = sellPrice
			Log::debug('Alanchand using second candidate', [
				'all' => $candidates,
				'selected' => $sellPrice
			]);
			return $sellPrice;
		}
    
		// اگر فقط یک عدد داریم، همون رو برمی‌گردونیم
		if (count($candidates) === 1) {
			Log::warning('Only one price found, using it', ['price' => $candidates[0]]);
			return $candidates[0];
		}
		
		return null;
	}
    
    /**
     * API: ExchangeRate
     */
    private static function fetchFromExchangeRateAPI(): ?int
    {
        $response = Http::timeout(10)->get('https://open.er-api.com/v6/latest/USD');
        
        if (!$response->successful()) {
            return null;
        }
        
        $data = $response->json();
        
        if (isset($data['rates']['IRR'])) {
            $irrRate = (int) $data['rates']['IRR'];
            $tomanRate = (int) round($irrRate / 10);
            
            Log::debug('ExchangeRate-API', [
                'irr' => $irrRate,
                'toman' => $tomanRate
            ]);
            
            return $tomanRate;
        }
        
        return null;
    }
    
    public static function getRateData(): array
    {
        $cached = Cache::get('exchange_rate');
        return [
            'rate' => $cached['rate'] ?? self::getCurrentRate(),
            'updated_at' => $cached['time'] ?? time(),
            'date' => $cached['date'] ?? date('Y-m-d H:i:s'),
        ];
    }
    
    public static function usdToIrr(?float $usdAmount): int
    {
        if (!$usdAmount || $usdAmount <= 0) return 0;
        return (int) round($usdAmount * self::getCurrentRate());
    }
    
    public static function irrToUsd(int $irrAmount): float
    {
        if ($irrAmount <= 0) return 0;
        $rate = self::getCurrentRate();
        return $rate > 0 ? round($irrAmount / $rate, 2) : 0;
    }
    
    public static function roundDown(int $irrAmount): int
    {
        if ($irrAmount <= 0) return 0;
        return $irrAmount >= 1000 
            ? (int) floor($irrAmount / 1000) * 1000
            : (int) floor($irrAmount / 100) * 100;
    }
    
    public static function clearCache(): void
    {
        Cache::forget('exchange_rate');
        Log::info('Cache cleared');
    }
    
    public static function testAll(): array
    {
        $results = [];
        
        $sources = [
            'Alanchand' => 'fetchFromAlanchand',
            'ExchangeRate-API' => 'fetchFromExchangeRateAPI',
        ];
        
        foreach ($sources as $name => $method) {
            $start = microtime(true);
            try {
                $rate = self::$method();
                $time = round((microtime(true) - $start) * 1000);
                
                $results[$name] = [
                    'success' => $rate !== null,
                    'rate' => $rate,
                    'time_ms' => $time,
                    'valid' => self::isValidRate($rate)
                ];
            } catch (\Exception $e) {
                $results[$name] = [
                    'success' => false,
                    'error' => substr($e->getMessage(), 0, 80)
                ];
            }
        }
        
        return $results;
    }
}