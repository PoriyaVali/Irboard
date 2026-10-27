<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerEasytier extends Model
{
    protected $table = 'v2_server_easytier';
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
        'dhcp' => 'boolean',
        'peers' => 'array',
        'listeners' => 'array',
        'no_listener' => 'boolean',
        'mapped_listeners' => 'array',
        'exit_nodes' => 'array',
        'proxy_networks' => 'array',
        'udp' => 'boolean',
        'accept_dns' => 'boolean',
        'enable_exit_node' => 'boolean',
        'enable_encryption' => 'boolean',
        'private_mode' => 'boolean',
        'latency_first' => 'boolean',
        'disable_p2p' => 'boolean',
        'enable_kcp_proxy' => 'boolean',
        'disable_kcp_input' => 'boolean',
        'enable_quic_proxy' => 'boolean',
        'disable_quic_input' => 'boolean',
        'mtu' => 'integer',
        'secure_mode' => 'boolean',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
