<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerSnell extends Model
{
    protected $table = 'v2_server_snell';
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
        'udp' => 'boolean',
        'version' => 'integer',
        'reuse' => 'boolean',
        'obfs_opts' => 'array',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
