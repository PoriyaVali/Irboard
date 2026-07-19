<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ExchangeService;

class ExchangeRateAdminController extends Controller
{
    public function current()
    {
        $data = ExchangeService::getRateData();

        $minutesAgo = isset($data["updated_at"]) ? (int) round((time() - $data["updated_at"]) / 60) : null;

        if ($minutesAgo === null) {
            $minutesAgoText = "";
        } elseif ($minutesAgo < 1) {
            $minutesAgoText = "همین الان";
        } elseif ($minutesAgo < 60) {
            $minutesAgoText = $minutesAgo . " دقیقه پیش";
        } else {
            $minutesAgoText = floor($minutesAgo / 60) . " ساعت پیش";
        }

        return response([
            "data" => [
                "rate"          => $data["rate"] ?? null,
                "has_cache"     => isset($data["rate"]),
                "formatted"     => isset($data["rate"]) ? number_format($data["rate"]) : null,
                "minutes_ago"   => $minutesAgo,
                "minutes_ago_text" => $minutesAgoText,
            ]
        ]);
    }

    public function refresh()
    {
        ExchangeService::clearCache();
        $rate = ExchangeService::getCurrentRate();

        return response([
            "data" => [
                "rate"      => $rate,
                "success"   => $rate !== null,
                "formatted" => $rate ? number_format($rate) : null,
                "minutes_ago_text" => "همین الان",
            ]
        ]);
    }
}
