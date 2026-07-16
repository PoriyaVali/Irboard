<?php
namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotSetting;
use Illuminate\Http\Request;

class BotSettingController extends Controller
{
    /**
     * دریافت تمام تنظیمات ربات
     */
    public function fetch()
    {
        $settings = BotSetting::all()->pluck('value', 'key')->toArray();
        
        return response([
            'data' => $settings
        ]);
    }

    /**
     * ذخیره تنظیمات ربات
     */
    public function save(Request $request)
    {
        $allowedKeys = [
            'bot_enabled',
            'payment_transit_enable',
            'payment_transit_url',
            'payment_proxy_enable',
            'payment_proxy_url',
            'payment_proxy_secret',
            'test_duration',
            'test_limit',
            'test_volume',
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $allowedKeys)) {
                BotSetting::set($key, $value);
            }
        }

        return response([
            'data' => true
        ]);
    }
}
