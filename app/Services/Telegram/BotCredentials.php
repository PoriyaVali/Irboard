<?php

namespace App\Services\Telegram;

use App\Models\BotSetting;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * The email and password the bot gives a user for logging in to the app.
 *
 * An account the bot made (tg_<telegram id>@telegram.user) was created with a
 * random password nobody ever saw, so its owner could not log in to the app at
 * all. The first time the bot is asked, it sets a readable password and keeps it
 * encrypted with the app key, so every later request shows the same one rather
 * than changing it under an app that is already logged in.
 *
 * 🔑 A password the bot did not set is never replaced on its own: an account
 * with a real email has a password its owner chose, and a password changed
 * since the bot set it belongs to whoever changed it. For those only an explicit
 * "new password" tap resets it - and never for an admin or staff account, whose
 * panel access must not depend on who holds the Telegram account.
 */
class BotCredentials
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return array{email: string, password: ?string, reason: ?string}
     *         password null with reason 'own' (a password the bot did not set)
     *         or 'privileged' (admin/staff, never handled by the bot)
     */
    public static function forUser(User $user): array
    {
        $email = (string) $user->email;
        if ($user->is_admin || $user->is_staff) {
            return ['email' => $email, 'password' => null, 'reason' => 'privileged'];
        }

        $stored = self::stored($user);
        if ($stored !== null && self::matches($user, $stored)) {
            return ['email' => $email, 'password' => $stored, 'reason' => null];
        }
        // Only an account the bot made, with a password the bot never handed
        // out, gets one without being asked.
        if ($stored === null && self::isBotAccount($user)) {
            return ['email' => $email, 'password' => self::issue($user), 'reason' => null];
        }
        return ['email' => $email, 'password' => null, 'reason' => 'own'];
    }

    /** A new password at the owner's own request; null for admin/staff. Ends the account's open sessions. */
    public static function reset(User $user): ?string
    {
        if ($user->is_admin || $user->is_staff) {
            return null;
        }
        $password = self::issue($user);
        try {
            (new AuthService($user))->removeAllSession();
        } catch (\Throwable $e) {
            Log::warning('bot password reset: sessions not cleared', ['user' => $user->id, 'msg' => $e->getMessage()]);
        }
        return $password;
    }

    private static function issue(User $user): string
    {
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        // As the panel's own password reset does.
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
        $user->save();
        BotSetting::set(self::key($user), Crypt::encryptString($password));
        return $password;
    }

    private static function stored(User $user): ?string
    {
        $value = BotSetting::get(self::key($user));
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function matches(User $user, string $password): bool
    {
        return Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password);
    }

    private static function isBotAccount(User $user): bool
    {
        return $user->telegram_id && strcasecmp((string) $user->email, 'tg_' . $user->telegram_id . '@telegram.user') === 0;
    }

    private static function key(User $user): string
    {
        return 'bot_pw:' . $user->id;
    }
}
