<?php

namespace App\Http\Middleware;

use Closure;
use Fideloper\Proxy\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_ALL;

    /**
     * '*' trusts X-Forwarded-For from anyone who can reach the app directly,
     * so a client can pick its own IP and step around every per-IP limit
     * (registration, throttles). It stays the default because the panel is
     * reached through nginx, the SSH tunnel and the relay, and which of them
     * sit in front of a given install is only known there. Set
     * TRUSTED_PROXIES in .env to the addresses those really connect from
     * (comma-separated IPs or CIDR ranges, e.g. "127.0.0.1,10.0.0.0/8") and
     * only they are believed.
     */
    public function handle(Request $request, Closure $next)
    {
        $configured = trim((string)config('app.trusted_proxies', ''));
        if ($configured !== '' && $configured !== '*') {
            $this->proxies = array_values(array_filter(array_map('trim', explode(',', $configured))));
        }
        return parent::handle($request, $next);
    }
}
