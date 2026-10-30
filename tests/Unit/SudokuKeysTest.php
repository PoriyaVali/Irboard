<?php

namespace Tests\Unit;

use App\Protocols\Mihomo\SudokuKeys;
use PHPUnit\Framework\TestCase;

class SudokuKeysTest extends TestCase
{
    // V2bX core/mihomo/server_test.go: testSudokuMaster and subscriber.
    private const MASTER = '1a1786c2533e7d5b5b1015d717ea8ce09165858dec91327c66bb958dcbd0050a';
    private const UUID = '00c68113-03c2-42cb-9f3c-976fe2b0eb94';

    /** The node derives the same split from the master and the UUID. */
    public function testUserKeysMatchV2bX(): void
    {
        $this->assertSame(
            'a0213c63cb3355827ca213074aa8c03b25d9931c423779fd7c8cf056f77a46077af5495f880a28d9de6d01d0cd41cca46c8cf170aa5ab97ee92ea536d455bf02',
            SudokuKeys::userKey(self::MASTER, self::UUID)
        );
        $this->assertSame(SudokuKeys::userKey(self::MASTER, self::UUID), SudokuKeys::userKey(strtoupper(self::MASTER), self::UUID));
        $this->assertNotSame(SudokuKeys::userKey(self::MASTER, self::UUID), SudokuKeys::userKey(self::MASTER, '5b0c5bb7-3c2f-4d0e-8a44-5f7b9a5f2e11'));
    }

    /** Every split sums to the master: r + k = x (mod the group order). */
    public function testSplitsSumToTheMaster(): void
    {
        $key = hex2bin(SudokuKeys::userKey(self::MASTER, self::UUID));
        $sum = sodium_crypto_core_ristretto255_scalar_add(substr($key, 0, 32), substr($key, 32));
        $this->assertSame(self::MASTER, bin2hex($sum));
    }

    /** The pure-PHP fallback computes what libsodium does. */
    public function testFallbackArithmeticAgrees(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $wide = random_bytes(64);
            $x = sodium_crypto_core_ristretto255_scalar_reduce(random_bytes(64));
            $this->assertSame(sodium_crypto_core_ristretto255_scalar_reduce($wide), \ParagonIE_Sodium_Core_Ristretto255::ristretto255_scalar_reduce($wide));
            $r = sodium_crypto_core_ristretto255_scalar_reduce($wide);
            $this->assertSame(sodium_crypto_core_ristretto255_scalar_sub($x, $r), \ParagonIE_Sodium_Core_Ristretto255::ristretto255_scalar_sub($x, $r));
        }
    }

    public function testGeneratedMastersAreValidAndDistinct(): void
    {
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $master = SudokuKeys::generate();
            $this->assertNull(SudokuKeys::problem($master), $master);
            $seen[$master] = true;
        }
        $this->assertCount(20, $seen);
    }

    public function testOnlyReducedNonzeroScalarsAreMasters(): void
    {
        $this->assertNull(SudokuKeys::problem(self::MASTER));
        foreach ([null, '', '11111111-2222-3333-4444-555555555555', substr(self::MASTER, 2), self::MASTER . '00', 'zz' . substr(self::MASTER, 2),
            str_repeat('0', 64), str_repeat('f', 64),
            // The group order itself, little-endian.
            'edd3f55c1a631258d69cf7a2def9de1400000000000000000000000000000010'] as $bad) {
            $this->assertNotNull(SudokuKeys::problem($bad), var_export($bad, true));
        }
    }
}
