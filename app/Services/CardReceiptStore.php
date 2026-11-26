<?php

namespace App\Services;

use App\Models\CardPayment;

/**
 * Receipt images a user sends with a card-to-card payment from the app or the
 * site, kept on the panel's own disk.
 *
 * Receipts sent through the Telegram bot stay where they are: their
 * receipt_file_id is Telegram's file id, fetched on demand. A stored one is
 * "local:card-receipts/<payment>-<random>.<ext>", which nothing a client sends
 * can name: the path is made here, and only that shape is ever read back.
 *
 * The image arrives as base64 in a JSON body rather than as a multipart
 * upload. Every hop between the app and the panel carries JSON, including the
 * relay's emergency path, which forwards a request's raw body - and PHP gives
 * no raw body for multipart/form-data.
 */
class CardReceiptStore
{
    const PREFIX = 'local:';
    const DIR = 'card-receipts';

    /** Decoded size cap. The app sends a JPEG of a few hundred KB. */
    const MAX_BYTES = 3 * 1024 * 1024;

    private const TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /**
     * The image in [data] (base64, with or without a data: URI prefix), as
     * [bytes, extension], or an abort(400) naming what is wrong with it.
     */
    public static function decode($data): array
    {
        if (!is_string($data) || $data === '') {
            abort(400, 'تصویر رسید خالی است');
        }
        if (preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $data, $m)) {
            $data = substr($data, strlen($m[0]));
        }
        // A rough bound before decoding: base64 is 4 characters per 3 bytes.
        if (strlen($data) > (int)ceil(self::MAX_BYTES / 3) * 4 + 4) {
            abort(400, 'حجم تصویر رسید بیش از حد مجاز است');
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $data), true);
        if ($bytes === false || $bytes === '') {
            abort(400, 'تصویر رسید خوانا نیست');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            abort(400, 'حجم تصویر رسید بیش از حد مجاز است');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !isset(self::TYPES[$info[2]])) {
            abort(400, 'فقط تصویر JPG، PNG یا WebP پذیرفته می‌شود');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000) {
            abort(400, 'ابعاد تصویر رسید نامعتبر است');
        }
        return [$bytes, self::TYPES[$info[2]][0]];
    }

    /** Writes [bytes] for [payment]; returns the receipt_file_id to store. */
    public static function save(CardPayment $payment, string $bytes, string $ext): string
    {
        $dir = storage_path('app/' . self::DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            abort(500, 'ذخیرهٔ رسید ممکن نشد');
        }
        $name = $payment->id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $name, $bytes, LOCK_EX) !== strlen($bytes)) {
            abort(500, 'ذخیرهٔ رسید ممکن نشد');
        }
        @chmod($dir . '/' . $name, 0640);
        return self::PREFIX . self::DIR . '/' . $name;
    }

    public static function isLocal($id): bool
    {
        return is_string($id) && str_starts_with($id, self::PREFIX);
    }

    /** The stored image behind [id], as [bytes, mime], or null. */
    public static function read($id): ?array
    {
        $path = self::pathOf($id);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        foreach (self::TYPES as [$e, $mime]) {
            if ($e === $ext) {
                return [$bytes, $mime];
            }
        }
        return null;
    }

    public static function delete($id): void
    {
        $path = self::pathOf($id);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private static function pathOf($id): ?string
    {
        if (!is_string($id) || !preg_match('#^local:card-receipts/(\d+-[0-9a-f]{16}\.(?:jpg|png|webp))$#', $id, $m)) {
            return null;
        }
        return storage_path('app/' . self::DIR . '/' . $m[1]);
    }
}
