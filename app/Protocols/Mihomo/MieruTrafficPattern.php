<?php

namespace App\Protocols\Mihomo;

/**
 * Mieru's traffic-pattern option: Base64 of the TrafficPattern protobuf, read
 * as github.com/enfein/mieru/v3/apis/trafficpattern Decode and Validate read
 * it. Mihomo rejects the whole profile when either fails.
 */
class MieruTrafficPattern
{
    /** Message => field number => [name, kind], from mieru's appctl base.proto. */
    private const SCHEMA = [
        'TrafficPattern' => [1 => ['seed', 'int32'], 2 => ['unlockAll', 'bool'], 3 => ['tcpFragment', 'TCPFragment'], 4 => ['nonce', 'NoncePattern'], 5 => ['padding', 'PaddingPattern'], 6 => ['lowEntropy', 'LowEntropyPattern']],
        'TCPFragment' => [1 => ['enable', 'bool'], 2 => ['maxSleepMs', 'int32']],
        'NoncePattern' => [1 => ['type', 'int32'], 2 => ['applyToAllUDPPacket', 'bool'], 3 => ['minLen', 'int32'], 4 => ['maxLen', 'int32'], 5 => ['customHexStrings', 'strings']],
        'PaddingPattern' => [1 => ['maxMiddlePaddingLen', 'int32'], 2 => ['maxEndPaddingLen', 'int32']],
        'LowEntropyPattern' => [1 => ['mode', 'int32'], 2 => ['maskRotation', 'int32']],
    ];

    /** Why Mieru rejects the value, or null when it decodes and validates. */
    public static function problem(string $encoded): ?string
    {
        // Go's base64.StdEncoding ignores line breaks but nothing else.
        $encoded = str_replace(["\r", "\n"], '', $encoded);
        $bytes = strlen($encoded) % 4 === 0 && strpbrk($encoded, " \t") === false ? base64_decode($encoded, true) : false;
        if ($bytes === false) return 'الگوی ترافیک باید همان رشتهٔ Base64 تولیدشده توسط mieru باشد.';
        $pattern = self::message('TrafficPattern', $bytes, []);
        if ($pattern === null) return 'الگوی ترافیک ناقص یا خراب است؛ آن را کامل و بدون تغییر از پیکربندی mieru کپی کنید.';
        $ranges = [['tcpFragment', 'maxSleepMs', 100], ['nonce', 'minLen', 12], ['nonce', 'maxLen', 12], ['padding', 'maxMiddlePaddingLen', 255], ['padding', 'maxEndPaddingLen', 255]];
        foreach ($ranges as [$message, $field, $max]) {
            $value = $pattern[$message][$field] ?? null;
            if ($value !== null && ($value < 0 || $value > $max)) return "مقدار {$message}.{$field} در الگوی ترافیک باید بین ۰ و {$max} باشد.";
        }
        $nonce = $pattern['nonce'] ?? [];
        if (isset($nonce['minLen'], $nonce['maxLen']) && $nonce['minLen'] > $nonce['maxLen']) return 'در الگوی ترافیک minLen از maxLen بزرگ‌تر است.';
        foreach ($nonce['customHexStrings'] ?? [] as $hex) {
            if (strlen($hex) % 2 !== 0 || ($hex !== '' && !ctype_xdigit($hex)) || strlen($hex) > 24) return 'رشته‌های customHexStrings در الگوی ترافیک باید Hex و حداکثر ۱۲ بایت باشند.';
        }
        $mode = $pattern['lowEntropy']['mode'] ?? null;
        $rotation = $pattern['lowEntropy']['maskRotation'] ?? null;
        if (($mode !== null && ($mode < 0 || $mode > 4)) || ($rotation !== null && !(($rotation >= 0 && $rotation <= 15) || ($rotation > 0 && $rotation <= 240 && $rotation % 16 === 0)))) {
            return 'حالت lowEntropy در الگوی ترافیک معتبر نیست.';
        }
        return null;
    }

    /**
     * Protobuf fields merged into $into, as proto.Unmarshal merges them, or
     * null when the encoding is invalid. A known field with an unexpected
     * wire type is an unknown field, which is skipped.
     */
    private static function message(string $type, string $data, array $into): ?array
    {
        $at = 0;
        $size = strlen($data);
        while ($at < $size) {
            $tag = self::varint($data, $at);
            $number = $tag === null ? 0 : ($tag >> 3) & 0x1fffffffffffffff;
            if ($number < 1 || $number > 0x1fffffff) return null;
            $wire = $tag & 7;
            if ($wire === 0) {
                $value = self::varint($data, $at);
                if ($value === null) return null;
            } elseif ($wire === 2) {
                $length = self::varint($data, $at);
                if ($length === null || $length < 0 || $length > $size - $at) return null;
                $value = (string)substr($data, $at, $length);
                $at += $length;
            } elseif ($wire === 1 || $wire === 5) {
                $at += $wire === 1 ? 8 : 4;
                if ($at > $size) return null;
                continue;
            } else {
                // Groups are never generated; wire types 6 and 7 are invalid.
                return null;
            }
            if (!isset(self::SCHEMA[$type][$number])) continue;
            [$name, $kind] = self::SCHEMA[$type][$number];
            if ($kind === 'strings' && $wire === 2) {
                // proto3 strings must be UTF-8.
                if (!preg_match('//u', $value)) return null;
                $into[$name][] = $value;
            } elseif (isset(self::SCHEMA[$kind]) && $wire === 2) {
                $nested = self::message($kind, $value, $into[$name] ?? []);
                if ($nested === null) return null;
                $into[$name] = $nested;
            } elseif (($kind === 'int32' || $kind === 'bool') && $wire === 0) {
                $low = $value & 0xffffffff;
                $into[$name] = $kind === 'bool' ? $value !== 0 : ($low >= 0x80000000 ? $low - 0x100000000 : $low);
            }
        }
        return $into;
    }

    /** protowire.ConsumeVarint as a 64-bit two's-complement int, or null. */
    private static function varint(string $data, int &$at): ?int
    {
        $value = 0;
        for ($shift = 0; $shift < 70; $shift += 7) {
            if ($at >= strlen($data)) return null;
            $byte = ord($data[$at++]);
            // The tenth byte holds a single bit.
            if ($shift === 63 && $byte > 1) return null;
            $value |= ($byte & 0x7f) << $shift;
            if ($byte < 0x80) return $value;
        }
        return null;
    }
}
