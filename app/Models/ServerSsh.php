<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerSsh extends Model
{
    protected $table = 'v2_server_ssh';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'group_id' => 'array',
        'route_id' => 'array',
        'tags' => 'array',
        'show' => 'integer',
        'sort' => 'integer',
        'parent_id' => 'integer',
        'port' => 'integer',
        'server_port' => 'integer',
        'host_key' => 'array',
        'host_key_algorithms' => 'array',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
