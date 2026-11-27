<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A digital good sold in the store. See app/Services/ProductOrderService.php. */
class Product extends Model
{
    protected $table = 'v2_product';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['content'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'fields' => 'array',
        'show' => 'boolean',
    ];

    public const DELIVERY_STOCK = 'stock';
    public const DELIVERY_MANUAL = 'manual';
    public const DELIVERY_UNLIMITED = 'unlimited';
    public const DELIVERIES = [self::DELIVERY_STOCK, self::DELIVERY_MANUAL, self::DELIVERY_UNLIMITED];
}
