<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerOpenvpn extends Model
{
    protected $table = 'v2_server_openvpn';
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
        'data_ciphers' => 'array',
        'peer_info' => 'array',
        'ping' => 'integer',
        'ping_restart' => 'integer',
        'tran_window' => 'integer',
        'handshake_timeout' => 'integer',
        'mtu' => 'integer',
        'udp' => 'boolean',
        'ip_stack' => 'array',
        'remote_dns_resolve' => 'boolean',
        'dns' => 'array',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
