<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestGoogleRegister extends Command
{
    protected $signature = 'test:google-register {email? : Email address for test user}';
    protected $description = 'شبیه‌سازی ثبت‌نام با Google بدون توکن واقعی';

    public function handle()
    {
        $this->info('🚀 Starting Google Register Simulation...');
        $this->newLine();

        // دریافت ایمیل از آرگومان یا تولید تصادفی
        $email = $this->argument('email') ?? 'test.' . time() . '@gmail.com';

        $this->info("📧 Email: {$email}");
        $this->newLine();

        // بررسی وجود کاربر
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            $this->warn('⚠️  این کاربر قبلا ثبت‌نام کرده است!');
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $existingUser->id],
                    ['Email', $existingUser->email],
                    ['UUID', $existingUser->uuid],
                    ['Token', substr($existingUser->token, 0, 20) . '...'],
                    ['Created At', date('Y-m-d H:i:s', $existingUser->created_at)],
                ]
            );
            
            if ($this->confirm('آیا می‌خواهید این کاربر را پاک کنید و دوباره ثبت‌نام کنید؟', false)) {
                $existingUser->delete();
                $this->info('✓ کاربر قبلی حذف شد');
            } else {
                return 0;
            }
        }

        // شبیه‌سازی داده‌های Google
        $googleUser = [
            'email' => $email,
            'name' => 'Test User ' . time(),
            'picture' => 'https://lh3.googleusercontent.com/a/default-user'
        ];

        $this->info('📦 شبیه‌سازی داده‌های Google:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $googleUser['email']],
                ['Name', $googleUser['name']],
                ['Picture', $googleUser['picture']],
            ]
        );
        $this->newLine();

        // بررسی محدودیت‌ها
        $this->info('🔍 بررسی محدودیت‌های ثبت‌نام...');
        
        if ((int)config('v2board.stop_register', 0)) {
            $this->error('✗ ثبت‌نام بسته است!');
            return 1;
        }
        $this->line('  ✓ ثبت‌نام باز است');

        if ((int)config('v2board.email_whitelist_enable', 0)) {
            $suffix = explode('@', $email)[1];
            $whitelist = config('v2board.email_whitelist_suffix', '');
            $this->line("  ✓ Whitelist check: {$suffix}");
        }

        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                $this->error('✗ Gmail alias پشتیبانی نمی‌شود!');
                return 1;
            }
            $this->line('  ✓ Gmail alias check passed');
        }

        $this->newLine();
        $this->info('👤 ایجاد کاربر جدید...');

        // ایجاد کاربر
        $user = new User();
        $user->email = $googleUser['email'];
        $randomPassword = Helper::randomChar(8);
        $user->password = password_hash($randomPassword, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();

        // اعمال Try Out Plan
        $planApplied = false;
        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->device_limit = $plan->device_limit;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
                $planApplied = true;
                $this->line("  ✓ Try Out Plan applied: {$plan->name}");
            }
        }

        if (!$user->save()) {
            $this->error('✗ ذخیره کاربر ناموفق بود!');
            return 1;
        }

        $user->last_login_at = time();
        $user->save();

        $this->info('✓ کاربر با موفقیت ایجاد شد!');
        $this->newLine();

        // نمایش اطلاعات کاربر
        $this->info('📊 اطلاعات کاربر جدید:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->id],
                ['Email', $user->email],
                ['UUID', $user->uuid],
                ['Token', substr($user->token, 0, 30) . '...'],
                ['Random Password', $randomPassword . ' (این رمز در DB hash شده ذخیره شد)'],
                ['Plan ID', $user->plan_id ?? 'N/A'],
                ['Transfer Enable', $user->transfer_enable ? Helper::trafficConvert($user->transfer_enable) : 'N/A'],
                ['Device Limit', $user->device_limit ?? 'N/A'],
                ['Expired At', $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : 'N/A'],
                ['Created At', date('Y-m-d H:i:s', $user->created_at)],
            ]
        );

        $this->newLine();
        $this->info('🔐 تولید Auth Data...');

        // شبیه‌سازی Request
        $request = Request::create('/test', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Google OAuth Test'
        ]);

        $authService = new AuthService($user);
        $authData = $authService->generateAuthData($request);

        $this->table(
            ['Field', 'Value'],
            [
                ['Token', substr($authData['token'], 0, 30) . '...'],
                ['Is Admin', $authData['is_admin'] ? 'Yes' : 'No'],
                ['Auth Data (JWT)', substr($authData['auth_data'], 0, 50) . '...'],
            ]
        );

        $this->newLine();
        $this->info('🎉 تست با موفقیت کامل شد!');
        
        // دستورات بعدی
        $this->newLine();
        $this->comment('📝 دستورات مفید:');
        $this->line("  • مشاهده کاربر: php artisan tinker");
        $this->line("    User::find({$user->id})");
        $this->line("  • حذف کاربر: php artisan tinker");
        $this->line("    User::find({$user->id})->delete()");
        $this->line("  • تست دوباره با همین ایمیل:");
        $this->line("    php artisan test:google-register {$email}");

        return 0;
    }
}