<?php

namespace Tests\Fixtures;

/**
 * Fictional SSH keys made with golang.org/x/crypto/ssh for these tests only;
 * they never authenticate anywhere. Like the other key fixtures they are
 * stored without PEM armour, which is added on demand.
 */
class SshKeyFixtures
{
    private static $keys;

    private static function keys(): array
    {
        return self::$keys ?? (self::$keys = json_decode(file_get_contents(__DIR__ . '/SshKeys.json'), true));
    }

    public static function privateKey(string $name): string
    {
        $key = self::keys()['private'][$name];
        $text = '-----BEGIN ' . $key['type'] . "-----\n";
        foreach ($key['headers'] ?? [] as $header => $value) $text .= $header . ': ' . $value . "\n";
        if (!empty($key['headers'])) $text .= "\n";
        return $text . chunk_split($key['body'], 64, "\n") . '-----END ' . $key['type'] . "-----\n";
    }

    /** An authorized_keys line. */
    public static function hostKey(string $name): string
    {
        return self::keys()['host'][$name];
    }
}
