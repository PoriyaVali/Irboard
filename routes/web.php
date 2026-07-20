<?php

use App\Services\ThemeService;
use Illuminate\Http\Request;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\Guest\TelegramBotController;
use App\Http\Controllers\V1\Guest\TelegramAuthController;
use App\Http\Controllers\V1\Passport\GoogleAuthController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function (Request $request) {
    if (config('v2board.app_url') && config('v2board.safe_mode_enable', 0)) {
        if ($request->server('HTTP_HOST') !== parse_url(config('v2board.app_url'))['host']) {
            abort(403);
        }
    }
    $renderParams = [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme' => config('v2board.frontend_theme', 'default'),
        'version' => config('app.version'),
        'description' => config('v2board.app_description', 'V2Board is best'),
        'logo' => config('v2board.logo')
    ];

    if (!config("theme.{$renderParams['theme']}")) {
        $themeService = new ThemeService($renderParams['theme']);
        $themeService->init();
    }

    $renderParams['theme_config'] = config('theme.' . config('v2board.frontend_theme', 'default'));
    return view('theme::' . config('v2board.frontend_theme', 'default') . '.dashboard', $renderParams);
});

//TODO:: سازگاری با نسخه‌های قدیم
Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
        'theme_header' => config('v2board.frontend_theme_header', 'dark'),
        'theme_color' => config('v2board.frontend_theme_color', 'default'),
        'background_url' => config('v2board.frontend_background_url'),
        // Cache-bust on the bundle itself, not on the app version. nginx serves
        // these assets with max-age=3600 while the admin bundle is edited
        // independently of releases, so a version that only moves on release
        // left every admin on an hour-stale bundle after each change - which
        // makes a shipped fix look like it simply did not work.
        'version' => (function () {
            $bundle = public_path('assets/admin/umi.js');
            return is_file($bundle)
                ? config('app.version') . '.' . filemtime($bundle)
                : config('app.version');
        })(),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

if (!empty(config('v2board.subscribe_path'))) {
    Route::get(config('v2board.subscribe_path'), 'V1\\Client\\ClientController@subscribe')->middleware('client');
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 💳 Payment Routes
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// مسیر اصلی notify (پشتیبانی از Payment Tracking)
Route::post('payment/notify/{method}/{uuid}', [PaymentController::class, 'notify'])
    ->name('payment.notify')
    ->middleware('throttle:60,1');

// مسیرهای legacy (سازگاری با نسخه‌های قدیم)
Route::post('/api/v1/guest/payment/callback/aghayehpardakht', [PaymentController::class, 'aghayehpardakhtCallback']);
Route::post('/api/v1/guest/payment/callback/zibal', [PaymentController::class, 'zibalCallback']);

// نرخ دلار API
Route::get("/api/v1/guest/exchange-rate", [\App\Http\Controllers\V1\Guest\ExchangeRateController::class, "fetch"]);
Route::post("/api/v1/guest/telegram/webhook", [TelegramBotController::class, "webhook"]);
Route::get("/api/v1/guest/telegram/auth", [TelegramAuthController::class, "loginAndRedirect"]);
Route::post('payment/notify/zibal/{uuid}', [PaymentController::class, 'notify'])->name('payment.notify.zibal');

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 📊 Payment Tracking API (اختیاری - برای مانیتورینگ)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

/*
// فعال کردن این بخش اختیاری است - برای Admin Panel
Route::prefix('api/admin/payment-tracks')->middleware(['auth', 'admin'])->group(function () {
    
    // دریافت آمار payment tracks
    Route::get('/statistics', function () {
        return response()->json(\App\Models\PaymentTrack::getStatistics());
    });

    // لیست trackId های اخیر
    Route::get('/list', function () {
        $tracks = \App\Models\PaymentTrack::latest()
            ->limit(100)
            ->get(['id', 'track_id', 'order_id', 'user_id', 'amount', 'is_used', 'created_at']);
        
        return response()->json($tracks);
    });

    // پاکسازی دستی trackId های قدیمی
    Route::post('/cleanup', function () {
        $count = \App\Models\PaymentTrack::cleanup(24);
        return response()->json([
            'success' => true,
            'deleted_count' => $count,
            'message' => "✓ {$count} trackId قدیمی حذف شد"
        ]);
    });
    
    // بررسی معتبر بودن یک trackId
    Route::get('/validate/{trackId}', function ($trackId) {
        $isValid = \App\Models\PaymentTrack::isValid($trackId);
        $track = \App\Models\PaymentTrack::getByTrackId($trackId);
        
        return response()->json([
            'track_id' => $trackId,
            'is_valid' => $isValid,
            'exists' => $track !== null,
            'is_used' => $track ? $track->is_used : null,
            'created_at' => $track ? $track->created_at->format('Y-m-d H:i:s') : null,
            'used_at' => $track && $track->used_at ? $track->used_at->format('Y-m-d H:i:s') : null,
        ]);
    });
});
*/
// ✅ تست API
Route::get('/api/test', function() {
    return response()->json([
        'success' => true,
        'message' => 'Backend API is working!',
        'server' => parse_url(config('v2board.app_url'), PHP_URL_HOST),
        'time' => now()->toDateTimeString()
    ]);
});
Route::group(['prefix' => 'api/v1/passport/auth/google'], function () {
    Route::get('/url', [GoogleAuthController::class, 'getLoginUrl']);
    Route::match(['get', 'post'], '/callback', [GoogleAuthController::class, 'callback']); // مهم!
});
Route::post('/api/v1/user/email-by-token', 'V1\User\UserController@getEmailByToken')
    ->middleware('throttle:10,1'); // محدودیت: 10 درخواست در دقیقه

// ✅ شارژ کیف پول
Route::get('/api/v1/user/wallet/options', 'V1\\User\\UserController@getRechargeOptions');
Route::get('/api/v1/user/wallet/history', 'V1\\User\\UserController@getWalletHistory');
// ✅ بسته‌های رزرو
Route::get('/api/v1/user/reserved-plans', 'V1\\User\\UserController@getReservedPlans')->middleware('user');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 💳 Card to Card Payment Routes
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// User Routes
Route::prefix('api/v1/user/card-payment')->middleware('user')->group(function () {
    Route::get('/info', 'V1\\User\\CardPaymentController@getInfo');
    Route::post('/claim', 'V1\\User\\CardPaymentController@claim');
    Route::post('/cancel', 'V1\\User\\CardPaymentController@cancel');
});

// AppStore Routes
Route::prefix('api/v1/user/appstore')->middleware('user')->group(function () {
    Route::get('/list', 'V1\\User\\AppStoreController@list');
});

// Admin Routes
Route::prefix('api/v1/admin/card-payment')->middleware('admin')->group(function () {
    Route::get('/list', 'V1\\Admin\\CardPaymentController@list');
    Route::get('/detail', 'V1\\Admin\\CardPaymentController@detail');
    Route::get('/stats', 'V1\\Admin\\CardPaymentController@stats');
    Route::post('/verify-full', 'V1\\Admin\\CardPaymentController@verifyFull');
    Route::post('/verify-different', 'V1\\Admin\\CardPaymentController@verifyDifferent');
    Route::post('/reject', 'V1\\Admin\\CardPaymentController@reject');
});


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// ⚙️ Server Config Routes
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Route::prefix('api/v1/admin/server-config')->middleware('admin')->group(function () {
    Route::get('/status', 'V1\\Admin\\ServerConfigController@status');
    Route::post('/apply', 'V1\\Admin\\ServerConfigController@apply');
});

// AppStore Admin Routes
Route::prefix('api/v1/' . config('v2board.secure_path', 'admin') . '/appstore')->middleware('admin')->group(function () {
    Route::get('/list', 'V1\\Admin\\AppStoreController@list');
    Route::post('/save-app', 'V1\\Admin\\AppStoreController@saveApp');
    Route::post('/delete-app', 'V1\\Admin\\AppStoreController@deleteApp');
    Route::post('/save-banner', 'V1\\Admin\\AppStoreController@saveBanner');
    Route::post('/delete-banner', 'V1\\Admin\\AppStoreController@deleteBanner');
});

Route::prefix('api/v1/' . config('v2board.secure_path', 'admin') . '/smartbot')->middleware('admin')->group(function () {
    Route::get('/get', 'V1\\Admin\\SmartBotController@get');
    Route::post('/save', 'V1\\Admin\\SmartBotController@save');
});

// Hedioum tunnel toggle (admin) — per-node DIRECT/TUNNEL switch, backed by v2_tunnel_map
Route::prefix('api/v1/' . config('v2board.secure_path', 'admin') . '/tunnel')->middleware('admin')->group(function () {
    Route::get('/status', 'V1\\Admin\\TunnelController@status');
    Route::post('/toggle', 'V1\\Admin\\TunnelController@toggle');
});
