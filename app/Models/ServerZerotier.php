<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerZerotier extends Model
{
    protected $table = 'v2_server_zerotier';
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
        'mtu' => 'integer',
        'ip_stack' => 'array',
        'physical_mtu' => 'integer',
        'udp' => 'boolean',
        'remote_dns_resolve' => 'boolean',
        'dns' => 'array',
        'low_bandwidth' => 'boolean',
        'encrypted_hello' => 'boolean',
        'primary_port' => 'integer',
        'secondary_port' => 'integer',
        'orbit' => 'array',
        'remote_trace_level' => 'integer',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
