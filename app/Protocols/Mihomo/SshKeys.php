<?php

namespace App\Protocols\Mihomo;

/**
 * SSH key checks that follow golang.org/x/crypto/ssh and Go's crypto/x509 and
 * crypto/rsa, which Mihomo clients use to load SSH nodes. Mihomo rejects a
 * whole profile when one node fails to load, so a key it cannot parse would
 * break every subscriber's profile. Where releases differ, the stricter rule
 * applies, because subscribers run many client versions.
 */
class SshKeys
{
    private const DAMAGED = 'کلید خصوصی ناقص یا خراب است؛ آن را کامل و بدون تغییر از خط BEGIN تا END جای‌گذاری کنید.';
    private const UNSUPPORTED = 'این نوع کلید در SSH پشتیبانی نمی‌شود؛ کلید Ed25519، ECDSA (P-256، P-384 یا P-521) یا RSA وارد کنید.';
    private const PLAIN = 'این کلید رمز ندارد؛ عبارت عبور را خالی بگذارید، وگرنه کلاینت پروفایل را رد می‌کند.';
    private const WRONG = 'عبارت عبور با این کلید خصوصی مطابقت ندارد یا کلید خراب است.';

    /** Host-key algorithms x/crypto offers for keys a server can present. */
    public const HOST_KEY_ALGORITHMS = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'];

    /** OpenSSH curve name => field size in bytes, group order, OID. */
    private const CURVES = [
        'nistp256' => [32, 'ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551', "\x2a\x86\x48\xce\x3d\x03\x01\x07"],
        'nistp384' => [48, 'ffffffffffffffffffffffffffffffffffffffffffffffffc7634d81f4372ddf581a0db248b0a77aecec196accc52973', "\x2b\x81\x04\x00\x22"],
        'nistp521' => [66, '01fffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffa51868783bf2f966b7fcc0148f709a5d03bb5c9b8899c47aebb6fb71e91386409', "\x2b\x81\x04\x00\x23"],
    ];

    /**
     * The field and message for the first problem with a private key and its
     * passphrase, or null when Mihomo can load the pair.
     */
    public static function privateKeyProblem(string $key, string $passphrase): ?array
    {
        // Mihomo reads a value without this marker as a file on the subscriber's device.
        if (strpos($key, 'PRIVATE KEY') === false) {
            if (strpos($key, 'PuTTY-User-Key-File') !== false) return ['private_key', 'کلید PuTTY (.ppk) در کلاینت پشتیبانی نمی‌شود؛ در PuTTYgen از منوی Conversions گزینهٔ Export OpenSSH key را بزنید و متن آن را وارد کنید.'];
            if (preg_match('/^\s*(ssh-|ecdsa-|sk-)|PUBLIC KEY/', $key)) return ['private_key', 'این کلید عمومی است؛ کلید خصوصی متناظر را وارد کنید و کلید عمومی را در authorized_keys سرور بگذارید.'];
            return ['private_key', 'متن کامل کلید خصوصی را از خط BEGIN تا END جای‌گذاری کنید. مقدار بدون «PRIVATE KEY» در کلاینت مسیر فایل حساب می‌شود که روی دستگاه کاربران وجود ندارد.'];
        }
        $block = self::pemBlock($key);
        if ($block === null) return ['private_key', self::DAMAGED];
        [$type, $headers, $der] = $block;
        if ($type === 'OPENSSH PRIVATE KEY') {
            $kind = self::openSSHKey($der);
            if ($kind === 'encrypted') {
                // Checking it needs bcrypt_pbkdf, which PHP lacks. The passphrase
                // ships to every subscriber next to the key anyway.
                return ['private_key', 'پنل نمی‌تواند عبارت عبور کلید OpenSSH رمزدار را بررسی کند؛ این عبارت همراه کلید برای همهٔ کاربران ارسال می‌شود و امنیتی اضافه نمی‌کند. روی یک نسخه از کلید دستور ssh-keygen -p -N "" -f <فایل> را اجرا کنید و کلید بدون رمز را وارد کنید.'];
            }
            if ($kind === null) return ['private_key', self::DAMAGED];
            if ($kind !== 'ok') return ['private_key', self::UNSUPPORTED];
            return $passphrase === '' ? null : ['private_key_passphrase', self::PLAIN];
        }
        if ($type === 'ENCRYPTED PRIVATE KEY') return ['private_key', 'کلید PKCS#8 رمزدار در کلاینت پشتیبانی نمی‌شود؛ روی یک نسخه از کلید دستور ssh-keygen -p -N "" -f <فایل> را اجرا کنید و کلید بدون رمز را وارد کنید.'];
        if ($type === 'DSA PRIVATE KEY') return ['private_key', 'کلید DSA منسوخ است و سرورهای جدید OpenSSH آن را نمی‌پذیرند؛ یک کلید Ed25519 بسازید.'];
        if (!in_array($type, ['RSA PRIVATE KEY', 'EC PRIVATE KEY', 'PRIVATE KEY'], true)) return ['private_key', self::UNSUPPORTED];
        $encrypted = strpos($headers['Proc-Type'] ?? '', 'ENCRYPTED') !== false;
        if ($encrypted && !isset($headers['DEK-Info'])) return ['private_key', self::DAMAGED];
        if (!$encrypted) {
            if ($passphrase !== '') return ['private_key_passphrase', self::PLAIN];
            $kind = self::derKey($type, $der);
            return $kind === 'ok' ? null : ['private_key', $kind === null ? self::DAMAGED : self::UNSUPPORTED];
        }
        if ($passphrase === '') return ['private_key_passphrase', 'این کلید رمزدار است؛ عبارت عبور آن را وارد کنید.'];
        if ($type === 'PRIVATE KEY') return ['private_key', self::UNSUPPORTED];
        $plain = self::decrypt($headers['DEK-Info'], $der, $passphrase);
        if ($plain === false) return ['private_key', 'پنل این روش رمزگذاری کلید را پشتیبانی نمی‌کند؛ رمز کلید را بردارید یا آن را با AES رمزگذاری کنید.'];
        $kind = $plain === null ? null : self::derKey($type, $plain);
        if ($kind === null) return ['private_key_passphrase', self::WRONG];
        return $kind === 'ok' ? null : ['private_key', self::UNSUPPORTED];
    }

    /**
     * The type of the key ssh.ParseAuthorizedKey takes from a host-key entry,
     * or null unless it parses as a key a server can present.
     */
    public static function hostKeyType(string $entry): ?string
    {
        foreach (explode("\n", $entry) as $line) {
            $line = self::trimSpace(explode("\r", $line, 2)[0]);
            if ($line === '' || $line[0] === '#') continue;
            $i = strcspn($line, " \t");
            if ($i === strlen($line)) continue;
            $type = substr($line, 0, $i);
            if (self::authorizedKey($type, substr($line, $i))) return $type;
            // An options field, or a known_hosts host list, may come first.
            $inQuote = false;
            for ($i = 0, $n = strlen($line); $i < $n; $i++) {
                if (!$inQuote && ($line[$i] === ' ' || $line[$i] === "\t")) break;
                if ($line[$i] === '"' && ($i === 0 || $line[$i - 1] !== '\\')) $inQuote = !$inQuote;
            }
            while ($i < $n && ($line[$i] === ' ' || $line[$i] === "\t")) $i++;
            if ($i === $n) continue;
            $rest = substr($line, $i);
            $j = strcspn($rest, " \t");
            $type = substr($rest, 0, $j);
            if ($j !== strlen($rest) && self::authorizedKey($type, substr($rest, $j))) return $type;
        }
        return null;
    }

    /**
     * The host-key algorithms that make a server present one of the pinned
     * keys. Without them x/crypto prefers ECDSA, so a server that also has an
     * ECDSA key presents it and a pinned Ed25519 or RSA key never matches.
     */
    public static function hostKeyAlgorithms(array $entries): array
    {
        $algorithms = [];
        foreach ($entries as $entry) {
            $type = is_string($entry) ? self::hostKeyType($entry) : null;
            foreach ($type === 'ssh-rsa' ? ['rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'] : (array)$type as $algorithm) $algorithms[$algorithm] = true;
        }
        return array_keys($algorithms);
    }

    /** Newer x/crypto also requires the declared type to match the key blob. */
    private static function authorizedKey(string $type, string $in): bool
    {
        $in = self::trimSpace($in);
        $blob = self::base64(substr($in, 0, strcspn($in, " \t")));
        return $blob !== null && self::publicKey($blob) === $type;
    }

    /** The type of an SSH wire-format public key that can be a server's host key, or null. */
    private static function publicKey(string $blob): ?string
    {
        $at = 0;
        $type = self::string($blob, $at);
        switch ($type) {
            case 'ssh-ed25519':
                $ok = strlen((string)self::string($blob, $at)) === 32;
                break;
            case 'ssh-rsa':
                $e = self::positive(self::string($blob, $at));
                $n = self::positive(self::string($blob, $at));
                $ok = $e !== null && strlen($e) <= 3 && self::small($e) >= 3 && self::small($e) % 2 === 1 && $n !== null && strlen($n) <= 2048;
                break;
            case 'ecdsa-sha2-nistp256': case 'ecdsa-sha2-nistp384': case 'ecdsa-sha2-nistp521':
                $curve = (string)self::string($blob, $at);
                $ok = $type === 'ecdsa-sha2-' . $curve && self::curvePoint($curve, self::string($blob, $at));
                break;
            default:
                // DSA is obsolete; certificates and security keys are never a server's plain host key.
                return null;
        }
        return $ok && $at === strlen($blob) ? $type : null;
    }

    /** x/crypto's parseOpenSSHPrivateKey: 'ok', 'encrypted', 'unsupported', or null when damaged. */
    private static function openSSHKey(string $data): ?string
    {
        if (strncmp($data, "openssh-key-v1\0", 15) !== 0) return null;
        $at = 15;
        $cipher = self::string($data, $at); $kdf = self::string($data, $at); $kdfOptions = self::string($data, $at);
        $count = self::uint32($data, $at);
        $public = self::string($data, $at); $private = self::string($data, $at);
        if ($private === null || $count !== 1) return null;
        if ($cipher !== 'none' || $kdf !== 'none') return 'encrypted';
        if ($kdfOptions !== '') return null;
        $at = 0;
        $check = self::uint32($private, $at);
        if ($check === null || $check !== self::uint32($private, $at)) return null;
        switch (self::string($private, $at)) {
            case 'ssh-rsa':
                $values = [];
                for ($i = 0; $i < 6; $i++) $values[] = self::string($private, $at);
                if (in_array(null, $values, true)) return null;
                // x/crypto ignores iqmp. Newer releases cap the exponent at 24 bits.
                [$n, $e, $d, , $p, $q] = array_map([self::class, 'positive'], $values);
                if ($e === null || strlen($e) > 3 || !self::rsa($n, self::small($e), $d, $p, $q, null)) return null;
                break;
            case 'ssh-ed25519':
                $public = self::string($private, $at);
                if ($public === null || strlen((string)self::string($private, $at)) !== 64) return null;
                break;
            case 'ecdsa-sha2-nistp256': case 'ecdsa-sha2-nistp384': case 'ecdsa-sha2-nistp521':
                $curve = self::string($private, $at); $point = self::string($private, $at); $scalar = self::string($private, $at);
                if ($scalar === null || !self::ecdsaPair((string)$curve, (string)$point, $scalar)) return null;
                break;
            default:
                return 'unsupported';
        }
        if (self::string($private, $at) === null) return null; // comment
        for ($i = 1; $at < strlen($private); $i++, $at++) if (ord($private[$at]) !== $i) return null;
        return 'ok';
    }

    /** The private scalar must produce the key's public point, as x/crypto checks. */
    private static function ecdsaPair(string $curve, string $point, string $scalar): bool
    {
        if (!self::curvePoint($curve, $point)) return false;
        [$size, $order, $oid] = self::CURVES[$curve];
        $scalar = self::positive($scalar);
        if ($scalar === null || strlen($scalar) > $size) return false;
        $scalar = str_pad($scalar, $size, "\0", STR_PAD_LEFT);
        if (strcmp($scalar, hex2bin($order)) >= 0) return false;
        // SEC 1 ECPrivateKey without the public key; OpenSSL derives it.
        $sec1 = self::der("\x30", "\x02\x01\x01" . self::der("\x04", $scalar) . self::der("\xa0", self::der("\x06", $oid)));
        $key = @openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($sec1), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
        $ec = $key ? (openssl_pkey_get_details($key)['ec'] ?? []) : [];
        return isset($ec['x'], $ec['y']) && "\x04" . str_pad($ec['x'], $size, "\0", STR_PAD_LEFT) . str_pad($ec['y'], $size, "\0", STR_PAD_LEFT) === $point;
    }

    /** An uncompressed point on the named curve, as elliptic.Unmarshal requires. */
    private static function curvePoint(string $curve, ?string $point): bool
    {
        $size = self::CURVES[$curve][0] ?? null;
        if ($size === null || $point === null || strlen($point) !== 1 + 2 * $size || $point[0] !== "\x04") return false;
        $algorithm = self::der("\x30", self::der("\x06", "\x2a\x86\x48\xce\x3d\x02\x01") . self::der("\x06", self::CURVES[$curve][2]));
        $spki = self::der("\x30", $algorithm . self::der("\x03", "\x00" . $point));
        return @openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n") !== false;
    }

    /** ParseRawPrivateKey for a PEM type: 'ok', 'unsupported', or null when damaged. */
    private static function derKey(string $type, string $der): ?string
    {
        if ($type === 'RSA PRIVATE KEY') return self::pkcs1($der) ? 'ok' : null;
        if ($type === 'EC PRIVATE KEY') return self::sec1($der, null);
        return self::pkcs8($der);
    }

    /** x509.ParsePKCS1PrivateKey for two-prime keys. */
    private static function pkcs1(string $der): bool
    {
        $at = 0;
        $body = self::element($der, $at, "\x30");
        if ($body === null || $at !== strlen($der)) return false;
        $at = 0;
        $version = self::int64(self::integer($body, $at));
        $n = self::positive(self::integer($body, $at));
        $e = self::int64(self::integer($body, $at));
        $d = self::positive(self::integer($body, $at));
        $p = self::positive(self::integer($body, $at));
        $q = self::positive(self::integer($body, $at));
        $crt = [];
        while (count($crt) < 3 && ($body[$at] ?? '') === "\x02") $crt[] = self::positive(self::integer($body, $at));
        // Multi-prime keys are not accepted.
        if ($version === null || $version > 1 || $e === null || in_array(null, $crt, true) || $at !== strlen($body)) return false;
        return self::rsa($n, $e, $d, $p, $q, count($crt) === 3 ? $crt : null);
    }

    /** x509.parseECPrivateKey; $curve is the PKCS #8 parameter OID, if any. */
    private static function sec1(string $der, ?string $curve): ?string
    {
        $at = 0;
        $body = self::element($der, $at, "\x30");
        if ($body === null) return null;
        $at = 0;
        $version = self::int64(self::integer($body, $at));
        $scalar = self::element($body, $at, "\x04");
        if ($version !== 1 || $scalar === null) return null;
        foreach (["\xa0" => "\x06", "\xa1" => "\x03"] as $tag => $inner) {
            if (($body[$at] ?? '') !== $tag) continue;
            $field = self::element($body, $at, $tag);
            $i = 0;
            $value = $field === null ? null : self::element($field, $i, $inner);
            if ($value === null || $i !== strlen($field) || ($inner === "\x03" && !self::bitString($value))) return null;
            if ($inner === "\x06" && $curve === null) $curve = $value;
        }
        if ($at !== strlen($body)) return null;
        // Go parses P-224 keys, but x/crypto refuses to sign with them.
        if ($curve === "\x2b\x81\x04\x00\x21") return 'unsupported';
        foreach (self::CURVES as [$size, $order, $oid]) {
            if ($oid !== $curve) continue;
            while (strlen($scalar) > $size) {
                if ($scalar[0] !== "\0") return null;
                $scalar = substr($scalar, 1);
            }
            $scalar = str_pad($scalar, $size, "\0", STR_PAD_LEFT);
            return $scalar !== str_repeat("\0", $size) && strcmp($scalar, hex2bin($order)) < 0 ? 'ok' : null;
        }
        return null;
    }

    /** x509.ParsePKCS8PrivateKey for the key types x/crypto signs with. */
    private static function pkcs8(string $der): ?string
    {
        $at = 0;
        $body = self::element($der, $at, "\x30");
        if ($body === null) return null;
        $at = 0;
        $version = self::int64(self::integer($body, $at));
        $algorithm = self::element($body, $at, "\x30");
        // Attributes or a public key may follow the key; Go ignores them.
        $key = self::element($body, $at, "\x04");
        if ($version === null || $algorithm === null || $key === null) return null;
        $at = 0;
        $oid = self::element($algorithm, $at, "\x06");
        $start = $at;
        if ($oid === null || ($at < strlen($algorithm) && self::element($algorithm, $at, null) === null)) return null;
        $parameters = substr($algorithm, $start, $at - $start);
        switch ($oid) {
            case "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01": // rsaEncryption
                return self::pkcs1($key) ? 'ok' : null;
            case "\x2a\x86\x48\xce\x3d\x02\x01": // id-ecPublicKey
                $at = 0;
                return self::sec1($key, ($parameters[0] ?? '') === "\x06" ? self::element($parameters, $at, "\x06") : null);
            case "\x2b\x65\x70": // Ed25519
                $at = 0;
                $seed = $parameters === '' ? self::element($key, $at, "\x04") : null;
                return $seed !== null && strlen($seed) === 32 ? 'ok' : null;
        }
        return 'unsupported';
    }

    /**
     * crypto/rsa's checks for two-prime keys (crypto/internal/fips140/rsa
     * checkPrivateKey). Without PKCS #1's CRT values Go derives dP and dQ from
     * d, and x/crypto ignores the iqmp of OpenSSH keys. The q⁻¹ mod p that Go
     * then computes is wrong only when p is not prime, which the other checks
     * already make implausible for a damaged key, so it is not recomputed.
     */
    private static function rsa(?string $n, int $e, ?string $d, ?string $p, ?string $q, ?array $crt): bool
    {
        if ($n === null || $d === null || $p === null || $q === null) return false;
        [$n, $d, $p, $q] = [self::nat($n), self::nat($d), self::nat($p), self::nat($q)];
        $one = [1];
        $bits = self::bits($n);
        // Newer x/crypto caps OpenSSH keys at OpenSSH's own 16384-bit limit.
        if ($bits > 16384 || self::bits($p) > 8192 || self::bits($q) > 8192) return false;
        if ($e < 3 || $e % 2 === 0 || $e > 0x7fffffff) return false;
        if (!($n[0] & 1) || !($p[0] & 1) || !($q[0] & 1) || self::cmp($p, [3]) < 0 || self::cmp($q, [3]) < 0) return false;
        if (self::cmp($d, $n) >= 0 || self::cmp($p, $n) >= 0 || self::cmp($q, $n) >= 0) return false;
        if (self::mod(self::mul($p, $q), $n) !== []) return false;
        $p1 = self::sub($p, $one);
        $q1 = self::sub($q, $one);
        $dp = self::mod($d, $p1);
        $dq = self::mod($d, $q1);
        if ($crt !== null) {
            [$cp, $cq, $qinv] = array_map([self::class, 'nat'], $crt);
            if ($cp !== $dp || $cq !== $dq || self::cmp($qinv, $p) >= 0) return false;
            if (self::mod(self::mul(self::mod($q, $p), $qinv), $p) !== $one) return false;
        }
        $e = self::nat(pack('N', $e));
        if (self::mod(self::mul($e, $dp), $p1) !== $one || self::mod(self::mul($e, $dq), $q1) !== $one) return false;
        $order = self::cmp($p, $q);
        if ($order === 0) return false;
        return self::bits($order > 0 ? self::sub($p, $q) : self::sub($q, $p)) > intdiv($bits, 2) - 100
            && self::bits($d) > intdiv($bits, 2);
    }

    /** x509.DecryptPEMBlock: the plaintext, null for a wrong passphrase or damaged block, false when PHP lacks the cipher. */
    private static function decrypt(string $dekInfo, string $data, string $passphrase)
    {
        $ciphers = ['DES-CBC' => ['des-cbc', 8, 8], 'DES-EDE3-CBC' => ['des-ede3-cbc', 24, 8], 'AES-128-CBC' => ['aes-128-cbc', 16, 16], 'AES-192-CBC' => ['aes-192-cbc', 24, 16], 'AES-256-CBC' => ['aes-256-cbc', 32, 16]];
        $comma = strpos($dekInfo, ',');
        $cipher = $comma === false ? null : ($ciphers[substr($dekInfo, 0, $comma)] ?? null);
        if ($cipher === null) return null;
        [$method, $keySize, $blockSize] = $cipher;
        $iv = (string)substr($dekInfo, $comma + 1);
        if (strlen($iv) !== 2 * $blockSize || !ctype_xdigit($iv) || $data === '' || strlen($data) % $blockSize !== 0) return null;
        $iv = hex2bin($iv);
        // OpenSSL's EVP_BytesToKey with MD5; the salt is the first 8 bytes of the IV.
        $key = $digest = '';
        while (strlen($key) < $keySize) $key .= $digest = md5($digest . $passphrase . substr($iv, 0, 8), true);
        $plain = @openssl_decrypt($data, $method, substr($key, 0, $keySize), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        // DES-CBC needs OpenSSL's legacy provider.
        if ($plain === false) return false;
        $pad = ord($plain[strlen($plain) - 1]);
        if ($pad === 0 || $pad > $blockSize || substr($plain, -$pad) !== str_repeat(chr($pad), $pad)) return null;
        return substr($plain, 0, -$pad);
    }

    /** The first PEM block as Go 1.27's pem.Decode finds it: [type, headers, bytes], or null. */
    private static function pemBlock(string $data): ?array
    {
        $rest = $data;
        $skip = 0;
        while (true) {
            if ($skip < 0 || $skip > strlen($rest)) return null;
            $rest = (string)substr($rest, $skip);
            $end = strpos($rest, "\n-----END ");
            if ($end === false) return null;
            $skip = $end + 10;
            $begin = strrpos(substr($rest, 0, $end), '-----BEGIN ');
            if ($begin === false || ($begin > 0 && $rest[$begin - 1] !== "\n")) continue;
            $rest = (string)substr($rest, $begin + 11);
            $end -= $begin + 11;
            $skip -= $begin + 11;
            [$type, $rest, $consumed] = self::line($rest);
            $end -= $consumed;
            $skip -= $consumed;
            if (substr($type, -5) !== '-----') continue;
            $type = substr($type, 0, -5);
            $headers = [];
            while (true) {
                if ($rest === '') return null;
                [$line, $next, $consumed] = self::line($rest);
                $colon = strpos($line, ':');
                if ($colon === false) break;
                $headers[self::trimSpace(substr($line, 0, $colon))] = self::trimSpace((string)substr($line, $colon + 1));
                $rest = $next;
                $end -= $consumed;
                $skip -= $consumed;
            }
            if ($headers && $end < 0) continue;
            $trailer = (string)substr($rest, $skip);
            $length = strlen($type) + 5;
            if (strlen($trailer) < $length) continue;
            $after = (string)substr($trailer, $length);
            if (substr($trailer, 0, $length) !== $type . '-----' || self::line($after)[0] !== '') continue;
            $bytes = '';
            if ($end > 0) {
                $bytes = self::base64(str_replace([' ', "\t"], '', substr($rest, 0, $end)), true);
                if ($bytes === null) continue;
            }
            return [$type, $headers, $bytes];
        }
    }

    /** Go pem's getLine: the line without its break and trailing blanks, the rest, and the bytes consumed. */
    private static function line(string $data): array
    {
        $i = strpos($data, "\n");
        if ($i === false) {
            $i = $next = strlen($data);
        } else {
            $next = $i + 1;
            if ($i > 0 && $data[$i - 1] === "\r") $i--;
        }
        return [rtrim(substr($data, 0, $i), " \t"), (string)substr($data, $next), $next];
    }

    /** bytes.TrimSpace: ASCII and Unicode white space, but not NUL as PHP's trim(). */
    private static function trimSpace(string $text): string
    {
        $space = '[\t\n\x0b\f\r \x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]';
        return preg_replace("/^$space+|$space+\\z/u", '', $text) ?? trim($text, "\t\n\x0b\f\r ");
    }

    /** Go's base64.StdEncoding: padded, ignoring only the line breaks of PEM bodies. */
    private static function base64(string $encoded, bool $lines = false): ?string
    {
        if ($lines) $encoded = str_replace(["\r", "\n"], '', $encoded);
        if (strlen($encoded) % 4 !== 0 || strpbrk($encoded, " \t\r\n") !== false) return null;
        $decoded = base64_decode($encoded, true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * One DER element at $at as Go's encoding/asn1 reads it: its contents, or
     * null when the encoding is invalid or the tag differs. A null tag accepts any.
     */
    private static function element(string $der, int &$at, ?string $tag): ?string
    {
        $size = strlen($der);
        if ($at + 2 > $size || ($tag !== null && $der[$at] !== $tag) || (ord($der[$at]) & 0x1f) === 0x1f) return null;
        $length = ord($der[$at + 1]);
        $at += 2;
        if ($length & 0x80) {
            $count = $length & 0x7f;
            // DER lengths are definite and minimal.
            if ($count === 0 || $count > 4 || $at + $count > $size || $der[$at] === "\0") return null;
            for ($length = 0, $end = $at + $count; $at < $end; $at++) $length = $length << 8 | ord($der[$at]);
            if ($length < 0x80) return null;
        }
        if ($length > $size - $at) return null;
        $content = (string)substr($der, $at, $length);
        $at += $length;
        return $content;
    }

    /** A DER INTEGER's two's-complement contents, minimally encoded as Go requires. */
    private static function integer(string $der, int &$at): ?string
    {
        $value = self::element($der, $at, "\x02");
        if ($value === null || $value === '') return null;
        if (strlen($value) > 1 && (($value[0] === "\0" && ord($value[1]) < 0x80) || ($value[0] === "\xff" && ord($value[1]) >= 0x80))) return null;
        return $value;
    }

    /** A Go int (int64) field from an INTEGER's contents, or null when it does not fit. */
    private static function int64(?string $value): ?int
    {
        if ($value === null || strlen($value) > 8) return null;
        return unpack('J', str_pad($value, 8, ord($value[0]) >= 0x80 ? "\xff" : "\0", STR_PAD_LEFT))[1];
    }

    /** A positive two's-complement integer's magnitude, or null for zero, negative or missing values. */
    private static function positive(?string $value): ?string
    {
        if ($value === null || $value === '' || ord($value[0]) >= 0x80) return null;
        $value = ltrim($value, "\0");
        return $value === '' ? null : $value;
    }

    private static function small(string $magnitude): int
    {
        return (int)hexdec(bin2hex($magnitude));
    }

    private static function bitString(string $bits): bool
    {
        if ($bits === '') return false;
        $unused = ord($bits[0]);
        return $unused < 8 && (strlen($bits) > 1 || $unused === 0) && (ord($bits[strlen($bits) - 1]) & ((1 << $unused) - 1)) === 0;
    }

    // Unsigned integers as little-endian 24-bit limbs without leading zeros,
    // since PHP may lack the GMP and BCMath extensions.

    private static function nat(string $magnitude): array
    {
        $magnitude = ltrim($magnitude, "\0");
        $magnitude = str_repeat("\0", (3 - strlen($magnitude) % 3) % 3) . $magnitude;
        $limbs = [];
        for ($i = strlen($magnitude) - 3; $i >= 0; $i -= 3) {
            $limbs[] = ord($magnitude[$i]) << 16 | ord($magnitude[$i + 1]) << 8 | ord($magnitude[$i + 2]);
        }
        return $limbs;
    }

    private static function normal(array $a): array
    {
        while ($a && end($a) === 0) array_pop($a);
        return $a;
    }

    private static function bits(array $a): int
    {
        return $a ? (count($a) - 1) * 24 + strlen(decbin(end($a))) : 0;
    }

    private static function cmp(array $a, array $b): int
    {
        if (count($a) !== count($b)) return count($a) <=> count($b);
        for ($i = count($a) - 1; $i >= 0; $i--) if ($a[$i] !== $b[$i]) return $a[$i] <=> $b[$i];
        return 0;
    }

    /** $a - $b for $a >= $b. */
    private static function sub(array $a, array $b): array
    {
        $borrow = 0;
        foreach ($a as $i => $x) {
            $t = $x - ($b[$i] ?? 0) - $borrow;
            $borrow = $t < 0 ? 1 : 0;
            $a[$i] = $t & 0xffffff;
        }
        return self::normal($a);
    }

    private static function mul(array $a, array $b): array
    {
        if (!$a || !$b) return [];
        $r = array_fill(0, count($a) + count($b), 0);
        foreach ($a as $i => $x) {
            $carry = 0;
            foreach ($b as $j => $y) {
                $t = $r[$i + $j] + $x * $y + $carry;
                $r[$i + $j] = $t & 0xffffff;
                $carry = $t >> 24;
            }
            $r[$i + count($b)] = $carry;
        }
        return self::normal($r);
    }

    /** $a << $s for 0 <= $s < 24, one limb longer than $a. */
    private static function shl(array $a, int $s): array
    {
        $out = [];
        $carry = 0;
        foreach ($a as $x) {
            $t = $x << $s | $carry;
            $out[] = $t & 0xffffff;
            $carry = $t >> 24;
        }
        $out[] = $carry;
        return $out;
    }

    /** $u mod $v for $v > 0, by Knuth's algorithm D. */
    private static function mod(array $u, array $v): array
    {
        if (self::cmp($u, $v) < 0) return $u;
        $n = count($v);
        if ($n === 1) {
            $r = 0;
            for ($i = count($u) - 1; $i >= 0; $i--) $r = ($r << 24 | $u[$i]) % $v[0];
            return $r ? [$r] : [];
        }
        // Normalise so the divisor's top limb has its high bit set.
        $s = 24 - strlen(decbin($v[$n - 1]));
        $v = array_slice(self::shl($v, $s), 0, $n);
        $u = self::shl($u, $s);
        $top = $v[$n - 1];
        $second = $v[$n - 2];
        for ($j = count($u) - $n - 1; $j >= 0; $j--) {
            $numerator = $u[$j + $n] << 24 | $u[$j + $n - 1];
            $q = intdiv($numerator, $top);
            $r = $numerator - $q * $top;
            while ($q > 0xffffff || $q * $second > ($r << 24 | $u[$j + $n - 2])) {
                $q--;
                $r += $top;
                if ($r > 0xffffff) break;
            }
            $borrow = $carry = 0;
            for ($i = 0; $i < $n; $i++) {
                $product = $q * $v[$i] + $carry;
                $carry = $product >> 24;
                $t = $u[$i + $j] - ($product & 0xffffff) - $borrow;
                $borrow = $t < 0 ? 1 : 0;
                $u[$i + $j] = $t & 0xffffff;
            }
            $t = $u[$j + $n] - $carry - $borrow;
            $u[$j + $n] = $t & 0xffffff;
            if ($t < 0) {
                // The estimate was one too large: add the divisor back.
                $carry = 0;
                for ($i = 0; $i < $n; $i++) {
                    $t = $u[$i + $j] + $v[$i] + $carry;
                    $u[$i + $j] = $t & 0xffffff;
                    $carry = $t >> 24;
                }
                $u[$j + $n] = ($u[$j + $n] + $carry) & 0xffffff;
            }
        }
        $remainder = [];
        for ($i = 0; $i < $n; $i++) $remainder[] = ($u[$i] >> $s | ($i + 1 < $n ? $u[$i + 1] : 0) << (24 - $s)) & 0xffffff;
        return self::normal($remainder);
    }

    private static function uint32(string $data, int &$at): ?int
    {
        if ($at + 4 > strlen($data)) return null;
        $value = unpack('N', $data, $at)[1];
        $at += 4;
        return $value;
    }

    private static function string(string $data, int &$at): ?string
    {
        $length = self::uint32($data, $at);
        if ($length === null || $length > strlen($data) - $at) return null;
        $value = (string)substr($data, $at, $length);
        $at += $length;
        return $value;
    }

    private static function der(string $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) return $tag . chr($length) . $content;
        $bytes = ltrim(pack('N', $length), "\0");
        return $tag . chr(0x80 | strlen($bytes)) . $bytes . $content;
    }
}
