<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMasque extends Model
{
    protected $table = 'v2_server_masque';
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
        'udp' => 'boolean',
        'handshake_timeout' => 'integer',
        'skip_cert_verify' => 'boolean',
        'cwnd' => 'integer',
        'ip_stack' => 'array',
        'remote_dns_resolve' => 'boolean',
        'dns' => 'array',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
