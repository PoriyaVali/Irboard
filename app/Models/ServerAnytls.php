<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerAnytls extends Model
{
    protected $table = 'v2_server_anytls';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'group_id' => 'array',
        'route_id' => 'array',
        'padding_scheme' => 'array',
        'tags' => 'array',
        // Same cast as v2_server_v2node so both node types hand the node and
        // the subscription builders an array rather than a JSON string.
        'tls_settings' => 'array'
    ];
}
