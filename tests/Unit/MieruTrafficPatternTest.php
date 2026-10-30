<?php

namespace Tests\Unit;

use App\Protocols\Mihomo\MieruTrafficPattern;
use PHPUnit\Framework\TestCase;

class MieruTrafficPatternTest extends TestCase
{
    public function testVerdictsMatchMierusDecoderAndValidator(): void
    {
        // Encoded and judged by github.com/enfein/mieru/v3 v3.38.0 apis/trafficpattern.
        $vectors = [
            ['full', 'CCoQARoECAEQZCImCAEYBiAMKgQwMGZmKhgwMTAyMDMwNDA1MDYwNzA4MDkwYTBiMGMqBQj/ARAAMgQIAhAg', true],
            ['negative seed', 'CPn//////////wE=', true],
            ['unknown field', 'CCoQARoECAEQZCImCAEYBiAMKgQwMGZmKhgwMTAyMDMwNDA1MDYwNzA4MDkwYTBiMGMqBQj/ARAAMgQIAhAg+AEF', true],
            ['line break', "GgIQZA==\n", true],
            ['sleep above 100', 'GgIQZQ==', false],
            ['nonce longer than 12', 'IgIYDQ==', false],
            ['nonce min above max', 'IgQYCCAE', false],
            ['odd hex nonce', 'IgUqA2FiYw==', false],
            ['hex nonce over 12 bytes', 'IhwqGjAxMDIwMzA0MDUwNjA3MDgwOTBhMGIwYzBk', false],
            ['padding above 255', 'KgMQgAI=', false],
            ['negative padding', 'KgsI////////////AQ==', false],
            ['low-entropy mode 5', 'MgIIBQ==', false],
            ['mask rotation 17', 'MgIQEQ==', false],
            ['truncated', 'CCoQARoECAEQZCImCAEYBiAMKgQwMGZmKhgwMTAyMDMwNDA1MDYwNzA4MDkwYTBiMGMqBQj/ARAAMgQI', false],
            ['unpadded', 'GgIQZQ', false],
            ['JSON', '{"seed":42}', false],
        ];
        foreach ($vectors as [$name, $value, $valid]) $this->assertSame($valid, MieruTrafficPattern::problem($value) === null, $name);
    }
}
