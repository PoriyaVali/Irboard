<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Node definitions for the additional protocols offered by the node manager. */
class ServerMihomo extends Model
{
    protected $table = 'v2_server_mihomo';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        'group_id' => 'array', 'route_id' => 'array', 'tags' => 'array', 'outbound_settings' => 'array',
        'show' => 'integer', 'sort' => 'integer', 'port' => 'integer', 'server_port' => 'integer',
    ];
}
