<?php

namespace App\Console\Commands;

use App\Services\GoogleAuthService;
use Illuminate\Console\Command;

class TestGoogleAuth extends Command
{
    protected $signature = 'test:google-auth {--code= : Google authorization code}';
    protected $description = 'Test Google authentication service';

    private $googleAuthService;

    public function __construct(GoogleAuthService $googleAuthService)
    {
        parent::__construct();
        $this->googleAuthService = $googleAuthService;
    }

    public function handle()
    {
        $this->info('🔍 Testing Google Auth Service...');
        $this->newLine();

        // تست 1: تولید URL
        $this->info('✓ Test 1: Generating Google Login URL');
        $url = $this->googleAuthService->getAuthorizationUrl('http://localhost/callback');
        $this->line("URL: {$url}");
        $this->newLine();

        // تست 2: دریافت کاربر (اگر code داده شده)
        if ($code = $this->option('code')) {
            $this->info('✓ Test 2: Getting user from Google');
            $user = $this->googleAuthService->getUserFromCode($code, 'http://localhost/callback');
            
            if ($user) {
                $this->info('✓ Success! User info:');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Email', $user['email'] ?? 'N/A'],
                        ['Name', $user['name'] ?? 'N/A'],
                        ['Picture', $user['picture'] ?? 'N/A'],
                    ]
                );
            } else {
                $this->error('✗ Failed to get user info from Google');
            }
        } else {
            $this->comment('💡 برای تست کامل، code را از Google دریافت کنید:');
            $this->line("   1. این URL را در browser باز کنید:");
            $this->line("      {$url}");
            $this->line("   2. بعد از ورود، code را از URL دریافت کنید");
            $this->line("   3. دستور را با --code اجرا کنید:");
            $this->line("      php artisan test:google-auth --code=YOUR_CODE");
        }

        $this->newLine();
        $this->info('✓ Test completed!');
    }
}