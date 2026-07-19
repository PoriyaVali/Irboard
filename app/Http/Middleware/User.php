<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
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
        return $next($request);
    }
}
