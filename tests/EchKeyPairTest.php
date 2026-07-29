<?php

/**
 * Byte-level checks on Helper::generateEchKeyPair().
 *
 * ECH is serialised by hand, and every field is a length-prefixed binary blob
 * that no PHP-side code ever reads back - the panel stores it and a Go process
 * parses it days later. So a wrong offset produces a perfectly valid-looking
 * base64 string that only fails when a node boots, which is exactly how three
 * separate defects survived here unnoticed until 2026-07-29.
 *
 * These tests therefore re-implement the SAME parsers the consumers use, and
 * assert against those rather than against our own encoder:
 *
 *   - server key  -> sing-box common/tls/ech.go UnmarshalECHKeys():
 *                    repeat until empty { u16-len private_key ; u16-len config }
 *   - client list -> Go crypto/tls ech.go parseECHConfigList():
 *                    u16 outer length, which MUST equal len(data)-2
 *   - each config -> Go crypto/tls ech.go parseECHConfig():
 *                    u16 version, u16 length, then the contents
 *
 * The same value feeds both node types: AnyTLSController (V1) and
 * V2nodeController (V2) call this one helper, and the V2 API hands tls_settings
 * to the v2node backend verbatim. Keeping to the crypto/tls layout is what makes
 * one encoder correct for both.
 */

use App\Utils\Helper;
use PHPUnit\Framework\TestCase;

class EchKeyPairTest extends TestCase
{
    private const ECH_VERSION = 0xfe0d;
    private const OUTER_SNI = 'cover.example.com';

    private function pair(): array
    {
        return Helper::generateEchKeyPair(self::OUTER_SNI);
    }

    /** Mirrors sing-box UnmarshalECHKeys: private key FIRST, then the config. */
    public function testServerKeyParsesAsPrivateKeyThenConfig(): void
    {
        $raw = base64_decode($this->pair()['ech_key'], true);
        $this->assertNotFalse($raw, 'ech_key is not valid base64');

        $offset = 0;
        $entries = 0;
        while ($offset < strlen($raw)) {
            $privateKey = $this->readUint16Prefixed($raw, $offset, 'private_key');
            $config     = $this->readUint16Prefixed($raw, $offset, 'config');

            // X25519 scalar - the only length DHKEM(X25519, HKDF-SHA256) accepts.
            $this->assertSame(32, strlen($privateKey), 'private key must be 32 bytes');
            // Reading it as an ECHConfig proves the two were not swapped: the
            // version lands where a version belongs only in the right order.
            $this->assertSame(self::ECH_VERSION, unpack('n', substr($config, 0, 2))[1]);
            $entries++;
        }

        $this->assertSame(1, $entries, 'expected exactly one key entry, and no trailing bytes');
    }

    /** Go's parseECHConfigList validates the outer length against the buffer. */
    public function testClientConfigListCarriesItsOuterLength(): void
    {
        $raw = base64_decode($this->pair()['ech_config'], true);
        $this->assertNotFalse($raw, 'ech_config is not valid base64');
        $this->assertGreaterThan(2, strlen($raw));

        $declared = unpack('n', substr($raw, 0, 2))[1];
        $this->assertSame(
            strlen($raw) - 2,
            $declared,
            'outer length must equal len(data)-2, or Go returns errMalformedECHConfigList'
        );
    }

    /** The ECHConfig body, field by field, as Go's parseECHConfig reads it. */
    public function testConfigBodyMatchesTheDraftLayout(): void
    {
        $raw = base64_decode($this->pair()['ech_config'], true);
        $config = substr($raw, 2); // past the list's outer length

        $offset = 0;
        $version = $this->readUint16($config, $offset);
        $length  = $this->readUint16($config, $offset);
        $this->assertSame(self::ECH_VERSION, $version);
        $this->assertSame(strlen($config) - 4, $length, 'config length must cover the remainder');

        $offset += 1;                                   // config_id
        $kemId = $this->readUint16($config, $offset);
        $this->assertSame(0x0020, $kemId, 'DHKEM(X25519, HKDF-SHA256)');

        $publicKey = $this->readUint16Prefixed($config, $offset, 'public_key');
        $this->assertSame(32, strlen($publicKey));

        $suites = $this->readUint16Prefixed($config, $offset, 'cipher_suites');
        $this->assertSame(0, strlen($suites) % 4, 'each suite is {u16 kdf, u16 aead}');
        $this->assertGreaterThan(0, strlen($suites));

        $offset += 1;                                   // maximum_name_length
        $nameLen = ord($config[$offset]);
        $offset += 1;
        $publicName = substr($config, $offset, $nameLen);
        $offset += $nameLen;
        $this->assertSame(self::OUTER_SNI, $publicName, 'public_name is the cover domain');

        $extensions = $this->readUint16Prefixed($config, $offset, 'extensions');
        $this->assertSame('', $extensions);
        $this->assertSame(strlen($config), $offset, 'no trailing bytes');
    }

    /** The keypair must actually be a pair, not two unrelated blobs. */
    public function testPublicKeyInTheConfigDerivesFromThePrivateKey(): void
    {
        $pair = $this->pair();

        $rawKey = base64_decode($pair['ech_key'], true);
        $offset = 0;
        $privateKey = $this->readUint16Prefixed($rawKey, $offset, 'private_key');

        $rawList = base64_decode($pair['ech_config'], true);
        $config = substr($rawList, 2);
        // version(2) + length(2) + config_id(1) + kem_id(2) = 7, then the key.
        $offset = 7;
        $publicKey = $this->readUint16Prefixed($config, $offset, 'public_key');

        $this->assertSame(
            sodium_crypto_scalarmult_base($privateKey),
            $publicKey,
            'the config advertises a public key the stored private key cannot answer for'
        );
    }

    /** Two calls must not collide - config_id and keys are per node. */
    public function testEachCallProducesFreshKeyMaterial(): void
    {
        $this->assertNotSame($this->pair()['ech_key'], $this->pair()['ech_key']);
    }

    private function readUint16(string $buf, int &$offset): int
    {
        $this->assertGreaterThanOrEqual($offset + 2, strlen($buf), 'truncated uint16');
        $value = unpack('n', substr($buf, $offset, 2))[1];
        $offset += 2;
        return $value;
    }

    private function readUint16Prefixed(string $buf, int &$offset, string $what): string
    {
        $len = $this->readUint16($buf, $offset);
        $this->assertGreaterThanOrEqual($offset + $len, strlen($buf), "truncated {$what}");
        $value = substr($buf, $offset, $len);
        $offset += $len;
        return $value;
    }
}
