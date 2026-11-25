<?php

namespace App\Services\Telegram;

use App\Models\User;

/** Small, shared presentation rules for Telegram's Persian customer screens. */
class BotView
{
    public const PERIODS = [
        'month' => 'ماهانه',
        'quarter' => 'سه‌ماهه',
        'half_year' => 'شش‌ماهه',
        'year' => 'سالانه',
        'two_year' => 'دوساله',
        'three_year' => 'سه‌ساله',
        'onetime' => 'حجمی (بدون انقضا)',
    ];

    public static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function money($amount): string
    {
        return number_format((int) $amount) . ' تومان';
    }

    public static function status(User $user): string
    {
        if (!$user->plan_id) return '⚪ بدون اشتراک';
        if ($user->expired_at !== null && $user->expired_at <= time()) return '🔴 منقضی شده';
        if ($user->u + $user->d >= $user->transfer_enable) return '🟠 حجم تمام شده';
        return '🟢 فعال';
    }

    /** Strict integers only: Persian/Arabic digits and correctly grouped amounts. */
    public static function amount(string $text): ?int
    {
        $text = strtr(trim($text), array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        ));
        $text = str_replace('٬', ',', $text);
        if (!preg_match('/^(?:[0-9]+|[1-9][0-9]{0,2}(?:,[0-9]{3})+)$/D', $text)) return null;
        $digits = ltrim(str_replace(',', '', $text), '0');
        // Money columns and the existing web charge form use signed 32-bit amounts.
        if (strlen($digits) > 10 || (strlen($digits) === 10 && strcmp($digits, '2147483647') > 0)) return null;
        return (int) $digits;
    }

    public static function home(): array
    {
        return [['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']];
    }

    public static function cancel(): array
    {
        return [[['text' => '✖️ انصراف و بازگشت', 'callback_data' => 'cancel_input']]];
    }
}
