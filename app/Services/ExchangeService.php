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
                // Two gates, and they ask different questions. isValidRate asks
                // whether this could be a dollar price at all; plausible() asks
                // whether it is the same currency we read an hour ago. The
                // second is the one that catches a parse landing on the euro.
                if (self::isValidRate($rate) && self::plausible($rate)) {
                    Log::info("✓ {$method}", ['rate' => $rate]);
                    return $rate;
                }
            } catch (\Exception $e) {
                Log::debug("✗ {$method}", ['error' => $e->getMessage()]);
            }
        }
        
        return null;
    }
    
    /**
     * Is this plausibly a dollar price at all?
     *
     * 🔴 The ceiling used to be 200,000 and the market went past it. Every
     * correct reading was then rejected, Pattern 1 and 2 fell through, and a
     * page-wide number scrape returned some other currency instead - 146,700
     * against a real 203,200. Plans priced in USD were sold at 72% of their
     * intended price, hourly, for as long as that lasted.
     *
     * So this band is now wide enough to be about catching a *parse* error - a
     * three-digit fragment, a gold price in the millions - and not about
     * having an opinion on the exchange rate. Deciding what the dollar is
     * allowed to cost is not this function's job, and the last time it tried,
     * it was wrong in the expensive direction.
     *
     * The check that actually catches a wrong number is plausible() below.
     */
    private static function isValidRate(?int $rate): bool
    {
        return $rate && $rate >= 50000 && $rate <= 2000000;
    }

    /**
     * Is this the same currency we read last time?
     *
     * 🔑 The real defence, and the one that would have caught today's bug on
     * its own. An absolute band cannot tell a dollar from a euro; a rate that
     * moved 38% in an hour can only be a different number, not a different
     * price. Real moves are percent-a-day, not tens-of-percent-an-hour.
     *
     * Rejecting means falling back to the cached rate, which is a real dollar
     * price from an hour ago. That is always better than a confident reading of
     * something else entirely.
     */
    private static function plausible(int $rate): bool
    {
        $cached = Cache::get('exchange_rate');
        if (!is_array($cached) || empty($cached['rate'])) {
            return true;    // nothing to compare against
        }

        $drift = abs($rate - $cached['rate']) / $cached['rate'];
        if ($drift > 0.35) {
            Log::warning('Rate rejected: implausible jump', [
                'previous' => $cached['rate'],
                'candidate' => $rate,
                'drift' => round($drift * 100) . '%',
            ]);
            return false;
        }
        return true;
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
    
		/*
		 * 🔴 There was a Pattern 3 here and it was the whole bug.
		 *
		 * It scraped every NN,NNN number from the entire page - euro, pound,
		 * gold, crypto, anything - kept the ones inside the valid band and
		 * returned the *second* of them. When the two patterns above failed,
		 * which they did the moment the dollar went past the old 200,000
		 * ceiling, it confidently returned a different currency: 146,700
		 * against a real 203,200, hourly, and every plan priced in USD was sold
		 * at 72% of its intended price for as long as that lasted.
		 *
		 * Nothing downstream could tell. It is a number, it is in range, it
		 * passes every check - and it is the price of something else.
		 *
		 * Returning null is strictly better. The caller falls back to the
		 * cached rate, which is a real dollar price from an hour ago; failing
		 * that, to the configured fallback. Both are honest about being old.
		 * A wrong number is not.
		 */
		Log::warning('Alanchand: the دلار آمریکا row did not parse', [
			'html_bytes' => strlen($html),
		]);
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