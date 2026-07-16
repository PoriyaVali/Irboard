<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_url_generation()
    {
        $response = $this->getJson('/api/v1/passport/auth/google/url', [
            'redirect_uri' => 'http://localhost/callback'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertStringContainsString('accounts.google.com', $response->json('data'));
    }

    public function test_google_callback_with_existing_user()
    {
        // Mock GoogleAuthService
        $this->mock(GoogleAuthService::class, function ($mock) {
            $mock->shouldReceive('getUserFromCode')
                ->once()
                ->andReturn([
                    'email' => 'test@example.com',
                    'name' => 'Test User'
                ]);
        });

        // ایجاد کاربر موجود
        $user = User::create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'uuid' => 'test-uuid',
            'token' => 'test-token',
            'banned' => 0
        ]);

        $response = $this->postJson('/api/v1/passport/auth/google/callback', [
            'code' => 'fake-google-code',
            'redirect_uri' => 'http://localhost/callback'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['token', 'is_admin', 'auth_data']
        ]);
    }

    public function test_google_callback_creates_new_user()
    {
        // Mock GoogleAuthService
        $this->mock(GoogleAuthService::class, function ($mock) {
            $mock->shouldReceive('getUserFromCode')
                ->once()
                ->andReturn([
                    'email' => 'newuser@example.com',
                    'name' => 'New User'
                ]);
        });

        $response = $this->postJson('/api/v1/passport/auth/google/callback', [
            'code' => 'fake-google-code',
            'redirect_uri' => 'http://localhost/callback'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['token', 'is_admin', 'auth_data']
        ]);

        // بررسی ایجاد کاربر در دیتابیس
        $this->assertDatabaseHas('v2_user', [
            'email' => 'newuser@example.com'
        ]);
    }

    public function test_google_callback_validation_fails()
    {
        $response = $this->postJson('/api/v1/passport/auth/google/callback', [
            'code' => '',
            'redirect_uri' => 'invalid-url'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code', 'redirect_uri']);
    }

    public function test_banned_user_cannot_login()
    {
        $this->mock(GoogleAuthService::class, function ($mock) {
            $mock->shouldReceive('getUserFromCode')
                ->once()
                ->andReturn([
                    'email' => 'banned@example.com',
                    'name' => 'Banned User'
                ]);
        });

        User::create([
            'email' => 'banned@example.com',
            'password' => bcrypt('password'),
            'uuid' => 'test-uuid',
            'token' => 'test-token',
            'banned' => 1
        ]);

        $response = $this->postJson('/api/v1/passport/auth/google/callback', [
            'code' => 'fake-google-code',
            'redirect_uri' => 'http://localhost/callback'
        ]);

        $response->assertStatus(500);
    }
}