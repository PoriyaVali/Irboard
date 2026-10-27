<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerSudoku extends Model
{
    protected $table = 'v2_server_sudoku';
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
        'padding_min' => 'integer',
        'padding_max' => 'integer',
        'enable_pure_downlink' => 'boolean',
        'http_mask' => 'boolean',
        'http_mask_tls' => 'boolean',
        'httpmask' => 'array',
        'custom_tables' => 'array',
        'tfo' => 'boolean',
        'mptcp' => 'boolean',
        'routing_mark' => 'integer',
    ];
}
