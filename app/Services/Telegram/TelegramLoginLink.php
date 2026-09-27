<?php

namespace App\Services\Telegram;

use App\Models\User;

/**
 * The bot's "open the panel" link, signed for one account.
 *
 * It used to carry the account's subscription token, which is also the secret
 * inside every subscription link - the one pasted into VPN apps and passed
 * around. Anyone holding a subscription link could open a full session on the
 * panel with it, an admin's included.
 *
 * The link now names the account and an expiry, signed with the app key. The
 * signature also covers the Telegram id and the subscription token as they are
 * now, so unbinding Telegram or resetting the subscription link retires every
 * link issued before. It lives only in the private chat with the bot, and the
 * keyboard holding it is sent again with every main menu.
 */
class TelegramLoginLink
{
    /** How long a link keeps working after the menu that carried it was sent. */
    public const TTL = 7 * 86400;

    public static function make(User $user, string $redirect = 'dashboard'): string
    {
        $exp = time() + self::TTL;
        $query = http_build_query([
            'uid' => $user->id,
            'exp' => $exp,
            'sig' => self::sign($user, $exp),
            'redirect' => $redirect,
        ]);
        return rtrim((string)config('v2board.app_url', ''), '/') . '/api/v1/guest/telegram/auth?' . $query;
    }

    /**
     * The account a link was issued for, or null when the link is not valid:
     * expired, altered, or issued before the account's Telegram binding or
     * subscription token changed.
     */
    public static function verify($uid, $exp, $sig): ?User
    {
        if (!is_scalar($uid) || !is_scalar($exp) || !is_string($sig)) return null;
        if (!ctype_digit((string)$uid) || !ctype_digit((string)$exp)) return null;
        $exp = (int)$exp;
        if ($exp < time() || $exp > time() + self::TTL + 300) return null;
        $user = User::find((int)$uid);
        if (!$user || !$user->telegram_id) return null;
        if (!hash_equals(self::sign($user, $exp), $sig)) return null;
        return $user;
    }

    private static function sign(User $user, int $exp): string
    {
        return hash_hmac('sha256', implode('|', [
            'tg-login',
            $user->id,
            $exp,
            (string)$user->telegram_id,
            (string)$user->token,
        ]), (string)config('app.key'));
    }
}
