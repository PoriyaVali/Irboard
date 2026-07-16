<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotPanel extends Model
{
    protected $table = 'v2_bot_panels';
    
    protected $fillable = [
        'name', 'type', 'url', 'username', 'password', 'token',
        'token_expires_at', 'sub_link', 'inbounds', 'proxies',
        'status', 'test_enabled', 'on_hold_enabled', 'username_method'
    ];

    protected $casts = [
        'status' => 'boolean',
        'test_enabled' => 'boolean',
        'on_hold_enabled' => 'boolean',
        'inbounds' => 'array',
        'proxies' => 'array',
        'token_expires_at' => 'datetime'
    ];

    public function isTokenExpired(): bool
    {
        return !$this->token || ($this->token_expires_at && $this->token_expires_at->isPast());
    }
}
