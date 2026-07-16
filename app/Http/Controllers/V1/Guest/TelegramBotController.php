<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Request;

class TelegramBotController extends Controller
{
    public function webhook(Request $request)
    {
        $token = $request->input('access_token');
        $expectedToken = md5(config('v2board.telegram_bot_token'));

        if ($token !== $expectedToken) {
            abort(403, 'Invalid access token');
        }

        $update = $request->all();

        if (empty($update)) {
            return response()->json(['status' => 'empty']);
        }

        $botService = new TelegramBotService();
        $botService->handleWebhook($update);

        return response()->json(['status' => 'ok']);
    }
}
