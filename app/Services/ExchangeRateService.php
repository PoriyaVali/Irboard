<?php
// app/Services/ExchangeRateService.php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /**
     * دریافت قیمت فروش دلار از alanchand.com
     * 
     * @param int $cacheMinutes مدت کش (دقیقه)
     * @return int|null قیمت به تومان
     */
    public static function getUsdSellPrice(int $cacheMinutes = 30): ?int
    {
        return Cache::remember('usd_sell_price', $cacheMinutes * 60, function () {
            return self::fetchUsdPrice();
        });
    }

    /**
     * دریافت قیمت بدون کش
     */
    public static function getUsdSellPriceFresh(): ?int
    {
        Cache::forget('usd_sell_price');
        return self::fetchUsdPrice();
    }

    /**
     * دریافت قیمت از سایت
     */
    private static function fetchUsdPrice(): ?int
    {
        try {
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                        'Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
                        'Connection: keep-alive',
                        'Referer: https://www.google.com/'
                    ]),
                    'timeout' => 15
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ];

            $ctx = stream_context_create($opts);
            $html = @file_get_contents('https://alanchand.com/', false, $ctx);

            if (!$html) {
                Log::error('ExchangeRate: Failed to fetch alanchand.com');
                return null;
            }

            preg_match('/دلار آمریکا<\/td>\s*<td[^>]*>([۰-۹,]+)<\/td>\s*<td[^>]*>([۰-۹,]+)/u', $html, $m);

            if (empty($m[2])) {
                Log::error('ExchangeRate: Could not parse USD price');
                return null;
            }

            $price = self::persianToEnglish($m[2]);
            $price = (int) str_replace(',', '', $price);

            Log::info('ExchangeRate: USD sell price fetched', ['price' => $price]);
            return $price;

        } catch (\Exception $e) {
            Log::error('ExchangeRate: Exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * تبدیل اعداد فارسی به انگلیسی
     */
    public static function persianToEnglish(string $string): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($persian, $english, $string);
    }

    /**
     * پاک کردن کش
     */
    public static function clearCache(): void
    {
        Cache::forget('usd_sell_price');
    }
}