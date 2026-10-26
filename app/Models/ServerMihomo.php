<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Externally provisioned client profiles, not managed V2bX inbounds. */
class ServerMihomo extends Model
{
    protected $table = 'v2_server_mihomo';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        'group_id' => 'array', 'tags' => 'array', 'outbound_settings' => 'array',
        'show' => 'integer', 'sort' => 'integer',
    ];
}
