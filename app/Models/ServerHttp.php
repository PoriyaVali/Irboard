<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerHttp extends Model
{
    protected $table = 'v2_server_http';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'managed' => 'boolean',
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
        'tls' => 'boolean',
        'skip_cert_verify' => 'boolean',
        'headers' => 'array',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
