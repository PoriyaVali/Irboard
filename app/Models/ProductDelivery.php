<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What a paid product order delivered, or is waiting to deliver. */
class ProductDelivery extends Model
{
    protected $table = 'v2_product_delivery';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['data'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const WAITING = 0;
    public const DELIVERED = 1;
}
