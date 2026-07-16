<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSetting extends Model
{
    protected $table = 'v2_bot_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = ['key', 'value'];

    public static function get(string $key, $default = null)
    {
        return self::find($key)?->value ?? $default;
    }

    public static function set(string $key, $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value !== null ? in_array($value, ['1', 'true', 'on', true], true) : $default;
    }
}
