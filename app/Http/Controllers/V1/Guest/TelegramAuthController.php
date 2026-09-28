<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\Telegram\TelegramLoginLink;
use Illuminate\Http\Request;

class TelegramAuthController extends Controller
{
    /**
     * لاگین با لینک امضاشده‌ی ربات تلگرام و هدایت به پنل
     *
     * Only the signed link the bot issues (TelegramLoginLink) signs anyone in.
     * The subscription token this used to accept is inside every subscription
     * link, so it signed in whoever held one - an admin's link included. A link
     * that does not verify, including an old ?token= one, goes to the login
     * page, as a missing token always did.
     */
    public function loginAndRedirect(Request $request)
    {
        $tradeNo = $request->input('order');
        $redirect = $request->input('redirect', 'dashboard');
        $loginPath = config('v2board.frontend_login_path', 'index.html');
        $frontendUrl = config('v2board.frontend_url');

        $user = TelegramLoginLink::verify(
            $request->input('uid'),
            $request->input('exp'),
            $request->input('sig')
        );

        if (!$user || $user->banned) {
            return redirect($frontendUrl . '/' . $loginPath . '#/login');
        }

        // استفاده از AuthService برای تولید auth_data
        $authService = new AuthService($user);
        $authData = $authService->generateAuthData($request);

        // Both end up in the URL fragment; keep them to the characters a route
        // or a trade number is made of.
        $redirect = is_string($redirect) && preg_match('#^[A-Za-z0-9/_-]{1,64}$#', $redirect) ? $redirect : 'dashboard';
        $tradeNo = is_string($tradeNo) && preg_match('/^[A-Za-z0-9]{1,64}$/', $tradeNo) ? $tradeNo : null;

        if ($tradeNo) {
            $redirectUrl = "{$frontendUrl}/{$loginPath}?auth_data=" . urlencode($authData['auth_data']) . "#/order/{$tradeNo}";
        } else {
            $redirectUrl = "{$frontendUrl}/{$loginPath}?auth_data=" . urlencode($authData['auth_data']) . "#/{$redirect}";
        }

        return redirect($redirectUrl);
    }
}
