<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerGostrelay extends Model
{
    protected $table = 'v2_server_gostrelay';
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
        'forward' => 'boolean',
        'udp' => 'boolean',
        'tls' => 'boolean',
        'mux' => 'boolean',
        'skip_cert_verify' => 'boolean',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
