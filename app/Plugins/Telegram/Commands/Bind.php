<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Bind extends Telegram {
    public $command = '/bind';
    public $description = 'اتصال حساب تلگرام به سایت';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        if (!isset($message->args[0])) {
            abort(500, '❌ پارامتر نادرست است. لطفاً آدرس اشتراک را همراه دستور بفرستید.');
        }
        $subscribeUrl = $message->args[0];
        $subscribeUrl = parse_url($subscribeUrl);
        parse_str($subscribeUrl['query'], $query);
        $token = $query['token'];
        if (!$token) {
            abort(500, '❌ آدرس اشتراک نامعتبر است.');
        }
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    abort(403, '❌ توکن نامعتبر است.');
                }
                $usertoken = Cache::get("otpn_{$token}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!$userid || !$clienthash) {
                        abort(403, '❌ توکن نامعتبر است.');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        abort(403, '❌ توکن نامعتبر است.');
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        abort(403, '❌ توکن نامعتبر است.');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            abort(500, '❌ کاربر یافت نشد.');
        }
        if ($user->telegram_id) {
            abort(500, '❌ این حساب قبلاً به یک حساب تلگرام متصل شده است.');
        }
        $user->telegram_id = $message->chat_id;
        if (!$user->save()) {
            abort(500, '❌ ذخیره ناموفق بود.');
        }
        $telegramService = $this->telegramService;
        $telegramService->sendMessage($message->chat_id, '✅ اتصال با موفقیت انجام شد.');
    }
}
