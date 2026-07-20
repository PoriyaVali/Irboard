<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerGroup extends Model
{
    protected $table = 'v2_server_group';
    protected $dateFormat = 'U';
    protected $casts = [
        'created_at'    => 'timestamp',
        'updated_at'    => 'timestamp',
        'addon_enabled' => 'boolean',
        'price_per_gb'  => 'integer',
    ];
}
