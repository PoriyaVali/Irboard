<?php

namespace Tests\Unit;

use App\Protocols\Mihomo\SshKeys;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\SshKeyFixtures as Keys;

/**
 * Each verdict matches golang.org/x/crypto/ssh v0.33.0, which Mihomo v1.19.33
 * uses, and v0.56.0, whose checks are stricter. The policy rejections are
 * marked: Mihomo would load those keys, but the panel cannot verify them or
 * current servers refuse them.
 */
class SshKeysTest extends TestCase
{
    public function testKeysMihomoLoadsAreAccepted(): void
    {
        foreach (['openssh-ed25519', 'openssh-rsa', 'openssh-ecdsa256', 'pem-rsa', 'pem-rsa-no-crt', 'pem-ec256', 'pkcs8-ed25519', 'pkcs8-ec384'] as $name) {
            $this->assertNull(SshKeys::privateKeyProblem(Keys::privateKey($name), ''), $name);
        }
        $this->assertNull(SshKeys::privateKeyProblem(Keys::privateKey('pem-rsa-aes256'), 'fixture-passphrase'));
        // Go's PEM decoder skips surrounding text and accepts Windows line endings.
        $this->assertNull(SshKeys::privateKeyProblem("my key:\r\n" . str_replace("\n", "\r\n", Keys::privateKey('openssh-ed25519')), ''));
    }

    public function testKeysThatWouldBreakEveryProfileAreRejectedOnTheirField(): void
    {
        $cases = [
            ['openssh-ed25519', 'secret', 'private_key_passphrase'],
            ['pem-rsa-aes256', '', 'private_key_passphrase'],
            ['pem-rsa-aes256', 'wrong', 'private_key_passphrase'],
            // Inconsistent CRT values (Go 1.24+), and an exponent above newer x/crypto's 24-bit cap.
            ['pem-rsa-bad-dp', '', 'private_key'], ['pem-rsa-bad-qinv', '', 'private_key'], ['pem-rsa-swapped-primes', '', 'private_key'],
            ['openssh-rsa-e25bits', '', 'private_key'],
            // x/crypto does not sign with P-224 or X25519 keys.
            ['pem-ec224', '', 'private_key'], ['pkcs8-x25519', '', 'private_key'],
            // Policy: bcrypt_pbkdf is unavailable to PHP, and DSA is obsolete.
            ['openssh-ed25519-encrypted', 'fixture-passphrase', 'private_key'], ['pem-dsa', '', 'private_key'],
        ];
        foreach ($cases as [$name, $passphrase, $field]) {
            $this->assertSame($field, SshKeys::privateKeyProblem(Keys::privateKey($name), $passphrase)[0] ?? null, $name);
        }
        $key = Keys::privateKey('openssh-ed25519');
        $values = ['truncated' => substr($key, 0, (int)(strlen($key) * 0.6)), 'client path' => '.ssh/id_ed25519',
            'public key' => Keys::hostKey('ed25519'), 'PuTTY key' => "PuTTY-User-Key-File-3: ssh-ed25519\nEncryption: none\n"];
        foreach ($values as $label => $value) $this->assertSame('private_key', SshKeys::privateKeyProblem($value, '')[0] ?? null, $label);
    }

    public function testHostKeysFollowParseAuthorizedKey(): void
    {
        $ed25519 = Keys::hostKey('ed25519');
        [$type, $blob] = explode(' ', $ed25519);
        $accepted = [$ed25519, $ed25519 . ' root@node', 'node.example.com,192.0.2.1 ' . $ed25519, "# comment\n" . $ed25519,
            $ed25519 . "\r\n", $ed25519 . "\u{a0}", Keys::hostKey('rsa'), Keys::hostKey('ecdsa256')];
        foreach ($accepted as $entry) $this->assertNotNull(SshKeys::hostKeyType($entry), $entry);
        $rejected = ['ssh-rsa ' . $blob, $blob, $type . ' ' . substr($blob, 0, -8), $ed25519 . "\0", 'SHA256:2ZlRt7qXAvE3DNk7mq0Vw4m0kdz3jXZ5x0Qn5UeTq3c', '',
            Keys::hostKey('ecdsa-curve-mismatch'), Keys::hostKey('dsa'), Keys::hostKey('sk-ed25519'), Keys::hostKey('cert')];
        foreach ($rejected as $entry) $this->assertNull(SshKeys::hostKeyType($entry), $entry);
    }

    public function testPinnedKeysChooseTheHostKeyAlgorithms(): void
    {
        // Without a list x/crypto prefers ECDSA, so a pinned Ed25519 key alone would never match a stock OpenSSH server.
        $this->assertSame(['ssh-ed25519', 'rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'], SshKeys::hostKeyAlgorithms([Keys::hostKey('ed25519'), Keys::hostKey('rsa'), 'invalid', Keys::hostKey('ed25519')]));
        $this->assertSame(['ecdsa-sha2-nistp256'], SshKeys::hostKeyAlgorithms(['node.example.com ' . Keys::hostKey('ecdsa256')]));
        $this->assertSame([], SshKeys::hostKeyAlgorithms([]));
    }
}
