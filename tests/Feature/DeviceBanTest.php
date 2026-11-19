<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * A device the admin banned stays banned: it cannot open a new account or
 * sign in to another one. The ban used to stop only the accounts it was
 * applied to, while the device signed up again at once.
 */
class DeviceBanTest extends TestCase
{
    use PanelFixtures;

    private const PAIR = 'wvd:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'adid:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_device_ban']);
    }

    private function ban(string $hash, ?int $revertedAt = null): void
    {
        DB::table('v2_device_ban')->insert([
            'batch' => str_repeat('b', 32), 'hash' => $hash, 'user_id' => 1, 'was_banned' => 0,
            'reverted_at' => $revertedAt, 'created_at' => time(),
        ]);
    }

    private function register(string $email, string $deviceIds)
    {
        return $this->withHeaders(['x-device-ids' => $deviceIds])
            ->postJson('/api/v1/passport/auth/register', ['email' => $email, 'password' => 'secret123']);
    }

    public function testABannedDeviceCannotSignUpAgain()
    {
        $this->ban(self::PAIR);
        $this->register('again@example.com', self::OTHER . ',' . self::PAIR)->assertStatus(500)
            ->assertJsonFragment(['message' => 'این دستگاه مسدود شده است؛ برای پیگیری با پشتیبانی تماس بگیرید.']);
        $this->assertSame(0, DB::table('v2_user')->where('email', 'again@example.com')->count());
    }

    public function testABanByTheBareHashFromALookupMatchesToo()
    {
        $this->ban(substr(self::PAIR, 4));
        $this->register('bare@example.com', self::PAIR)->assertStatus(500);
    }

    public function testARevertedBanAndAnotherDeviceDoNotStopAnyone()
    {
        $this->ban(self::PAIR, time());
        $this->register('reverted@example.com', self::PAIR)->assertStatus(200);
        $this->ban(self::OTHER);
        $this->register('clean@example.com', self::PAIR)->assertStatus(200);
        $this->register('nohead@example.com', '')->assertStatus(200);
    }

    public function testSignInFromABannedDeviceIsRefusedExceptForAdmins()
    {
        $this->ban(self::PAIR);
        $user = $this->makeUser(['email' => 'member@example.com']);
        $admin = $this->makeUser(['email' => 'admin@example.com', 'is_admin' => 1]);
        $this->withHeaders(['x-device-ids' => self::PAIR])
            ->postJson('/api/v1/passport/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertStatus(500);
        $this->withHeaders(['x-device-ids' => self::PAIR])
            ->postJson('/api/v1/passport/auth/login', ['email' => $admin->email, 'password' => 'secret123'])
            ->assertStatus(200);
        $this->withHeaders(['x-device-ids' => self::OTHER])
            ->postJson('/api/v1/passport/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertStatus(200);
    }
}
