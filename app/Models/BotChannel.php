<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotChannel extends Model
{
    protected $table = 'v2_bot_channels';
    
    protected $fillable = ['channel_id', 'title', 'invite_link', 'status'];

    protected $casts = ['status' => 'boolean'];
}
