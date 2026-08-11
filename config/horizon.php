<?php

use Illuminate\Support\Str;
use Linfo\Linfo;

// مقدار پیش‌فرض برای جلوگیری از خطا
$maxProcesses = 10;

try {
    if (php_sapi_name() !== 'cli') {
        throw new Exception("Linfo is not required in non-CLI mode.");
    }
    $lInfo = new Linfo();
    $parser = $lInfo->getParser();
    $maxProcesses = (int)ceil($parser->getRam()['total'] / 1024 / 1024 / 1024 * 6);
} catch (\Throwable $e) {
    // در صورت بروز خطا، مقدار پیش‌فرض 10 باقی می‌ماند.
    // \Throwable و نه \Exception: اگر کلاس Linfo لود نشود یا پارسر روی این سیستم
    // بشکند، PHP 8 یک \Error پرتاب می‌کند که از \Exception رد می‌شود و کل بوتِ
    // برنامه را می‌خواباند — نه اینکه فقط به مقدار پیش‌فرض برگردد.
}

// سقف نهایی، مستقل از حافظه.
//
// فرمول بالا فقط RAM را می‌بیند و برای هر گیگابایت ۶ پروسه می‌دهد. روی یک
// ماشین بزرگ این عدد بی‌معنا می‌شود — ۳۲ گیگابایت یعنی ۱۹۲ کارگر — و هر کارگر
// اتصال Redis و حافظهٔ خودش را می‌گیرد. حافظه تنها چیزی نیست که محدود است:
// تعداد هستهٔ CPU و سقف اتصال Redis هم هستند و در این محاسبه دیده نمی‌شوند.
//
// این سقف فقط پایین می‌آورد و هرگز بالا نمی‌برد، پس یک مقدار بزرگ در .env
// همان رفتار قبلی را می‌دهد.
//
// مقدار کمتر از ۱ نادیده گرفته می‌شود، نه اینکه اعمال شود: یک صفر — چه عمدی و
// چه از یک مقدار نامعتبر که (int) آن را صفر می‌کند — یعنی هیچ کارگری اجرا
// نشود و کل صف پنل بخوابد. یک اشتباه تایپی در .env نباید بتواند چنین کند.
$maxProcessesCap = (int)env('HORIZON_MAX_PROCESSES', 128);
if ($maxProcessesCap >= 1) {
    $maxProcesses = min($maxProcesses, $maxProcessesCap);
}

// همان تنظیمات برای هر محیطی که پنل با آن اجرا می‌شود استفاده می‌شود.
// توضیح در بخش 'environments' پایین.
$supervisors = [
    'V2board' => [
        'connection' => 'redis',
        'queue' => [
            'order_handle',
            'traffic_fetch',
            'stat',
            'send_email',
            'send_email_mass',
            'send_telegram',
        ],
        'balance' => 'auto',
        'minProcesses' => 1,
        'maxProcesses' => $maxProcesses,
        'tries' => 1,
        'balanceCooldown' => 3,
    ],
];

return [

    'domain' => null,
    'path' => 'monitor',
    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['admin'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 32,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Horizon only starts workers for the environment named here. When APP_ENV is
    | not a key in this list, ProvisioningPlan::deploy() returns early: no worker
    | starts and nothing is logged — orders, mail, traffic and telegram just stop
    | being processed. .env.example ships APP_ENV=local and v2board:install never
    | rewrites it, so panels normally run as "local"; production is listed with the
    | same supervisors so that setting APP_ENV=production — the natural thing to do
    | on a live server — cannot silently disable the queue.
    |
    */

    'environments' => [
        'local' => $supervisors,
        'production' => $supervisors,
    ],
];
