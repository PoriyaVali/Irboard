<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TestGoogleLogin extends Command
{
    protected $signature = 'test:google-login {email : Email address to test login}';
    protected $description = 'شبیه‌سازی لاگین با Google برای کاربر موجود';

    public function handle()
    {
        $this->info('🔐 Starting Google Login Simulation...');
        $this->newLine();

        $email = $this->argument('email');
        $this->info("📧 Email: {$email}");
        $this->newLine();

        // جستجوی کاربر
        $this->info('🔍 جستجوی کاربر در دیتابیس...');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error('✗ کاربر یافت نشد!');
            $this->newLine();
            $this->comment('💡 ابتدا یک کاربر بسازید:');
            $this->line("   php artisan test:google-register {$email}");
            return 1;
        }

        $this->info('✓ کاربر یافت شد!');
        $this->newLine();

        // نمایش اطلاعات کاربر
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->id],
                ['Email', $user->email],
                ['UUID', $user->uuid],
                ['Token', substr($user->token, 0, 30) . '...'],
                ['Is Admin', $user->is_admin ? 'Yes' : 'No'],
                ['Banned', $user->banned ? 'Yes ⚠️' : 'No'],
                ['Last Login', $user->last_login_at ? date('Y-m-d H:i:s', $user->last_login_at) : 'Never'],
                ['Created At', date('Y-m-d H:i:s', $user->created_at)],
            ]
        );

        // بررسی banned
        if ($user->banned) {
            $this->error('✗ این کاربر مسدود شده است!');
            return 1;
        }

        $this->newLine();
        $this->info('🔓 لاگین کاربر...');

        // آپدیت last_login_at
        $user->last_login_at = time();
        $user->save();

        // شبیه‌سازی Request
        $request = Request::create('/test', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Google OAuth Test'
        ]);

        $authService = new AuthService($user);
        $authData = $authService->generateAuthData($request);

        $this->info('✓ لاگین موفق!');
        $this->newLine();

        // نمایش Auth Data
        $this->info('📊 Auth Data تولید شده:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Token', substr($authData['token'], 0, 30) . '...'],
                ['Is Admin', $authData['is_admin'] ? 'Yes' : 'No'],
                ['Auth Data (JWT)', substr($authData['auth_data'], 0, 50) . '...'],
            ]
        );

        $this->newLine();

        // تست JWT Decode
        $this->info('🔍 تست JWT Decode...');
        $decodedUser = AuthService::decryptAuthData($authData['auth_data']);

        if ($decodedUser) {
            $this->info('✓ JWT معتبر است!');
            $this->table(
                ['Field', 'Value'],
                [
                    ['User ID', $decodedUser['id']],
                    ['Email', $decodedUser['email']],
                    ['Is Admin', $decodedUser['is_admin'] ? 'Yes' : 'No'],
                    ['Is Staff', $decodedUser['is_staff'] ? 'Yes' : 'No'],
                ]
            );
        } else {
            $this->error('✗ JWT معتبر نیست!');
            return 1;
        }

        $this->newLine();

        // نمایش Sessions
        $this->info('📋 Sessions فعال کاربر:');
        $sessions = $authService->getSessions();
        
        if (empty($sessions)) {
            $this->warn('  هیچ session فعالی وجود ندارد');
        } else {
            $sessionData = [];
            foreach ($sessions as $guid => $meta) {
                $sessionData[] = [
                    'GUID' => substr($guid, 0, 20) . '...',
                    'IP' => $meta['ip'] ?? 'N/A',
                    'Login At' => isset($meta['login_at']) ? date('Y-m-d H:i:s', $meta['login_at']) : 'N/A',
                    'User Agent' => substr($meta['ua'] ?? 'N/A', 0, 30) . '...',
                ];
            }
            $this->table(['GUID', 'IP', 'Login At', 'User Agent'], $sessionData);
        }

        $this->newLine();
        $this->info('🎉 تست لاگین با موفقیت کامل شد!');
        
        // دستورات بعدی
        $this->newLine();
        $this->comment('📝 دستورات مفید:');
        $this->line("  • تست دوباره:");
        $this->line("    php artisan test:google-login {$email}");
        $this->line("  • مشاهده sessions:");
        $this->line("    php artisan cache:get USER_SESSIONS_{$user->id}");
        $this->line("  • تست API با JWT:");
        $this->line("    curl -H 'Authorization: {$authData['auth_data']}' http://localhost/api/...");

        return 0;
    }
}