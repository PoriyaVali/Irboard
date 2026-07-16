<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ExchangeRateController extends Controller
{
    public function fetch(Request $request)
    {
        $rate = ExchangeRateService::getUsdSellPrice();
        
        if (!$rate) {
            return response([
                'data' => null,
                'message' => 'خطا در دریافت نرخ دلار'
            ], 500);
        }
        
        // زمان آخرین بروزرسانی
        $cacheKey = 'exchange_rate_updated_at';
        $updatedAt = Cache::get($cacheKey);
        
        if (!$updatedAt) {
            $updatedAt = now();
            Cache::put($cacheKey, $updatedAt, 1800);
        }
        
        $minutesAgo = now()->diffInMinutes($updatedAt);
        
        if ($minutesAgo < 1) {
            $minutesAgoText = 'همین الان';
        } elseif ($minutesAgo < 60) {
            $minutesAgoText = $minutesAgo . ' دقیقه پیش';
        } else {
            $hoursAgo = floor($minutesAgo / 60);
            $minutesAgoText = $hoursAgo . ' ساعت پیش';
        }
        
        return response([
            'data' => [
                'rate' => $rate,
                'formatted' => number_format($rate),
                'updated_at' => $updatedAt->toIso8601String(),
                'minutes_ago' => $minutesAgo,
                'minutes_ago_text' => $minutesAgoText
            ]
        ]);
    }
}
