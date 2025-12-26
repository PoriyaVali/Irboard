<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleAuthService
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;

    public function __construct()
    {
        $this->clientId     = config('services.google.client_id');
        $this->clientSecret = config('services.google.client_secret');
        $this->redirectUri  = config('services.google.redirect'); // مقدار ثابت
    }

    /**
     * تولید URL ورود به گوگل
     */
    public function getAuthorizationUrl(): string
    {
        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'offline',
            'prompt'        => 'consent'
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    /**
     * دریافت اطلاعات کاربر از کد تأیید
     */
    public function getUserFromCode(string $code): ?array
    {
        // مرحله ۱: دریافت Access Token
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->redirectUri,
        ]);

        if (!$tokenResponse->successful()) {
            Log::error('Google Token Error', $tokenResponse->json());
            return null;
        }

        $accessToken = $tokenResponse->json('access_token');

        // مرحله ۲: دریافت اطلاعات کاربر
        $userResponse = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v2/userinfo');

        if (!$userResponse->successful()) {
            Log::error('Google UserInfo Error', $userResponse->json());
            return null;
        }

        return $userResponse->json();
    }
}
