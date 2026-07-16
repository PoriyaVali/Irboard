<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotText extends Model
{
    protected $table = 'v2_bot_texts';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = ['key', 'value'];

    public static function get(string $key, string $default = ''): string
    {
        return self::find($key)?->value ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
