<?php

namespace App\Protocols;

use App\Utils\Helper;

class V2rayNG
{
    public $flag = 'v2rayng';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $uri = '';
        $user = $this->user;
        $appName = config('v2board.app_name', 'V2Board');

        foreach ($this->servers as $server) {
            $uri .= Helper::buildUri($user['uuid'], $server);
        }

        return response(base64_encode($uri), 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}")
            ->header('profile-update-interval', '24')
            ->header('Profile-Title', 'base64:' . base64_encode($appName))
            ->header('Content-Disposition', 'attachment; filename="' . $appName . '"');
    }
}
