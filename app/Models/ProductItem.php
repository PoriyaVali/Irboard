<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One sellable unit of a stock-delivered product; its data is encrypted. */
class ProductItem extends Model
{
    protected $table = 'v2_product_item';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['data'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const AVAILABLE = 0;
    public const RESERVED = 1;
    public const SOLD = 2;
    public const WITHDRAWN = 3;
}
