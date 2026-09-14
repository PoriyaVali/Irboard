<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use App\Services\DeviceIdentityService;
use Closure;

class User
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, 'وارد نشده‌اید یا نشست منقضی شده است');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, 'وارد نشده‌اید یا نشست منقضی شده است');
        $request->merge([
            'user' => $user
        ]);
        // Which devices this account is used from, so one device behind many
        // accounts can be seen. Here and not only at login: nearly every live
        // session predates the header, and a Google sign-in is created by the
        // browser, which never sends it. The service gates this to one write
        // per account and device every six hours, and it cannot fail the
        // request.
        DeviceIdentityService::recordFromHeader((int)$user['id'], $request->header('x-device-ids'));
        return $next($request);
    }
}
