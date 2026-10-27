<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerTailscale extends Model
{
    protected $table = 'v2_server_tailscale';
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
        'ephemeral' => 'boolean',
        'udp' => 'boolean',
        'accept_routes' => 'boolean',
        'exit_node_allow_lan_access' => 'boolean',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
