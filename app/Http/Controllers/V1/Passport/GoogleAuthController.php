<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthGoogle;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\GoogleAuthService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleAuthController extends Controller
{
    private $googleAuthService;

    public function __construct(GoogleAuthService $googleAuthService)
    {
        $this->googleAuthService = $googleAuthService;
    }

    /**
     * دریافت URL ورود به گوگل
     */
    public function getLoginUrl()
    {
        try {
            $url = $this->googleAuthService->getAuthorizationUrl();
            return response()->json(['data' => $url]);
        } catch (\Exception $e) {
            Log::error('Google getLoginUrl Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to generate Google login URL'
            ], 500);
        }
    }

    /**
     * Callback از گوگل — GET و POST
     */
    public function callback(AuthGoogle $request)
    {
        try {
            // دریافت کد تایید از Google
            $code = $request->query('code') ?? $request->input('code');
            
            if (!$code) {
                Log::warning('Google OAuth: No authorization code received');
                return $this->redirectToFrontendWithError('کد تایید از Google دریافت نشد');
            }

            // دریافت اطلاعات کاربر از Google
            $googleUser = $this->googleAuthService->getUserFromCode($code);
            
            if (!$googleUser || empty($googleUser['email'])) {
                Log::error('Google OAuth: Failed to get user info', ['code' => substr($code, 0, 10) . '...']);
                return $this->redirectToFrontendWithError('دریافت اطلاعات کاربر از Google با خطا مواجه شد');
            }

            Log::info('Google OAuth: User info received', [
                'email' => $googleUser['email']
            ]);

            // بررسی وجود کاربر
            $email = $googleUser['email'];
            $user = User::where('email', $email)->first();

            if ($user) {
                return $this->handleExistingUser($user, $request);
            }

            return $this->handleNewUser($googleUser, $request);
            
        } catch (\Exception $e) {
            Log::error('Google OAuth Critical Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->redirectToFrontendWithError('خطای سیستمی رخ داد. لطفاً دوباره تلاش کنید');
        }
    }

    /**
     * مدیریت کاربر موجود
     */
    private function handleExistingUser(User $user, Request $request)
    {
        // بررسی مسدود بودن کاربر
        if ($user->banned) {
            Log::warning('Google OAuth: Banned user attempted login', ['user_id' => $user->id]);
            return $this->redirectToFrontendWithError('حساب کاربری شما مسدود شده است');
        }

        // بروزرسانی زمان آخرین ورود
        $user->last_login_at = time();
        $user->save();

        Log::info('Google OAuth: Existing user logged in', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);

        return $this->generateAuthResponse($user, $request);
    }

    /**
     * مدیریت کاربر جدید (ثبت نام)
     */
    private function handleNewUser(array $googleUser, Request $request)
    {
        // بررسی فعال بودن ثبت نام
        if ((int)config('v2board.stop_register', 0)) {
            Log::warning('Google OAuth: Registration attempt while disabled');
            return $this->redirectToFrontendWithError('ثبت نام در حال حاضر غیرفعال است');
        }

        // بررسی whitelist ایمیل
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $googleUser['email'],
                config('v2board.email_whitelist_suffix')
            )) {
                Log::warning('Google OAuth: Email not in whitelist', [
                    'email' => $googleUser['email']
                ]);
                return $this->redirectToFrontendWithError('دامنه ایمیل شما مجاز نیست');
            }
        }

        // ایجاد کاربر جدید
        $user = new User();
        $user->email = $googleUser['email'];
        $user->password = password_hash(Helper::randomChar(16), PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();

        // اعمال پلن آزمایشی (در صورت وجود)
        $this->applyTryOutPlan($user);

        // ذخیره کاربر
        if (!$user->save()) {
            Log::error('Google OAuth: Failed to save new user', [
                'email' => $googleUser['email']
            ]);
            return $this->redirectToFrontendWithError('خطا در ثبت نام. لطفاً دوباره تلاش کنید');
        }

        // بروزرسانی زمان آخرین ورود
        $user->last_login_at = time();
        $user->save();

        Log::info('Google OAuth: New user registered', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);

        return $this->generateAuthResponse($user, $request);
    }

    /**
     * اعمال پلن آزمایشی
     */
    private function applyTryOutPlan(User $user)
    {
        $tryOutPlanId = (int)config('v2board.try_out_plan_id', 0);
        
        if (!$tryOutPlanId) {
            return;
        }

        $plan = Plan::find($tryOutPlanId);
        
        if (!$plan) {
            Log::warning('Google OAuth: Try-out plan not found', [
                'plan_id' => $tryOutPlanId
            ]);
            return;
        }

        $user->transfer_enable = $plan->transfer_enable * 1073741824; // GB to Bytes
        $user->device_limit = $plan->device_limit;
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
        $user->speed_limit = $plan->speed_limit;

        Log::info('Google OAuth: Try-out plan applied', [
            'user_id' => $user->id ?? 'new',
            'plan_id' => $plan->id
        ]);
    }

    /**
     * تولید پاسخ احراز هویت و redirect به Frontend
     */
    private function generateAuthResponse(User $user, Request $request)
    {
        try {
            // تولید JWT Token
            $authService = new AuthService($user);
            $authData = $authService->generateAuthData($request);

            // استخراج token از authData
            $jwtToken = is_array($authData) ? ($authData['auth_data'] ?? '') : $authData;

            if (empty($jwtToken)) {
                Log::error('Google OAuth: Empty JWT token', [
                    'user_id' => $user->id,
                    'authData' => $authData
                ]);
                return $this->redirectToFrontendWithError('خطا در تولید توکن احراز هویت');
            }

            Log::info('Google OAuth: Auth token generated successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'is_admin' => $user->is_admin ?? 0,
                'token_preview' => substr($jwtToken, 0, 30) . '...'
            ]);

            // ✅ FIX: Redirect به صفحه LOGIN با auth_data در query string
            $frontendUrl = config('app.frontend_url', 'https://drmobjay.com');
            
            // روش 1: auth_data در query string قبل از hash (توصیه می‌شود)
            $redirectUrl = $frontendUrl . '/index2332.html?auth_data=' . urlencode($jwtToken) . '#/login';
            
            Log::info('Google OAuth: Redirecting to frontend', [
                'url' => $frontendUrl . '/index2332.html?auth_data=***#/login',
                'user_id' => $user->id
            ]);

            return redirect($redirectUrl);
            
        } catch (\Exception $e) {
            Log::error('Google OAuth: Auth response generation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->redirectToFrontendWithError('خطا در احراز هویت. لطفاً دوباره تلاش کنید');
        }
    }

    /**
     * Redirect به Frontend با پیام خطا
     */
    private function redirectToFrontendWithError(string $message)
    {
        Log::warning('Google OAuth: Redirecting with error', ['message' => $message]);
        
        $frontendUrl = config('app.frontend_url', 'https://drmobjay.com');
        
        // ✅ FIX: error در query string قبل از hash
        $redirectUrl = $frontendUrl . '/index2332.html?error=' . urlencode($message) . '#/login';
        
        return redirect($redirectUrl);
    }
}
