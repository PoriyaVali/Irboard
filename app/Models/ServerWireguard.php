<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerWireguard extends Model
{
    protected $table = 'v2_server_wireguard';
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
        'reserved' => 'array',
        'allowed_ips' => 'array',
        'workers' => 'integer',
        'mtu' => 'integer',
        'udp' => 'boolean',
        'persistent_keepalive' => 'integer',
        'ip_stack' => 'array',
        'amnezia_wg_option' => 'array',
        'peers' => 'array',
        'remote_dns_resolve' => 'boolean',
        'dns' => 'array',
        'refresh_server_ip_interval' => 'integer',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
