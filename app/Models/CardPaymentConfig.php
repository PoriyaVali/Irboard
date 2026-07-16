<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardPaymentConfig extends Model
{
    protected $table = 'v2_card_payment_config';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'is_active' => 'boolean',
    ];

    /**
     * دریافت کارت فعال
     */
    public static function getActive()
    {
        return self::where('is_active', 1)->first();
    }
}
