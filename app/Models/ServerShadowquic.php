<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerShadowquic extends Model
{
    protected $table = 'v2_server_shadowquic';
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
        'alpn' => 'array',
        'quic_versions' => 'array',
        'udp_over_stream' => 'boolean',
        'zero_rtt' => 'boolean',
        'keep_alive_interval' => 'integer',
        'cwnd' => 'integer',
        'recv_window_conn' => 'integer',
        'recv_window' => 'integer',
        'disable_mtu_discovery' => 'boolean',
        'max_datagram_frame_size' => 'integer',
        'max_open_streams' => 'integer',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
