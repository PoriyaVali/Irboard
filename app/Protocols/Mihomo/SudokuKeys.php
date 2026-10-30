<?php

namespace App\Protocols\Mihomo;

/**
 * Per-user keys of a managed Sudoku node.
 *
 * A Sudoku server derives its tables and AEAD keys from the master public
 * key, and a client from the public key its split key r || k sums to. So
 * every user can hold a split of the master private key x, r || x-r, and the
 * hash of the split names the user. V2bX derives the same r from x and the
 * user's UUID (core/mihomo/sudoku.go); a change here is a change there.
 *
 * The ristretto255 scalar functions are the ed25519 scalar field: same order,
 * same little-endian encoding.
 */
class SudokuKeys
{
    private const DOMAIN = 'irboard/sudoku/user-key/v1';

    /** A new master private key, 64 hex digits. */
    public static function generate(): string
    {
        do {
            $x = self::reduce(random_bytes(64));
        } while ($x === str_repeat("\0", 32));
        return bin2hex($x);
    }

    /** Why $hex cannot be a managed node's master private key, or null. */
    public static function problem($hex): ?string
    {
        if (!is_string($hex) || !preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            return 'در حالت V2bX، کلید Sudoku کلید خصوصی اصلی با ۶۴ رقم hex است؛ برای ساخت خودکار خالی بگذارید.';
        }
        $x = hex2bin($hex);
        if ($x === str_repeat("\0", 32) || self::reduce($x . str_repeat("\0", 32)) !== $x) {
            return 'این مقدار کلید خصوصی ed25519 معتبری نیست؛ برای ساخت خودکار خالی بگذارید.';
        }
        return null;
    }

    /** The split of the master key $hex that the user with $uuid holds. */
    public static function userKey(string $hex, string $uuid): string
    {
        $x = hex2bin(strtolower($hex));
        $r = self::reduce(hash('sha512', self::DOMAIN . "\0" . $x . $uuid, true));
        return bin2hex($r . self::subtract($x, $r));
    }

    private static function reduce(string $wide): string
    {
        return function_exists('sodium_crypto_core_ristretto255_scalar_reduce')
            ? sodium_crypto_core_ristretto255_scalar_reduce($wide)
            : \ParagonIE_Sodium_Core_Ristretto255::ristretto255_scalar_reduce($wide);
    }

    private static function subtract(string $x, string $y): string
    {
        return function_exists('sodium_crypto_core_ristretto255_scalar_sub')
            ? sodium_crypto_core_ristretto255_scalar_sub($x, $y)
            : \ParagonIE_Sodium_Core_Ristretto255::ristretto255_scalar_sub($x, $y);
    }
}
