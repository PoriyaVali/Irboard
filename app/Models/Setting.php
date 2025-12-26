<?php
// app/Models/Setting.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $table = 'settings';
    protected $fillable = ['key', 'value'];
    
    /**
     * دریافت مقدار یک تنظیم
     */
    public static function get($key, $default = null)
    {
        return Cache::remember("setting_{$key}", 3600, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }
    
    /**
     * ذخیره/بروزرسانی یک تنظیم
     */
    public static function set($key, $value)
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
        Cache::forget("setting_{$key}");
    }
    
    /**
     * چک کردن فعال بودن گرد کردن خودکار
     */
    public static function isAutoRoundEnabled()
    {
        return self::get('auto_round_prices', '0') === '1';
    }
}