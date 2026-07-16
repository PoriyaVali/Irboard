<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;

class TelegramAuthController extends Controller
{
    /**
     * لاگین با توکن تلگرام و هدایت به صفحه سفارش
     */
    public function loginAndRedirect(Request $request)
    {
        $token = $request->input('token');
        $tradeNo = $request->input('order');
        $redirect = $request->input('redirect', 'dashboard');
        $loginPath = config('v2board.frontend_login_path', 'index.html');

        if (!$token) {
            return redirect(config('v2board.frontend_url') . '/' . $loginPath . '#/login');
        }

        // پیدا کردن کاربر با token
        $user = User::where('token', $token)->first();

        if (!$user) {
            return redirect(config('v2board.frontend_url') . '/' . $loginPath . '#/login');
        }

        // استفاده از AuthService برای تولید auth_data
        $authService = new AuthService($user);
        $authData = $authService->generateAuthData($request);

        // هدایت به فرانت‌اند با auth_data
        $frontendUrl = config('v2board.frontend_url');
        
        if ($tradeNo) {
            $redirectUrl = "{$frontendUrl}/{$loginPath}?auth_data=" . urlencode($authData['auth_data']) . "#/order/{$tradeNo}";
        } else {
            $redirectUrl = "{$frontendUrl}/{$loginPath}?auth_data=" . urlencode($authData['auth_data']) . "#/{$redirect}";
        }

        return redirect($redirectUrl);
    }
}
