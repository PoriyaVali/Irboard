<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Models\Plan;
use App\Models\Order;
use App\Models\BotPanel;
use App\Models\BotChannel;
use App\Models\BotText;
use App\Models\BotSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\Telegram\TelegramOrderService;

class TelegramBotService
{
    protected string $token;
    protected string $apiUrl;
    protected ?array $update = null;
    protected ?User $user = null;

    public function __construct()
    {
        $this->token = config('v2board.telegram_bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}/";
    }

    /**
     * پردازش webhook
     */
    public function handleWebhook(array $update): void
    {
        $this->update = $update;

        try {
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            }
        } catch (\Exception $e) {
            Log::error('Telegram Bot Error: ' . $e->getMessage());
        }
    }

    /**
     * پردازش پیام
     */
    /**
     * آیا کاربر یک پرداخت کارت‌به‌کارت در انتظار تأیید ادمین دارد؟
     */
    protected function hasPendingCardClaim(): bool
    {
        if (!$this->user) {
            return false;
        }
        return \App\Models\CardPayment::where('user_id', $this->user->id)
            ->where('status', \App\Models\CardPayment::STATUS_CLAIMED)
            ->exists();
    }

    protected function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        Log::info('Telegram text received', ['text' => $text, 'chat_id' => $chatId]);
        $telegramId = $message['from']['id'];

        // پیدا کردن یا ساخت کاربر
        $this->user = $this->findOrCreateUser($message['from']);

        // بررسی عضویت در کانال
        if (!$this->checkChannelMembership($telegramId)) {
            $this->sendJoinChannelMessage($chatId);
            return;
        }

        // بررسی بن بودن
        if ($this->user->banned) {
            $this->sendMessage($chatId, '⛔ حساب شما مسدود شده است.');
            return;
        }

        // پاسخ ادمین به تیکت با ریپلای روی پیام اعلان
        if ($this->user->is_admin && $text !== '' && isset($message['reply_to_message'])) {
            $repliedText = $message['reply_to_message']['text'] ?? '';
            if ((mb_strpos($repliedText, '📮') !== false || mb_strpos($repliedText, 'تیکت') !== false || mb_strpos($repliedText, '工单') !== false) && preg_match('/#(\d+)/u', $repliedText, $m)) {
                $this->handleAdminTicketReply($chatId, (int) $m[1], $text);
                return;
            }
        }

        // قفل کارت‌به‌کارت: تا پاسخ ادمین، هیچ کار دیگری انجام نشود
        if ($this->hasPendingCardClaim()) {
            $pendingCp = \App\Models\CardPayment::where('user_id', $this->user->id)
                ->where('status', \App\Models\CardPayment::STATUS_CLAIMED)
                ->orderBy('id', 'desc')->first();
            $tradeNo = $pendingCp ? $pendingCp->trade_no : '';
            $remindKb = $pendingCp ? [[['text' => '🔔 یادآوری به ادمین', 'callback_data' => 'remind_admin_' . $pendingCp->id]]] : null;
            $this->sendMessage($chatId, "⏳ درخواست پرداخت کارت‌به‌کارت شما در حال بررسی است.\n🔢 شماره سفارش: {$tradeNo}\nلطفاً تا پاسخ ادمین منتظر بمانید. نتیجه از طریق ربات اطلاع‌رسانی می‌شود.", null, $remindKb);
            return;
        }

        // بررسی step کاربر
        $step = $this->user->bot_step;

        if ($step) {
            // اگر عکس ارسال شده و منتظر رسید هستیم
            if ($step === 'waiting_card_receipt' && isset($message['photo'])) {
                $photo = end($message['photo']);
                $this->processCardReceipt($chatId, $photo['file_id']);
                return;
            }
            $this->handleStep($chatId, $text, $step);
            return;
        }

        // پردازش دستورات
        $this->handleCommand($chatId, $text);
    }

    /**
     * پردازش دستورات
     */
    protected function handleCommand(int $chatId, string $text): void
    {
        Log::info("handleCommand called", ["text" => $text]);
        // بررسی /start با پارامتر (مثل /start invite_xxx)
        Log::info("Checking regex", ["text" => $text, "len" => strlen($text)]);
        if (preg_match('/^\/start\s+(.+)$/', $text, $matches)) {
            Log::info("Regex matched", ["param" => $matches[1]]);
            $this->handleStartParam($chatId, trim($matches[1]));
            return;
        }
        Log::info("Regex NOT matched");
        
        switch ($text) {
            case '/start':
            case '🏠 منوی اصلی':
                $this->sendMainMenu($chatId);
                break;
            case '/setting':
                Log::info("Setting command received", ["is_admin" => $this->user->is_admin]);
                if ($this->user->is_admin) {
                    $this->showSettings($chatId);
                } else {
                    $this->sendMessage($chatId, "❌ این دستور فقط برای ادمین‌ها است.");
                }
                break;

            case '🛒 خرید اشتراک':
                $this->sendPlansList($chatId);
                break;

            case '👤 حساب کاربری':
                $this->sendAccountInfo($chatId);
                break;

            case '🎁 اکانت تست':
                $this->handleTestAccount($chatId);
                break;

            case '💰 کیف پول':
                $this->sendWalletInfo($chatId);
                break;

            case '📦 سرویس‌های من':
                $this->sendMyServices($chatId);
                break;

            case '🎁 گیفت کارت':
                $this->askForGiftCard($chatId);
                break;

            case '👥 زیرمجموعه':
                $this->sendAffiliateInfo($chatId);
                break;

            case '📞 پشتیبانی':
                $this->sendSupportInfo($chatId);
                break;

            case '📚 راهنما':
                $this->sendHelpInfo($chatId);
                break;

            default:
                // اجرای دستورات داینامیک
                if (Str::startsWith($text, '/')) {
                    $this->executePluginCommand($chatId, $text);
                } else {
                    $this->sendMainMenu($chatId);
                }
        }
    }

    /**
     * منوی اصلی
     */
    protected function sendMainMenu(int $chatId): void
    {
        $text = BotText::get('text_start', "🌟 به ربات خوش آمدید!\n\nاز منوی زیر استفاده کنید:");

        $keyboard = [
            [['text' => '🛒 خرید اشتراک'], ['text' => '👤 حساب کاربری']],
            [['text' => '📦 سرویس‌های من'], ['text' => '🎁 اکانت تست']],
            [['text' => '💰 کیف پول'], ['text' => '🎁 گیفت کارت']],
            [['text' => '👥 زیرمجموعه'], ['text' => '📞 پشتیبانی']],
            [['text' => '📚 راهنما']]
        ];

        // دکمه‌ی ورود به پنل (WebApp) — ورود خودکار با توکن کاربر
        if (!empty($this->user) && !empty($this->user->token)) {
            array_unshift($keyboard, [[
                'text' => '🌐 ورود به پنل',
                'web_app' => ['url' => rtrim(config('v2board.app_url', ''), '/') . '/api/v1/guest/telegram/auth?token=' . $this->user->token . '&redirect=dashboard']
            ]]);
        }

        $this->sendMessage($chatId, $text, $keyboard);
    }

    /**
     * لیست پلن‌ها
     */
    protected function sendPlansList(int $chatId): void
    {
        $plans = Plan::where('show', true)->orderBy('sort')->get();

        if ($plans->isEmpty()) {
            $this->sendMessage($chatId, '❌ در حال حاضر پلنی موجود نیست.');
            return;
        }

        $text = "📋 *لیست اشتراک‌ها:*\n\n";

        $buttons = [];
        foreach ($plans as $plan) {
            $price = number_format($plan->month_price) . ' تومان';
            $text .= "▫️ *{$plan->name}*\n";
            $text .= "   💰 قیمت ماهانه: {$price}\n";
            $text .= "   📊 حجم: " . $this->formatBytes($plan->transfer_enable * 1073741824) . "\n\n";

            $buttons[] = [['text' => "🛒 {$plan->name}", 'callback_data' => "plan_{$plan->id}"]];
        }

        $buttons[] = [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * اطلاعات حساب
     */
    protected function sendAccountInfo(int $chatId): void
    {
        $user = $this->user;
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;

        $text = "👤 *حساب کاربری*\n\n";
        $text .= "📧 ایمیل: `{$user->email}`\n";
        $text .= "💰 موجودی: " . number_format($user->balance) . " تومان\n";

        if ($plan) {
            $text .= "📦 اشتراک: {$plan->name}\n";
            $expireDate = $this->jalaliDate($user->expired_at, 'Y/m/d');
            $text .= "📅 انقضا: {$expireDate}\n";
            $text .= "📊 حجم مصرفی: " . $this->formatBytes($user->u + $user->d) . " از " . $this->formatBytes($user->transfer_enable) . "\n";
        } else {
            $text .= "📦 اشتراک: ندارید\n";
        }

        $reservedCount = \App\Models\ReservedPlan::where('user_id', $user->id)->where('status', 0)->count();
        if ($reservedCount > 0) {
            $text .= "📋 بسته‌های رزرو: {$reservedCount} عدد\n";
        }
        $buttons = [
            [['text' => '🔗 دریافت لینک اشتراک', 'callback_data' => 'get_sub_link']],
        ];
        if ($reservedCount > 0) {
            $buttons[] = [['text' => '📋 مشاهده بسته‌های رزرو', 'callback_data' => 'reserved_plans']];
        }
        $buttons[] = [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * اکانت تست
     */
    protected function handleTestAccount(int $chatId): void
    {
        $maxTest = (int) BotSetting::get('test_limit', 1);
        
        if ($this->user->bot_test_count >= $maxTest) {
            $this->sendMessage($chatId, "❌ شما قبلاً از اکانت تست استفاده کرده‌اید.");
            return;
        }

        $panels = BotPanel::where('status', true)->where('test_enabled', true)->get();

        if ($panels->isEmpty()) {
            $this->sendMessage($chatId, "❌ در حال حاضر اکانت تست موجود نیست.");
            return;
        }

        $buttons = [];
        foreach ($panels as $panel) {
            $buttons[] = [['text' => "🎁 {$panel->name}", 'callback_data' => "test_{$panel->id}"]];
        }
        $buttons[] = [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, "🎁 یک سرور برای اکانت تست انتخاب کنید:", null, $buttons);
    }

    /**
     * اطلاعات کیف پول
     */
    protected function sendWalletInfo(int $chatId): void
    {
        $text = "💰 *کیف پول*\n\n";
        $text .= "💵 موجودی: " . number_format($this->user->balance) . " تومان\n\n";
        $text .= "برای شارژ کیف پول روی دکمه زیر کلیک کنید:";

        $buttons = [
            [['text' => '💳 شارژ کیف پول', 'callback_data' => 'charge_wallet']],
            [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
        ];

        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * پردازش callback
     */
    protected function handleCallback(array $callback): void
    {
        Log::info("handleCallback called", ["data" => $callback['data'] ?? 'none']);
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $data = $callback['data'];
        $telegramId = $callback['from']['id'];

        $this->user = User::where('telegram_id', $telegramId)->first();

        if (!$this->user) {
            $this->answerCallback($callback['id'], '❌ لطفاً ابتدا /start بزنید.');
            return;
        }

        // قفل کارت‌به‌کارت: جز دکمه‌های تأیید ادمین، تا پاسخ ادمین هیچ کاری انجام نشود
        if ($this->hasPendingCardClaim()
            && !Str::startsWith($data, 'card_verify_')
            && !Str::startsWith($data, 'card_reject_')
            && !Str::startsWith($data, 'card_confirm_')
            && !Str::startsWith($data, 'remind_admin_')) {
            $this->answerCallback($callback['id'], '⏳ منتظر پاسخ ادمین باشید.');
            return;
        }

        // پردازش callback ها
        if ($data === 'main_menu') {
            $this->deleteMessage($chatId, $messageId);
            $this->sendMainMenu($chatId);
        } elseif (Str::startsWith($data, 'plan_')) {
            $planId = (int) Str::after($data, 'plan_');
            $this->showPlanDetails($chatId, $messageId, $planId);
        } elseif (Str::startsWith($data, 'buy_')) {
            $planId = (int) Str::after($data, 'buy_');
            $this->handleBuyPlan($chatId, $data);
        } elseif (Str::startsWith($data, 'test_')) {
            $panelId = (int) Str::after($data, 'test_');
            $this->createTestAccount($chatId, $panelId);
        } elseif ($data === 'get_sub_link') {
            $this->sendSubscriptionLink($chatId);
        } elseif ($data === 'charge_wallet') {
            $this->askChargeAmount($chatId);
        } elseif (Str::startsWith($data, 'pay_')) {
            $this->handlePayment($chatId, $data);
        } elseif (Str::startsWith($data, 'cancel_order_')) {
            $tradeNo = Str::after($data, 'cancel_order_');
            $this->handleCancelOrder($chatId, $tradeNo);
        } elseif ($data === 'reserved_plans') {
            $this->showReservedPlans($chatId, $messageId);
        } elseif ($data === 'plans_list') {
            $this->deleteMessage($chatId, $messageId);
            $this->sendPlansList($chatId);
        } elseif (Str::startsWith($data, 'coupon_yes_')) {
            $tradeNo = Str::after($data, 'coupon_yes_');
            $this->askForCouponCode($chatId, $tradeNo);
        } elseif (Str::startsWith($data, 'coupon_no_')) {
            $tradeNo = Str::after($data, 'coupon_no_');
            $this->showPaymentGateways($chatId, $tradeNo);
        } elseif ($data === 'create_invite_code') {
            $this->createInviteCode($chatId);
        } elseif ($data === 'transfer_commission') {
            $this->transferCommission($chatId);
        } elseif ($data === 'new_ticket') {
            $this->startNewTicket($chatId);
        } elseif ($data === 'ticket_add_message') {
            $this->askTicketMessage($chatId);
        } elseif ($data === 'ticket_send_now') {
            $this->sendTicketNow($chatId);
        } elseif ($data === 'my_tickets') {
            $this->showMyTickets($chatId);
        } elseif (Str::startsWith($data, 'view_ticket_')) {
            $ticketId = (int) Str::after($data, 'view_ticket_');
            $this->viewTicket($chatId, $ticketId);
        } elseif (Str::startsWith($data, 'reply_ticket_')) {
            $ticketId = (int) Str::after($data, 'reply_ticket_');
            $this->startReplyTicket($chatId, $ticketId);
        } elseif ($data === 'support_menu') {
            $this->sendSupportInfo($chatId);
        } elseif ($data === 'setting_transit_toggle' && $this->user->is_admin) {
            $this->toggleTransitSetting($chatId);
        } elseif ($data === 'setting_transit_url' && $this->user->is_admin) {
            $this->askTransitUrl($chatId);
        } elseif ($data === 'setting_back' && $this->user->is_admin) {
            $this->showSettings($chatId);
        } elseif ($data === 'setting_proxy_toggle' && $this->user->is_admin) {
            $this->toggleProxySetting($chatId);
        } elseif (Str::startsWith($data, 'renew_')) {
            $planId = (int) Str::after($data, 'renew_');
            $this->handleRenew($chatId, $planId);
        } elseif ($data === 'card_done') {
            $this->answerCallback($callback['id'], 'این پرداخت قبلاً پردازش شده است');
            return;
        } elseif (Str::startsWith($data, 'remind_admin_')) {
            $paymentId = (int) Str::after($data, 'remind_admin_');
            $this->handleRemindAdmin($chatId, $paymentId, $callback['id']);
            return;
        } elseif (Str::startsWith($data, 'card_verify_full_')) { // ━━━ Card Payment Callbacks ━━━
            $paymentId = (int) Str::after($data, 'card_verify_full_');
            $this->handleCardVerifyFull($chatId, $paymentId, $callback['id']);
            return;
        } elseif (Str::startsWith($data, 'card_verify_diff_')) {
            $paymentId = (int) Str::after($data, 'card_verify_diff_');
            $this->handleCardVerifyDiff($chatId, $paymentId, $callback['id']);
            return;
        } elseif (Str::startsWith($data, 'card_reject_')) {
            $paymentId = (int) Str::after($data, 'card_reject_');
            $this->handleCardReject($chatId, $paymentId, $callback['id']);
            return;
        } elseif (Str::startsWith($data, 'card_confirm_diff_')) {
            $this->handleCardConfirmDiff($chatId, $data, $callback['id']);
            return;
        } elseif (Str::startsWith($data, 'card_confirm_reject_')) {
            $this->handleCardConfirmReject($chatId, $data, $callback['id']);
            return;
        } elseif (Str::startsWith($data, 'help_')) {
            $this->answerCallback($callback['id']);
            $this->sendHelpPlatform($chatId, Str::after($data, 'help_'));
            return;
        }

        $this->answerCallback($callback['id']);
    }

    /**
     * پیدا کردن یا ساخت کاربر
     */
    protected function findOrCreateUser(array $from): User
    {
        $telegramId = $from['id'];
        
        // اول بررسی با telegram_id
        $user = User::where('telegram_id', $telegramId)->first();
        
        if (!$user) {
            // بررسی آیا یوزر tg_xxx قبلاً ساخته شده
            $tgEmail = "tg_{$telegramId}@telegram.user";
            $existingUser = User::where('email', $tgEmail)->first();
            
            if ($existingUser) {
                // یوزر وجود دارد، فقط telegram_id را ست کن
                $existingUser->telegram_id = $telegramId;
                $existingUser->save();
                $user = $existingUser;
            } else {
                // ساخت کاربر جدید
                $user = User::create([
                    'email' => $tgEmail,
                    'telegram_id' => $telegramId,
                    'password' => bcrypt(Str::random(16)),
                    'uuid' => Str::uuid(),
                    'token' => Str::random(32),
                ]);
            }
        }
        
        return $user;
    }

    /**
     * بررسی عضویت در کانال
     */
    protected function checkChannelMembership(int $telegramId): bool
    {
        $channels = BotChannel::where('status', true)->get();

        if ($channels->isEmpty()) {
            return true;
        }

        foreach ($channels as $channel) {
            $response = Http::get($this->apiUrl . 'getChatMember', [
                'chat_id' => $channel->channel_id,
                'user_id' => $telegramId
            ]);

            $result = $response->json();
            
            if (!isset($result['ok']) || !$result['ok']) {
                return false;
            }

            $status = $result['result']['status'] ?? '';
            if (!in_array($status, ['member', 'administrator', 'creator'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * پیام عضویت در کانال
     */
    protected function sendJoinChannelMessage(int $chatId): void
    {
        $channels = BotChannel::where('status', true)->get();

        $text = "⚠️ برای استفاده از ربات ابتدا در کانال‌های زیر عضو شوید:\n\n";

        $buttons = [];
        foreach ($channels as $channel) {
            $link = $channel->invite_link ?: "https://t.me/" . ltrim($channel->channel_id, '@');
            $buttons[] = [['text' => "📢 {$channel->title}", 'url' => $link]];
        }
        $buttons[] = [['text' => '✅ عضو شدم', 'callback_data' => 'check_join']];

        $this->sendMessage($chatId, $text, null, $buttons);
    }

    /**
     * ارسال پیام
     */
    public function sendMessage(int $chatId, string $text, ?array $keyboard = null, ?array $inlineKeyboard = null, string $parseMode = ''): void
    {
        Log::info("sendMessage called", ["chatId" => $chatId, "text_len" => strlen($text), "hasInline" => !empty($inlineKeyboard)]);
        $data = [
            'chat_id' => $chatId,
            'text' => $text
        ];

        if ($parseMode) {
            $data['parse_mode'] = $parseMode;
        }

        if ($keyboard) {
            $data['reply_markup'] = json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true
            ]);
        } elseif ($inlineKeyboard) {
            $data['reply_markup'] = json_encode([
                'inline_keyboard' => $inlineKeyboard
            ]);
        }

        try { 
            $response = Http::post($this->apiUrl . 'sendMessage', $data); 
            Log::info("TG Response Full", ["status" => $response->status(), "body" => $response->json()]); 
        } catch (\Exception $e) { 
            Log::error("TG Error: " . $e->getMessage()); 
        }
    }

    /**
     * حذف پیام
     */
    protected function deleteMessage(int $chatId, int $messageId): void
    {
        Http::post($this->apiUrl . 'deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }

    /**
     * پاسخ به callback
     */
    protected function answerCallback(string $callbackId, string $text = ''): void
    {
        Http::post($this->apiUrl . 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text
        ]);
    }

    /**
     * فرمت حجم
     */
    protected function jalaliDate($timestamp, string $format = 'Y/m/d H:i'): string
    {
        if (empty($timestamp)) {
            return '-';
        }
        return \Morilog\Jalali\Jalalian::fromCarbon(
            \Carbon\Carbon::createFromTimestamp($timestamp, 'Asia/Tehran')
        )->format($format);
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }

    // متدهای بیشتر برای خرید، پرداخت، و غیره در فایل‌های بعدی اضافه می‌شوند...

    /**
     * سرویس‌های من
     */
    protected function sendMyServices(int $chatId): void
    {
        $user = $this->user;
        
        if (!$user->plan_id) {
            $this->sendMessage($chatId, "📦 شما هیچ سرویس فعالی ندارید.\n\nبرای خرید از منوی 🛒 خرید اشتراک استفاده کنید.");
            return;
        }

        $plan = Plan::find($user->plan_id);
        $expireDate = $this->jalaliDate($user->expired_at, 'Y/m/d H:i');
        $usedTraffic = $this->formatBytes($user->u + $user->d);
        $totalTraffic = $this->formatBytes($user->transfer_enable);
        $remainingTraffic = $this->formatBytes(max(0, $user->transfer_enable - $user->u - $user->d));

        $text = "📦 *سرویس‌های من*\n\n";
        $text .= "📋 پلن: *{$plan->name}*\n";
        $text .= "📅 انقضا: `{$expireDate}`\n";
        $text .= "📊 مصرف: {$usedTraffic} از {$totalTraffic}\n";
        $text .= "📉 باقیمانده: {$remainingTraffic}\n";

        $buttons = [
            [['text' => '🔗 دریافت لینک اشتراک', 'callback_data' => 'get_sub_link']],
            [['text' => '🔄 تمدید اشتراک', 'callback_data' => 'renew_' . $user->plan_id]],
            [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
        ];

        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * درخواست کد تخفیف
     */
    protected function askForGiftCard(int $chatId): void
    {
        $this->user->update(['bot_step' => 'enter_giftcard']);
        $this->sendMessage($chatId, "🎁 لطفاً کد گیفت کارت خود را وارد کنید:\n\nبرای انصراف /cancel بزنید.");
    }
    /**
     * اطلاعات زیرمجموعه
     */
    protected function sendAffiliateInfo(int $chatId): void
    {
        $user = $this->user;
        
        $inviteCodes = \App\Models\InviteCode::where('user_id', $user->id)->where('status', 0)->get();
        
        $refCount = User::where('invite_user_id', $user->id)->count();
        $commissionRate = $user->commission_rate ?: config('v2board.invite_commission', 10);
        $commissionBalance = $user->commission_balance ?? 0;
        
        $pendingCommission = (int)\App\Models\Order::where('status', 3)->where('commission_status', 0)->where('invite_user_id', $user->id)->sum('commission_balance');
        $confirmedCommission = (int)\App\Models\CommissionLog::where('invite_user_id', $user->id)->sum('get_amount');

        $text = "👥 *زیرمجموعه‌گیری*\n\n";
        $text .= "📊 *آمار شما:*\n";
        $text .= "👤 تعداد زیرمجموعه: {$refCount} نفر\n";
        $text .= "📈 درصد کمیسیون: {$commissionRate}%\n";
        $text .= "💰 کمیسیون قابل برداشت: " . number_format($commissionBalance) . " تومان\n";
        $text .= "⏳ در انتظار تایید: " . number_format($pendingCommission) . " تومان\n";
        $text .= "✅ کل دریافتی: " . number_format($confirmedCommission) . " تومان\n\n";

        $siteUrl = config("v2board.frontend_url");
        $loginPath = config('v2board.frontend_login_path', 'index.html');
        $botUsername = config('v2board.telegram_bot_username');

        if ($inviteCodes->count() > 0) {
            $text .= "🔗 *لینک‌های دعوت:*\n\n";
            foreach ($inviteCodes as $i => $code) {
                $num = $i + 1;
                $text .= "*کد {$num}:* `{$code->code}`\n";
                $text .= "🌐 `{$siteUrl}/{$loginPath}#/register?code={$code->code}`\n";
                $text .= "🤖 `https://t.me/{$botUsername}?start=invite_{$code->code}`\n\n";
            }
        } else {
            $text .= "⚠️ شما هنوز کد دعوتی ندارید.\n\n";
        }

        $maxCodes = config('v2board.invite_gen_limit', 5);
        $canCreate = $inviteCodes->count() < $maxCodes;

        $buttons = [];
        if ($canCreate) {
            $buttons[] = [['text' => '➕ ساخت کد دعوت جدید', 'callback_data' => 'create_invite_code']];
        }
        if ($commissionBalance > 0) {
            $buttons[] = [['text' => '💸 انتقال به کیف پول', 'callback_data' => 'transfer_commission']];
        }
        $buttons[] = [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * پشتیبانی
     */
    protected function sendSupportInfo(int $chatId): void
    {
        $openTicket = \App\Models\Ticket::where('user_id', $this->user->id)->where('status', 0)->first();
        $ticketCount = \App\Models\Ticket::where('user_id', $this->user->id)->count();
        
        $text = "📞 پشتیبانی\n\n";
        
        if ($openTicket) {
            $text .= "🎫 تیکت باز: #{$openTicket->id} - {$openTicket->subject}\n";
        }
        
        $text .= "📊 تعداد تیکت‌ها: {$ticketCount}";
        
        $buttons = [
            [['text' => '📩 ارسال تیکت جدید', 'callback_data' => 'new_ticket']],
            [['text' => '📋 تیکت‌های من', 'callback_data' => 'my_tickets']],
            [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
        ];
        
        $this->sendMessage($chatId, $text, null, $buttons);
    }
    /**
     * راهنما
     */
    protected function sendHelpPlatform(int $chatId, string $platform): void
    {
        $valid = ['android', 'ios', 'windows'];
        if (!in_array($platform, $valid, true)) {
            $this->sendMainMenu($chatId);
            return;
        }
        $text  = BotText::get('help_' . $platform . '_text', '');
        $image = BotText::get('help_' . $platform . '_image', '');
        $links = json_decode(BotText::get('help_' . $platform . '_links', '[]'), true);
        if (!is_array($links)) $links = [];
        if ($text === '') $text = 'محتوای این بخش هنوز تنظیم نشده است.';

        $inline = [];
        foreach ($links as $lk) {
            if (($lk['type'] ?? '') === 'url' && !empty($lk['url'])) {
                $label = !empty($lk['label']) ? $lk['label'] : '🔗 لینک';
                $inline[] = [['text' => $label, 'url' => $lk['url']]];
            }
        }
        $inline[] = [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']];

        if ($image !== '') {
            if (mb_strlen($text) > 1000) {
                $this->sendPhoto($chatId, $image, '', null);
                $this->sendMessage($chatId, $text, null, $inline);
            } else {
                $this->sendPhoto($chatId, $image, $text, $inline);
            }
        } else {
            $this->sendMessage($chatId, $text, null, $inline);
        }

        foreach ($links as $lk) {
            if (($lk['type'] ?? '') !== 'file' || empty($lk['file'])) continue;
            $path    = public_path(ltrim($lk['file'], '/'));
            $caption = !empty($lk['label']) ? $lk['label'] : '';
            $size    = file_exists($path) ? filesize($path) : 0;
            if ($size > 0 && $size <= 50 * 1024 * 1024) {
                $this->sendDocumentFile($chatId, $path, $caption);
            } else {
                $fileUrl = secure_url('/' . ltrim($lk['file'], '/'));
                $btnText = '⬇️ ' . ($caption !== '' ? $caption : 'دانلود فایل');
                $note    = ($caption !== '' ? $caption : 'فایل') . ' (حجم: ' . $this->humanSize($size) . ')';
                $this->sendMessage($chatId, $note, null, [[['text' => $btnText, 'url' => $fileUrl]]]);
            }
        }
    }

    protected function sendPhoto(int $chatId, string $photo, string $caption = '', ?array $inlineKeyboard = null): void
    {
        $data = ['chat_id' => $chatId, 'photo' => $photo];
        if ($caption !== '') $data['caption'] = $caption;
        if ($inlineKeyboard) {
            $data['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }
        try {
            $resp = Http::post($this->apiUrl . 'sendPhoto', $data);
            Log::info('sendPhoto', ['status' => $resp->status(), 'ok' => $resp->json('ok')]);
        } catch (\Throwable $e) {
            Log::error('sendPhoto error', ['msg' => $e->getMessage()]);
            $this->sendMessage($chatId, $caption, null, $inlineKeyboard);
        }
    }

    protected function sendDocument(int $chatId, string $document, string $caption = ''): void
    {
        $data = ['chat_id' => $chatId, 'document' => $document];
        if ($caption !== '') $data['caption'] = $caption;
        try {
            $resp = Http::post($this->apiUrl . 'sendDocument', $data);
            Log::info('sendDocument(url)', ['status' => $resp->status(), 'ok' => $resp->json('ok')]);
        } catch (\Throwable $e) {
            Log::error('sendDocument error', ['msg' => $e->getMessage()]);
        }
    }

    protected function sendDocumentFile(int $chatId, string $path, string $caption = ''): void
    {
        try {
            $payload = ['chat_id' => $chatId];
            if ($caption !== '') $payload['caption'] = $caption;
            $resp = Http::attach('document', fopen($path, 'r'), basename($path))
                ->timeout(180)
                ->post($this->apiUrl . 'sendDocument', $payload);
            Log::info('sendDocumentFile', ['file' => basename($path), 'size' => @filesize($path), 'status' => $resp->status(), 'ok' => $resp->json('ok')]);
        } catch (\Throwable $e) {
            Log::error('sendDocumentFile error', ['msg' => $e->getMessage()]);
        }
    }

    protected function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    protected function sendHelpInfo(int $chatId): void
    {
        $text = BotText::get('text_help', "📚 *راهنما*\n\nبرای دریافت راهنمای استفاده از سرویس، گزینه مورد نظر را انتخاب کنید.");
        
        $buttons = [
            [['text' => '📱 آموزش اندروید', 'callback_data' => 'help_android']],
            [['text' => '🍎 آموزش iOS', 'callback_data' => 'help_ios']],
            [['text' => '💻 آموزش ویندوز', 'callback_data' => 'help_windows']],
            [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
        ];

        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * پردازش step
     */
    protected function handleStep(int $chatId, string $text, string $step): void
    {
        if ($text === "/cancel" || $this->isMenuButton($text)) {
            $this->user->update(["bot_step" => null, "bot_data" => null]);
            $this->handleCommand($chatId, $text);
            return;
        }
        if (false) {
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            $this->sendMainMenu($chatId);
            return;
        }

        switch ($step) {
            case 'enter_coupon':
                $this->processCouponCode($chatId, $text);
                break;
            case 'enter_giftcard':
                $this->processGiftCard($chatId, $text);
                break;
            case 'enter_charge_amount':
                $this->processChargeAmount($chatId, $text);
                break;
            case 'ticket_subject':
            case 'ticket_message':
                $this->handleTicketStep($chatId, $text, $step);
                break;
            case 'ticket_reply':
                $this->processTicketReply($chatId, $text);
                break;
            case 'setting_transit_url':
            case 'waiting_card_receipt':
                $this->sendMessage($chatId, '📸 لطفاً عکس رسید بانکی را ارسال کنید.');
                break;
            case 'card_diff_amount':
                $this->processCardDiffAmount($chatId, $text);
                break;
                $this->processTransitUrl($chatId, $text);
                break;
            default:
                $this->user->update(['bot_step' => null]);
                $this->sendMainMenu($chatId);
        }
    }

    /**
     * پردازش کد تخفیف
     */
    protected function processGiftCard(int $chatId, string $code): void
    {
        $giftCard = \App\Models\Giftcard::where('code', $code)->first();

        if (!$giftCard) {
            $this->sendMessage($chatId, "❌ کد گیفت کارت نامعتبر است.");
            return;
        }

        $currentTime = time();
        
        // بررسی تاریخ شروع
        if ($giftCard->started_at && $currentTime < $giftCard->started_at) {
            $this->sendMessage($chatId, "❌ این گیفت کارت هنوز فعال نشده است.");
            return;
        }
        
        // بررسی تاریخ انقضا
        if ($giftCard->ended_at && $currentTime > $giftCard->ended_at) {
            $this->sendMessage($chatId, "❌ این گیفت کارت منقضی شده است.");
            return;
        }

        // بررسی محدودیت استفاده
        if ($giftCard->limit_use !== null) {
            if (!is_numeric($giftCard->limit_use) || $giftCard->limit_use <= 0) {
                $this->sendMessage($chatId, "❌ ظرفیت این گیفت کارت تکمیل شده است.");
                return;
            }
        }

        // بررسی استفاده قبلی
        $usedUserIds = $giftCard->used_user_ids ? json_decode($giftCard->used_user_ids, true) : [];
        if (!is_array($usedUserIds)) {
            $usedUserIds = [];
        }
        if (in_array($this->user->id, $usedUserIds)) {
            $this->sendMessage($chatId, "❌ شما قبلاً از این گیفت کارت استفاده کرده‌اید.");
            return;
        }

        // ثبت استفاده
        $usedUserIds[] = $this->user->id;
        $giftCard->used_user_ids = json_encode($usedUserIds);

        $user = $this->user;
        $resultText = "";

        switch ($giftCard->type) {
            case 1: // پول هدیه
                $user->balance += $giftCard->value;
                $resultText = "💰 مبلغ " . number_format($giftCard->value) . " تومان به کیف پول شما اضافه شد.\n💵 موجودی جدید: " . number_format($user->balance) . " تومان";
                break;
                
            case 2: // افزایش مدت زمان اشتراک
                if ($user->expired_at !== null) {
                    if ($user->expired_at <= $currentTime) {
                        $user->expired_at = $currentTime + $giftCard->value * 86400;
                    } else {
                        $user->expired_at += $giftCard->value * 86400;
                    }
                    $resultText = "📅 " . $giftCard->value . " روز به اشتراک شما اضافه شد.\n⏰ تاریخ انقضا: " . $this->jalaliDate($user->expired_at, 'Y/m/d');
                } else {
                    $this->sendMessage($chatId, "❌ شما اشتراک فعالی ندارید که بتوان مدت آن را افزایش داد.");
                    return;
                }
                break;
                
            case 3: // افزایش ترافیک
                $user->transfer_enable += $giftCard->value * 1073741824;
                $newTrafficGB = round($user->transfer_enable / 1073741824, 2);
                $resultText = "📊 " . $giftCard->value . " گیگابایت به ترافیک شما اضافه شد.\n📈 ترافیک کل: " . $newTrafficGB . " GB";
                break;
                
            case 4: // بازنشانی ترافیک
                $user->u = 0;
                $user->d = 0;
                $resultText = "🔄 ترافیک مصرفی شما بازنشانی شد.";
                break;
                
            case 5: // تعریف پلن
                if ($user->plan_id == null || ($user->expired_at !== null && $user->expired_at < $currentTime)) {
                    $plan = \App\Models\Plan::find($giftCard->plan_id);
                    if (!$plan) {
                        $this->sendMessage($chatId, "❌ پلن مربوط به این گیفت کارت یافت نشد.");
                        return;
                    }
                    $user->plan_id = $plan->id;
                    $user->group_id = $plan->group_id;
                    $user->transfer_enable = $plan->transfer_enable * 1073741824;
                    $user->device_limit = $plan->device_limit;
                    $user->u = 0;
                    $user->d = 0;
                    if ($giftCard->value == 0) {
                        $user->expired_at = null;
                    } else {
                        $user->expired_at = $currentTime + $giftCard->value * 86400;
                    }
                    $resultText = "🎉 پلن \"{$plan->name}\" برای شما فعال شد!\n📅 مدت: " . ($giftCard->value == 0 ? "نامحدود" : $giftCard->value . " روز");
                } else {
                    $this->sendMessage($chatId, "❌ شما در حال حاضر اشتراک فعال دارید. این گیفت کارت فقط برای کاربران بدون اشتراک است.");
                    return;
                }
                break;
                
            default:
                $this->sendMessage($chatId, "❌ نوع گیفت کارت نامعتبر است.");
                return;
        }

        // کاهش limit_use
        if ($giftCard->limit_use !== null) {
            $giftCard->limit_use -= 1;
        }
        
        $giftCard->save();
        $user->save();

        $this->user->update(['bot_step' => null]);
        $this->sendMessage($chatId, "✅ گیفت کارت با موفقیت اعمال شد!\n\n" . $resultText);
    }

    /**
     * درخواست مبلغ شارژ
     */
    protected function askChargeAmount(int $chatId): void
    {
        $this->user->update(['bot_step' => 'enter_charge_amount']);
        $this->sendMessage($chatId, "💳 مبلغ شارژ را به تومان وارد کنید:\n\nمثال: 50000\n\nبرای انصراف /cancel بزنید.");
    }

    /**
     * پردازش مبلغ شارژ
     */
    protected function processChargeAmount(int $chatId, string $text): void
    {
        $amount = (int) str_replace([',', '٬'], '', $text);

        if ($amount < 10000) {
            $this->sendMessage($chatId, "❌ حداقل مبلغ شارژ 10,000 تومان است.");
            return;
        }

        $this->user->update(['bot_step' => null, 'bot_data' => null]);

        // ایجاد سفارش deposit
        $orderService = new TelegramOrderService($this->user);
        $result = $orderService->createDepositOrder($amount);

        if (!$result['success']) {
            $this->sendMessage($chatId, "❌ " . $result['message']);
            return;
        }

        $tradeNo = $result["trade_no"];
        $payments = TelegramOrderService::getActivePayments();
        
        $text = "🛒 سفارش شارژ کیف پول ایجاد شد\n\n";
        $text .= "💰 مبلغ: " . number_format($amount) . " تومان\n\n";
        $text .= "درگاه پرداخت را انتخاب کنید:";
        
        $buttons = [];
        foreach ($payments as $p) {
            $buttons[] = [["text" => "💳 " . $p["name"], "callback_data" => "pay_" . $tradeNo . "_" . $p["id"]]];
        }
        $buttons[] = [["text" => "❌ لغو سفارش", "callback_data" => "cancel_order_" . $tradeNo]];

        $this->sendMessage($chatId, $text, null, $buttons);
    }

    /**
     * پردازش پارامتر start
     */
    protected function handleStartParam(int $chatId, string $param): void
    {
        // پردازش کد دعوت از جدول v2_invite_code
        if (\Illuminate\Support\Str::startsWith($param, 'invite_')) {
            $inviteCode = \Illuminate\Support\Str::after($param, 'invite_');
            $code = \App\Models\InviteCode::where('code', $inviteCode)->where('status', 0)->first();
            
            if (!$code) {
                $this->sendMessage($chatId, "❌ کد دعوت نامعتبر یا منقضی شده است.");
            } elseif ($code->user_id === $this->user->id) {
                $this->sendMessage($chatId, "❌ نمی‌توانید از کد دعوت خودتان استفاده کنید.");
            } elseif ($this->user->invite_user_id) {
                $this->sendMessage($chatId, "ℹ️ شما قبلاً با کد دعوت دیگری ثبت‌نام کرده‌اید.");
            } else {
                $this->user->update(['invite_user_id' => $code->user_id]);
                $code->increment('pv');
                
                $referrer = User::find($code->user_id);
                $referrerName = $referrer ? $referrer->email : 'ناشناس';
                $this->sendMessage($chatId, "✅ شما با کد دعوت *{$inviteCode}* وارد شدید.\n👤 معرف: {$referrerName}", null, null, 'Markdown');
            }
        }
        // پشتیبانی از فرمت قدیمی ref_
        elseif (\Illuminate\Support\Str::startsWith($param, 'ref_')) {
            $refCode = \Illuminate\Support\Str::after($param, 'ref_');
            $referrer = User::where('bot_ref_code', $refCode)->first();
            
            if ($referrer && $referrer->id !== $this->user->id && !$this->user->invite_user_id) {
                $this->user->update(['invite_user_id' => $referrer->id]);
            }
        }
        // پردازش bind برای اتصال حساب
        elseif (\Illuminate\Support\Str::startsWith($param, 'bind_')) {
            $token = \Illuminate\Support\Str::after($param, 'bind_');
            $existingUser = User::where('token', $token)->first();
            
            if ($existingUser && !$existingUser->telegram_id) {
                $existingUser->update(['telegram_id' => $chatId]);
                $this->user = $existingUser;
                $this->sendMessage($chatId, "✅ حساب شما با موفقیت به تلگرام متصل شد!");
            }
        }
        
        $this->sendMainMenu($chatId);
    }

    /**
     * نمایش جزئیات پلن
     */
    protected function showPlanDetails(int $chatId, int $messageId, int $planId): void
    {
        $plan = Plan::find($planId);

        if (!$plan) {
            $this->sendMessage($chatId, "❌ پلن یافت نشد.");
            return;
        }

        $text = "📋 *{$plan->name}*\n\n";
        $text .= "📊 حجم: " . $this->formatBytes($plan->transfer_enable * 1073741824) . "\n";
        
        if ($plan->month_price) $text .= "💰 ماهانه: " . number_format($plan->month_price) . " تومان\n";
        if ($plan->quarter_price) $text .= "💰 سه ماهه: " . number_format($plan->quarter_price) . " تومان\n";
        if ($plan->half_year_price) $text .= "💰 شش ماهه: " . number_format($plan->half_year_price) . " تومان\n";
        if ($plan->year_price) $text .= "💰 سالانه: " . number_format($plan->year_price) . " تومان\n";

        $buttons = [];
        if ($plan->month_price) $buttons[] = [['text' => '🛒 خرید ماهانه', 'callback_data' => "buy_{$planId}_month"]];
        if ($plan->quarter_price) $buttons[] = [['text' => '🛒 خرید سه ماهه', 'callback_data' => "buy_{$planId}_quarter"]];
        if ($plan->half_year_price) $buttons[] = [['text' => '🛒 خرید شش ماهه', 'callback_data' => "buy_{$planId}_half_year"]];
        if ($plan->year_price) $buttons[] = [['text' => '🛒 خرید سالانه', 'callback_data' => "buy_{$planId}_year"]];
        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'plans_list']];

        $this->deleteMessage($chatId, $messageId);
        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * خرید پلن
     */
    protected function handleBuyPlan(int $chatId, string $data): void
    {
        // parse data: buy_planId_period
        $parts = explode("_", $data);
        if (count($parts) < 3) {
            $this->sendMessage($chatId, "❌ خطا در پردازش درخواست.");
            return;
        }
        $planId = (int) $parts[1];
        $period = $parts[2];

        $orderService = new TelegramOrderService($this->user);
        $result = $orderService->createPlanOrder($planId, $period);

        if (!$result["success"]) {
            $this->sendMessage($chatId, "❌ " . $result["message"]);
            return;
        }

        if ($result["paid"]) {
            $this->sendMessage($chatId, "✅ " . $result["message"] . "\n\nسرویس شما فعال شد!");
            return;
        }

        $tradeNo = $result["trade_no"];
        $amount = $result["amount"];
        
        // ذخیره اطلاعات سفارش برای مرحله بعد
        $this->user->update(['bot_data' => json_encode(['trade_no' => $tradeNo, 'amount' => $amount])]);

        // پرسش کد تخفیف
        $text = "🛒 سفارش ایجاد شد\n\n";
        $text .= "💰 مبلغ: " . number_format($amount) . " تومان\n\n";
        $text .= "🎫 آیا کد تخفیف دارید؟";

        $buttons = [
            [['text' => '✅ بله، کد تخفیف دارم', 'callback_data' => 'coupon_yes_' . $tradeNo]],
            [['text' => '❌ خیر، ادامه پرداخت', 'callback_data' => 'coupon_no_' . $tradeNo]],
            [['text' => '🗑 لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]]
        ];

        $this->sendMessage($chatId, $text, null, $buttons);
    }

    /**
     * ساخت اکانت تست
     */
    protected function createTestAccount(int $chatId, int $panelId): void
    {
        $panel = BotPanel::find($panelId);

        if (!$panel || !$panel->test_enabled) {
            $this->sendMessage($chatId, "❌ این پنل در حال حاضر فعال نیست.");
            return;
        }

        $this->user->increment('bot_test_count');
        $this->sendMessage($chatId, "✅ اکانت تست شما ایجاد شد!\n\n⏳ مدت: 1 روز\n📊 حجم: 500 مگابایت\n\n🔗 لینک اتصال به زودی ارسال می‌شود...");
    }

    /**
     * ارسال لینک اشتراک
     */
    protected function sendSubscriptionLink(int $chatId): void
    {
        $user = $this->user;
        
        // بررسی اشتراک
        if (!$user->plan_id || !$user->expired_at || $user->expired_at < time()) {
            $this->sendMessage($chatId, "❌ شما هنوز هیچ اشتراک فعالی ندارید.\n\nبرای خرید اشتراک از منوی 🛒 خرید اشتراک استفاده کنید.");
            return;
        }
        
        $subUrl = config('v2board.subscribe_url') ?: config('app.url');
        $link = "{$subUrl}/api/v1/client/subscribe?token={$user->token}";

        $text = "🔗 *لینک اشتراک شما:*\n\n`{$link}`\n\n📱 این لینک را در اپلیکیشن خود کپی کنید.";

        $buttons = [
            [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
        ];
        
        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * پردازش پرداخت
     */
    protected function handlePayment(int $chatId, string $data): void
    {
        // parse: pay_tradeNo_paymentId
        $parts = explode('_', $data);
        if (count($parts) < 3) {
            $this->sendMessage($chatId, '❌ خطا در پردازش درخواست.');
            return;
        }

        $tradeNo = $parts[1];
        $paymentId = (int) $parts[2];

        $orderService = new TelegramOrderService($this->user);
        $result = $orderService->getPaymentLink($tradeNo, $paymentId);

        if (!$result['success']) {
            $this->sendMessage($chatId, '❌ ' . $result['message']);
            return;
        }

        // type 1 = redirect URL
        if ($result['type'] == 1) {
            $buttons = [
                [['text' => '💳 پرداخت آنلاین', 'url' => $result['data']]],
                [['text' => '❌ لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]]
            ];
            $this->sendMessage($chatId, "🔗 برای پرداخت روی دکمه زیر کلیک کنید:", null, $buttons);
        } elseif ($result['type'] == 0) {
            $d = $result['data'];
            $text = "💳 *پرداخت کارت به کارت*\n\n";
            $text .= "━━━━━━━━━━━━━━\n";
            $text .= "🏦 بانک: " . ($d['bank_name'] ?? '-') . "\n";
            $text .= "💳 شماره کارت:\n`" . ($d['card_number'] ?? '----') . "`\n";
            $text .= "👤 صاحب کارت: " . ($d['card_holder'] ?? '-') . "\n";
            $text .= "━━━━━━━━━━━━━━\n";
            $text .= "💰 مبلغ: *" . number_format($d['amount_toman'] ?? 0) . " تومان*\n";
            $text .= "⏱ مهلت: " . (int)(($d['remaining_seconds'] ?? 1800) / 60) . " دقیقه\n\n";
            $text .= "⚠️ لطفاً دقیقاً همین مبلغ را واریز کنید\n\n";
            $text .= "📸 *پس از واریز، عکس رسید بانکی را ارسال کنید*";
            $this->user->update([
                'bot_step' => 'waiting_card_receipt',
                'bot_data' => json_encode(['trade_no' => $d['trade_no'], 'payment_id' => $d['payment_id'], 'amount' => $d['amount_toman']])
            ]);
            $buttons = [
                [['text' => '❌ انصراف از پرداخت', 'callback_data' => 'cancel_order_' . $tradeNo]]
            ];
            $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
        } else {
            $this->sendMessage($chatId, "✅ درخواست پرداخت ارسال شد.\n\n" . json_encode($result['data']));
        }
    }

    /**
     * لغو سفارش
     */
    protected function handleCancelOrder(int $chatId, string $tradeNo): void
    {
        $orderService = new TelegramOrderService($this->user);
        $result = $orderService->cancelOrder($tradeNo);

        if ($result['success']) {
            $this->sendMessage($chatId, '✅ ' . $result['message']);
        } else {
            $this->sendMessage($chatId, '❌ ' . $result['message']);
        }
    }

    /**
     * بررسی دکمه منو
     */
    protected function isMenuButton(string $text): bool
    {
        $menuButtons = [
            '🏠 منوی اصلی',
            '🛒 خرید اشتراک',
            '👤 حساب کاربری',
            '🎁 اکانت تست',
            '💰 کیف پول',
            '📦 سرویس‌های من',
            '🎁 گیفت کارت',
            '👥 زیرمجموعه',
            '📞 پشتیبانی',
            '📚 راهنما',
            '/start'
        ];
        return in_array($text, $menuButtons);
    }

    /**
     * تمدید اشتراک
     */
    protected function handleRenew(int $chatId, int $planId): void
    {
        $plan = Plan::find($planId);
        
        if (!$plan) {
            $this->sendMessage($chatId, '❌ پلن یافت نشد.');
            return;
        }

        $text = "🔄 *تمدید اشتراک: {$plan->name}*\n\n";
        $text .= "دوره تمدید را انتخاب کنید:";

        $buttons = [];
        if ($plan->month_price) $buttons[] = [['text' => '📅 ماهانه - ' . number_format($plan->month_price) . ' تومان', 'callback_data' => "buy_{$planId}_month"]];
        if ($plan->quarter_price) $buttons[] = [['text' => '📅 سه ماهه - ' . number_format($plan->quarter_price) . ' تومان', 'callback_data' => "buy_{$planId}_quarter"]];
        if ($plan->half_year_price) $buttons[] = [['text' => '📅 شش ماهه - ' . number_format($plan->half_year_price) . ' تومان', 'callback_data' => "buy_{$planId}_half_year"]];
        if ($plan->year_price) $buttons[] = [['text' => '📅 سالانه - ' . number_format($plan->year_price) . ' تومان', 'callback_data' => "buy_{$planId}_year"]];
        $buttons[] = [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * درخواست کد تخفیف
     */
    protected function askForCouponCode(int $chatId, string $tradeNo): void
    {
        $this->user->update([
            'bot_step' => 'enter_coupon',
            'bot_data' => json_encode(['trade_no' => $tradeNo])
        ]);
        
        $buttons = [
            [['text' => '❌ انصراف', 'callback_data' => 'coupon_no_' . $tradeNo]]
        ];
        
        $this->sendMessage($chatId, "🎫 لطفاً کد تخفیف خود را وارد کنید:", null, $buttons);
    }

    /**
     * پردازش کد تخفیف
     */
    protected function processCouponCode(int $chatId, string $code): void
    {
        $botData = json_decode($this->user->bot_data, true);
        $tradeNo = $botData['trade_no'] ?? null;
        
        if (!$tradeNo) {
            $this->sendMessage($chatId, "❌ خطا در پردازش. لطفاً دوباره سفارش دهید.");
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        $order = \App\Models\Order::where('trade_no', $tradeNo)->where('user_id', $this->user->id)->first();
        if (!$order) {
            $this->sendMessage($chatId, "❌ سفارش یافت نشد.");
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        $coupon = \App\Models\Coupon::where('code', $code)->first();
        
        if (!$coupon) {
            $this->sendMessage($chatId, "❌ کد تخفیف نامعتبر است. دوباره تلاش کنید یا روی انصراف بزنید.");
            return;
        }

        // بررسی تاریخ
        $now = time();
        if ($coupon->started_at && $coupon->started_at > $now) {
            $this->sendMessage($chatId, "❌ این کد تخفیف هنوز فعال نشده است.");
            return;
        }
        if ($coupon->ended_at && $coupon->ended_at < $now) {
            $this->sendMessage($chatId, "❌ این کد تخفیف منقضی شده است.");
            return;
        }

        // بررسی محدودیت پلن
        if ($coupon->limit_plan_ids) {
            $limitPlanIds = json_decode($coupon->limit_plan_ids, true) ?? [];
            if (!empty($limitPlanIds) && !in_array($order->plan_id, $limitPlanIds)) {
                $this->sendMessage($chatId, "❌ این کد تخفیف برای پلن انتخابی شما قابل استفاده نیست.");
                return;
            }
        }

        // محاسبه تخفیف
        $originalAmount = $order->total_amount;
        if ($coupon->type == 1) {
            // درصدی
            $discount = round($originalAmount * $coupon->value / 100);
            $discountText = $coupon->value . '%';
        } else {
            // مبلغ ثابت
            $discount = $coupon->value;
            $discountText = number_format($coupon->value) . ' تومان';
        }

        $newAmount = max(0, $originalAmount - $discount);

        // اعمال تخفیف به سفارش
        $order->discount_amount = $discount;
        $order->total_amount = $newAmount;
        $order->coupon_id = $coupon->id;
        $order->save();

        $this->user->update(['bot_step' => null, 'bot_data' => null]);

        $text = "✅ کد تخفیف اعمال شد!\n\n";
        $text .= "💰 مبلغ اولیه: " . number_format($originalAmount) . " تومان\n";
        $text .= "🎫 تخفیف: " . $discountText . " (" . number_format($discount) . " تومان)\n";
        $text .= "💵 مبلغ نهایی: " . number_format($newAmount) . " تومان\n\n";

        if ($newAmount <= 0) {
            // پرداخت رایگان
            $orderService = new \App\Services\OrderService($order);
            $orderService->paid($tradeNo);
            $text .= "🎉 سفارش شما رایگان شد و فعال گردید!";
            $this->sendMessage($chatId, $text);
        } else {
            $text .= "درگاه پرداخت را انتخاب کنید:";
            $payments = TelegramOrderService::getActivePayments();
            
            $buttons = [];
            foreach ($payments as $p) {
                $buttons[] = [['text' => '💳 ' . $p['name'], 'callback_data' => 'pay_' . $tradeNo . '_' . $p['id']]];
            }
            $buttons[] = [['text' => '❌ لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]];

            $this->sendMessage($chatId, $text, null, $buttons);
        }
    }

    /**
     * نمایش درگاه‌های پرداخت
     */
    protected function showPaymentGateways(int $chatId, string $tradeNo): void
    {
        $order = \App\Models\Order::where('trade_no', $tradeNo)->where('user_id', $this->user->id)->first();
        
        if (!$order) {
            $this->sendMessage($chatId, "❌ سفارش یافت نشد.");
            return;
        }

        $this->user->update(['bot_step' => null, 'bot_data' => null]);

        $amount = $order->total_amount;
        $payments = TelegramOrderService::getActivePayments();

        $text = "💰 مبلغ قابل پرداخت: " . number_format($amount) . " تومان\n\n";
        $text .= "درگاه پرداخت را انتخاب کنید:";

        $buttons = [];
        foreach ($payments as $p) {
            $buttons[] = [['text' => '💳 ' . $p['name'], 'callback_data' => 'pay_' . $tradeNo . '_' . $p['id']]];
        }
        $buttons[] = [['text' => '❌ لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]];

        $this->sendMessage($chatId, $text, null, $buttons);
    }

    /**
     * ساخت کد دعوت جدید
     */
    protected function createInviteCode(int $chatId): void
    {
        $user = $this->user;
        $maxCodes = config('v2board.invite_gen_limit', 5);
        
        $currentCount = \App\Models\InviteCode::where('user_id', $user->id)
            ->where('status', 0)
            ->count();
        
        if ($currentCount >= $maxCodes) {
            $this->sendMessage($chatId, "❌ شما به حداکثر تعداد کد دعوت ({$maxCodes} کد) رسیده‌اید.");
            return;
        }
        
        $inviteCode = new \App\Models\InviteCode();
        $inviteCode->user_id = $user->id;
        $inviteCode->code = \App\Utils\Helper::randomChar(8);
        $inviteCode->save();
        
        $this->sendMessage($chatId, "✅ کد دعوت جدید ساخته شد: `{$inviteCode->code}`", null, null, 'Markdown');
        
        // نمایش مجدد اطلاعات زیرمجموعه
        $this->sendAffiliateInfo($chatId);
    }

    /**
     * انتقال کمیسیون به کیف پول
     */
    protected function transferCommission(int $chatId): void
    {
        $user = $this->user;
        $commission = $user->commission_balance ?? 0;
        
        if ($commission <= 0) {
            $this->sendMessage($chatId, "❌ موجودی کمیسیون شما صفر است.");
            return;
        }
        
        $minWithdraw = config('v2board.withdraw_close_enable', 0) ? PHP_INT_MAX : 0;
        
        // انتقال به کیف پول
        $user->balance += $commission;
        $user->commission_balance = 0;
        $user->save();
        
        $this->sendMessage($chatId, "✅ مبلغ " . number_format($commission) . " تومان به کیف پول شما منتقل شد.\n\n💵 موجودی جدید: " . number_format($user->balance) . " تومان");
    }

    /**
     * اتصال اشتراک سایت به ربات (bind)
     */
    protected function handleBind(int $chatId, string $subscribeUrl): void
    {
        // پارس کردن URL
        $parsed = parse_url(trim($subscribeUrl));
        if (!isset($parsed['query'])) {
            $this->sendMessage($chatId, "❌ لینک اشتراک نامعتبر است.\n\nفرمت صحیح:\n/bind " . config("v2board.frontend_url") . "/api/v1/client/subscribe?token=YOUR_TOKEN");
            return;
        }
        
        parse_str($parsed['query'], $query);
        $token = $query['token'] ?? null;
        
        if (!$token) {
            $this->sendMessage($chatId, "❌ توکن در لینک یافت نشد.");
            return;
        }
        
        // بررسی روش اشتراک
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        
        switch ($submethod) {
            case 1:
                if (!\Illuminate\Support\Facades\Cache::has("otpn_{$token}")) {
                    $this->sendMessage($chatId, "❌ توکن نامعتبر است.");
                    return;
                }
                $token = \Illuminate\Support\Facades\Cache::get("otpn_{$token}");
                break;
            case 2:
                $usertoken = \Illuminate\Support\Facades\Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = \App\Utils\Helper::base64DecodeUrlSafe($token);
                    $parts = explode(':', $idhash, 2);
                    if (count($parts) !== 2) {
                        $this->sendMessage($chatId, "❌ توکن نامعتبر است.");
                        return;
                    }
                    [$userid, $clienthash] = $parts;
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        $this->sendMessage($chatId, "❌ کاربر یافت نشد.");
                        return;
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        $this->sendMessage($chatId, "❌ توکن نامعتبر است.");
                        return;
                    }
                    \Illuminate\Support\Facades\Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
        }
        
        // پیدا کردن کاربر سایت
        $siteUser = User::where('token', $token)->first();
        
        if (!$siteUser) {
            $this->sendMessage($chatId, "❌ کاربر با این توکن یافت نشد.");
            return;
        }
        
        // بررسی اینکه آیا این اکانت سایت قبلاً به تلگرام دیگری متصل شده
        if ($siteUser->telegram_id && $siteUser->telegram_id != $chatId) {
            $this->sendMessage($chatId, "❌ این اکانت قبلاً به یک حساب تلگرام دیگر متصل شده است.\n\nبرای تغییر، ابتدا از طریق آن حساب /unbind کنید.");
            return;
        }
        
        // اگر کاربر فعلی تلگرام یک یوزر tg_ است، آن را حذف یا غیرفعال کنیم
        $currentTgUser = User::where('telegram_id', $chatId)->first();
        if ($currentTgUser && $currentTgUser->id !== $siteUser->id) {
            // حذف telegram_id از یوزر قبلی (tg_xxx)
            $currentTgUser->telegram_id = null;
            $currentTgUser->save();
        }
        
        // اتصال کاربر سایت به تلگرام
        $siteUser->telegram_id = $chatId;
        $siteUser->save();
        
        // بروزرسانی user فعلی
        $this->user = $siteUser;
        
        $this->sendMessage($chatId, "✅ اتصال با موفقیت انجام شد!\n\n📧 ایمیل: {$siteUser->email}\n\nاکنون می‌توانید از امکانات ربات استفاده کنید.");
        $this->sendMainMenu($chatId);
    }

    /**
     * قطع اتصال (unbind)
     */
    protected function handleUnbind(int $chatId): void
    {
        $user = $this->user;
        
        // بررسی اینکه آیا یوزر tg_ است
        if (Str::startsWith($user->email, 'tg_')) {
            $this->sendMessage($chatId, "❌ شما هنوز اکانت سایت را متصل نکرده‌اید.\n\nبرای اتصال از دستور /bind استفاده کنید.");
            return;
        }
        
        $user->telegram_id = null;
        $user->save();

        // رفرش پس از unbind: بازگشت فوری به حساب تلگرامی و منوی جدید (بدون نیاز به /start)
        $this->sendMessage($chatId, "✅ اتصال قطع شد. به حساب تلگرامی خود برگشتید.");
        $this->user = $this->findOrCreateUser(['id' => $chatId]);
        $this->sendMainMenu($chatId);
        return;
        
        $this->sendMessage($chatId, "✅ اتصال شما قطع شد.\n\nبرای استفاده مجدد، دوباره /start بزنید.");
    }

    /**
     * اطلاعات ترافیک
     */
    protected function sendTrafficInfo(int $chatId): void
    {
        $user = $this->user;
        
        if (!$user->plan_id) {
            $this->sendMessage($chatId, "❌ شما اشتراک فعالی ندارید.");
            return;
        }
        
        $used = $user->u + $user->d;
        $total = $user->transfer_enable;
        $remaining = max(0, $total - $used);
        
        $usedGB = round($used / 1073741824, 2);
        $totalGB = round($total / 1073741824, 2);
        $remainingGB = round($remaining / 1073741824, 2);
        $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0;
        
        $text = "📊 *وضعیت ترافیک*\n\n";
        $text .= "📈 مصرف شده: {$usedGB} GB\n";
        $text .= "📉 باقیمانده: {$remainingGB} GB\n";
        $text .= "📦 کل ترافیک: {$totalGB} GB\n";
        $text .= "📊 درصد مصرف: {$percent}%\n";
        
        if ($user->expired_at) {
            $expireDate = $this->jalaliDate($user->expired_at, 'Y/m/d');
            $daysLeft = max(0, ceil(($user->expired_at - time()) / 86400));
            $text .= "\n⏰ انقضا: {$expireDate} ({$daysLeft} روز مانده)";
        }
        
        $buttons = [
            [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
        ];
        
        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }

    /**
     * اجرای دستورات داینامیک از پوشه Commands
     */
    protected function executePluginCommand(int $chatId, string $text): void
    {
        // جدا کردن دستور و آرگومان‌ها
        $parts = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $args = isset($parts[1]) ? explode(' ', $parts[1]) : [];

        // مپ کردن دستورات به کلاس‌ها
        $commandsPath = app_path('Plugins/Telegram/Commands');
        $commandClasses = [];
        
        foreach (glob($commandsPath . '/*.php') as $file) {
            $className = 'App\\Plugins\\Telegram\\Commands\\' . basename($file, '.php');
            if (class_exists($className)) {
                $instance = new $className();
                if (isset($instance->command)) {
                    $commandClasses[strtolower($instance->command)] = $className;
                }
            }
        }

        // بررسی وجود دستور
        if (!isset($commandClasses[$command])) {
            // بررسی دستورات با پارامتر (مثل /start xxx)
            $baseCommand = $command;
            if (!isset($commandClasses[$baseCommand])) {
                $this->sendMainMenu($chatId);
                return;
            }
        }

        $className = $commandClasses[$command] ?? $commandClasses[$baseCommand] ?? null;
        
        if (!$className) {
            $this->sendMainMenu($chatId);
            return;
        }

        try {
            // ساخت شیء message برای سازگاری با Commands قدیمی
            $message = new \stdClass();
            $message->chat_id = $chatId;
            $message->is_private = true;
            $message->args = $args;
            $message->text = $text;
            $message->message_id = 0;
            
            // ساخت نمونه از کلاس دستور
            $commandInstance = new $className();
            
            // تنظیم telegramService - استفاده از TelegramService اصلی
            // telegramService قبلاً در constructor ست شده، فقط override می‌کنیم با wrapper
            $botService = $this;
            $commandInstance->telegramService = new class($botService) {
                private $bot;
                public function __construct($bot) { $this->bot = $bot; }
                public function sendMessage($chatId, $text, $parseMode = '') {
                    $pm = ($parseMode === 'markdown' || $parseMode === 'Markdown') ? 'Markdown' : '';
                    return $this->bot->sendMessage($chatId, $text, null, null, $pm);
                }
                public function sendMessageWithKeyboard($chatId, $text, $keyboard, $parseMode = '') {
                    $pm = ($parseMode === 'markdown' || $parseMode === 'Markdown') ? 'Markdown' : '';
                    return $this->bot->sendMessage($chatId, $text, null, $keyboard, $pm);
                }
                public function getMe() { return null; }
            };
            
            // اجرای دستور
            $commandInstance->handle($message, $args);
            
        } catch (\Exception $e) {
            \Log::error('Plugin Command Error: ' . $e->getMessage());
            
            // اگر خطا داشت، خودمان handle کنیم
            if ($command === '/start' && !empty($args)) {
                $this->handleStartParam($chatId, $args[0]);
            } elseif ($command === '/bind' && !empty($args)) {
                $this->handleBind($chatId, $args[0]);
            } elseif ($command === '/unbind') {
                $this->handleUnbind($chatId);
            } elseif ($command === '/traffic') {
                $this->sendTrafficInfo($chatId);
            } else {
                $this->sendMainMenu($chatId);
            }
        }
    }

    /**
     * اتصال با توکن مستقیم (برای لینک start)
     */
    protected function handleBindByToken(int $chatId, string $token): void
    {
        // پیدا کردن کاربر سایت
        $siteUser = User::where('token', $token)->first();
        
        if (!$siteUser) {
            $this->sendMessage($chatId, "❌ توکن نامعتبر است.");
            $this->sendMainMenu($chatId);
            return;
        }
        
        // بررسی اینکه آیا این اکانت سایت قبلاً به تلگرام دیگری متصل شده
        if ($siteUser->telegram_id && $siteUser->telegram_id != $chatId) {
            $this->sendMessage($chatId, "❌ این اکانت قبلاً به یک حساب تلگرام دیگر متصل شده است.\n\nبرای تغییر، ابتدا از طریق آن حساب /unbind کنید.");
            $this->sendMainMenu($chatId);
            return;
        }
        
        // اگر کاربر فعلی تلگرام یک یوزر tg_ است، آن را حذف یا غیرفعال کنیم
        $currentTgUser = User::where('telegram_id', $chatId)->first();
        if ($currentTgUser && $currentTgUser->id !== $siteUser->id) {
            $currentTgUser->telegram_id = null;
            $currentTgUser->save();
        }
        
        // اتصال کاربر سایت به تلگرام
        $siteUser->telegram_id = $chatId;
        $siteUser->save();
        
        // بروزرسانی user فعلی
        $this->user = $siteUser;
        
        $this->sendMessage($chatId, "✅ اتصال با موفقیت انجام شد!\n\n📧 ایمیل: {$siteUser->email}\n\nاکنون می‌توانید از امکانات ربات استفاده کنید.");
        $this->sendMainMenu($chatId);
    }

    /**
     * شروع ارسال تیکت جدید
     */
    protected function startNewTicket(int $chatId): void
    {
        // بررسی تیکت باز
        $openTicket = \App\Models\Ticket::where('user_id', $this->user->id)
            ->where('status', 0)
            ->first();
        
        if ($openTicket) {
            $this->sendMessage($chatId, "❌ شما یک تیکت باز دارید.\n\nلطفاً ابتدا تیکت قبلی را ببندید یا منتظر پاسخ باشید.");
            return;
        }
        
        // بررسی وضعیت تیکت
        $ticketStatus = config('v2board.ticket_status', 0);
        
        if ($ticketStatus == 2) {
            $this->sendMessage($chatId, "❌ ارسال تیکت غیرفعال است.");
            return;
        }
        
        if ($ticketStatus == 1) {
            $hasOrder = \App\Models\Order::where('user_id', $this->user->id)
                ->whereIn('status', [3, 4])
                ->exists();
            if (!$hasOrder) {
                $this->sendMessage($chatId, "❌ فقط کاربران دارای اشتراک می‌توانند تیکت ارسال کنید.");
                return;
            }
        }
        
        $this->user->update(['bot_step' => 'ticket_subject', 'bot_data' => null]);
        
        $this->sendMessage($chatId, "📩 ارسال تیکت جدید\n\n📋 موضوع تیکت را وارد کنید:\n\n(برای انصراف /cancel بزنید)");
    }
    
    /**
     * درخواست متن پیام تیکت
     */
    protected function askTicketMessage(int $chatId): void
    {
        $botData = json_decode($this->user->bot_data ?? '{}', true);
        $this->user->update(['bot_step' => 'ticket_message', 'bot_data' => json_encode($botData)]);
        
        $this->sendMessage($chatId, "📝 متن پیام خود را وارد کنید:\n\n(برای انصراف /cancel بزنید)");
    }
    
    /**
     * ارسال تیکت بدون پیام اضافی
     */
    protected function sendTicketNow(int $chatId): void
    {
        $botData = json_decode($this->user->bot_data ?? '{}', true);
        $subject = $botData['subject'] ?? 'بدون موضوع';
        
        $this->user->update(['bot_step' => null, 'bot_data' => null]);
        
        // ساخت تیکت با اولویت بالا (level=2) و بدون پیام اضافی
        $this->createTicket($chatId, $subject, $subject, 2);
    }
    
    /**
     * پردازش مراحل تیکت
     */
    protected function handleTicketStep(int $chatId, string $text, string $step): void
    {
        $botData = json_decode($this->user->bot_data ?? '{}', true);
        
        if ($step === 'ticket_subject') {
            if (mb_strlen($text) < 3) {
                $this->sendMessage($chatId, "❌ موضوع باید حداقل ۳ کاراکتر باشد.");
                return;
            }
            
            $botData['subject'] = $text;
            $this->user->update([
                'bot_step' => null,
                'bot_data' => json_encode($botData)
            ]);
            
            $buttons = [
                [['text' => '✅ بله', 'callback_data' => 'ticket_add_message']],
                [['text' => '❌ خیر، ارسال تیکت', 'callback_data' => 'ticket_send_now']]
            ];
            
            $this->sendMessage($chatId, "📋 موضوع: {$text}\n\n💬 حرف دیگری هم دارید؟", null, $buttons);
            
        } elseif ($step === 'ticket_message') {
            $botData['message'] = $text;
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            
            // ساخت تیکت با اولویت بالا (level=2)
            $this->createTicket($chatId, $botData['subject'] ?? 'بدون موضوع', $text, 2);
        }
    }
    
    /**
     * ساخت تیکت در دیتابیس
     */
    protected function handleRemindAdmin(int $chatId, int $paymentId, string $callbackId): void
    {
        $cp = \App\Models\CardPayment::where('id', $paymentId)
            ->where('user_id', $this->user->id)
            ->where('status', \App\Models\CardPayment::STATUS_CLAIMED)
            ->first();
        if (!$cp) {
            $this->answerCallback($callbackId, 'این سفارش دیگر در حال بررسی نیست.');
            return;
        }
        $already = \App\Models\TicketMessage::where('user_id', $this->user->id)
            ->where('message', 'like', '%' . $cp->trade_no . '%')
            ->exists();
        if ($already) {
            $this->answerCallback($callbackId, 'برای این سفارش قبلاً یادآوری ثبت شده است.');
            return;
        }
        $this->createTicket($chatId, 'یادآوری', "لطفاً وضعیت پرداخت کارت‌به‌کارت با شماره سفارش {$cp->trade_no} را بررسی کنید.", 2);
        $this->answerCallback($callbackId, 'یادآوری برای ادمین ارسال شد');
    }

    protected function createTicket(int $chatId, string $subject, string $message, int $level): void
    {
        try {
            \DB::beginTransaction();
            
            $ticket = \App\Models\Ticket::create([
                'user_id' => $this->user->id,
                'subject' => $subject,
                'level' => $level,
                'status' => 0,
                'reply_status' => 0
            ]);
            
            \App\Models\TicketMessage::create([
                'user_id' => $this->user->id,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            
            \DB::commit();
            
            // پاک کردن step
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            
            // ارسال اعلان به ادمین
            $this->sendTicketNotifyToAdmin($ticket, $message);
            
            $levelText = ['🟢 پایین', '🟡 متوسط', '🔴 بالا'][$level] ?? 'متوسط';
            
            $this->sendMessage($chatId, "✅ تیکت شما با موفقیت ارسال شد!\n\n" .
                "🎫 شماره تیکت: #{$ticket->id}\n" .
                "📋 موضوع: {$subject}\n" .
                "⚡ اولویت: {$levelText}\n\n" .
                "پاسخ از طریق پنل کاربری ارسال خواهد شد.");
            
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Ticket creation failed: ' . $e->getMessage());
            $this->sendMessage($chatId, "❌ خطا در ارسال تیکت. لطفاً دوباره تلاش کنید.");
        }
    }
    
    /**
     * ارسال اعلان تیکت به ادمین
     */
    protected function sendTicketNotifyToAdmin(\App\Models\Ticket $ticket, string $message): void
    {
        try {
            $user = $this->user;
            $plan = \App\Models\Plan::find($user->plan_id);
            $planName = $plan ? $plan->name : 'بدون اشتراک';
            
            $transferEnable = $this->formatBytes($user->transfer_enable ?? 0);
            $used = $this->formatBytes(($user->u ?? 0) + ($user->d ?? 0));
            $balance = number_format($user->balance ?? 0);
            $expiredAt = $user->expired_at ? $this->jalaliDate($user->expired_at, 'Y/m/d') : 'ندارد';
            
            $levelText = ['🟢 پایین', '🟡 متوسط', '🔴 بالا'][$ticket->level] ?? 'متوسط';
            
            $text = "📮 *تیکت جدید* #{$ticket->id}\n";
            $text .= "———————————————\n";
            $text .= "👤 کاربر: `{$user->email}`\n";
            $text .= "📦 اشتراک: {$planName}\n";
            $text .= "📊 مصرف: {$used} / {$transferEnable}\n";
            $text .= "💰 موجودی: {$balance} تومان\n";
            $text .= "📅 انقضا: {$expiredAt}\n";
            $text .= "———————————————\n";
            $text .= "⚡ اولویت: {$levelText}\n";
            $text .= "📋 موضوع: {$ticket->subject}\n";
            $text .= "———————————————\n";
            $text .= "💬 پیام:\n{$message}";
            
            $telegramService = new \App\Services\TelegramService();
            $telegramService->sendMessageToAdminsBySwitch($text);
            
        } catch (\Exception $e) {
            \Log::error('Failed to send ticket notify: ' . $e->getMessage());
        }
    }

    /**
     * نمایش لیست تیکت‌های کاربر
     */
    protected function showMyTickets(int $chatId): void
    {
        $tickets = \App\Models\Ticket::where('user_id', $this->user->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();
        
        if ($tickets->isEmpty()) {
            $this->sendMessage($chatId, "📋 شما هیچ تیکتی ندارید.", null, [
                [['text' => '📩 ارسال تیکت جدید', 'callback_data' => 'new_ticket']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']]
            ]);
            return;
        }
        
        $text = "📋 تیکت‌های شما:\n\n";
        $buttons = [];
        
        foreach ($tickets as $ticket) {
            $statusIcon = $ticket->status == 0 ? '🟢' : '🔴';
            $replyIcon = $ticket->reply_status == 1 ? '💬' : '';
            $date = \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::createFromTimestamp($ticket->created_at, 'Asia/Tehran'))->format('Y/m/d H:i');
            
            $text .= "{$statusIcon} #{$ticket->id} - {$ticket->subject} {$replyIcon}\n";
            $text .= "   📅 {$date}\n\n";
            
            $buttons[] = [['text' => "{$statusIcon} #{$ticket->id} - {$ticket->subject}", 'callback_data' => "view_ticket_{$ticket->id}"]];
        }
        
        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']];
        
        $this->sendMessage($chatId, $text, null, $buttons);
    }
    
    /**
     * مشاهده یک تیکت
     */
    protected function viewTicket(int $chatId, int $ticketId): void
    {
        $ticket = \App\Models\Ticket::where('id', $ticketId)
            ->where('user_id', $this->user->id)
            ->first();
        
        if (!$ticket) {
            $this->sendMessage($chatId, "❌ تیکت یافت نشد.");
            return;
        }
        
        $messages = \App\Models\TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('created_at', 'asc')
            ->get();
        
        $statusText = $ticket->status == 0 ? '🟢 باز' : '🔴 بسته';
        
        $text = "🎫 تیکت #{$ticket->id}\n";
        $text .= "📋 موضوع: {$ticket->subject}\n";
        $text .= "📊 وضعیت: {$statusText}\n";
        $text .= "━━━━━━━━━━━━━━━\n\n";
        
        foreach ($messages as $msg) {
            $isAdmin = $msg->user_id != $this->user->id;
            $sender = $isAdmin ? '👨‍💼 پشتیبانی' : '👤 شما';
            $date = \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::createFromTimestamp($msg->created_at, 'Asia/Tehran'))->format('Y/m/d H:i');
            
            $text .= "{$sender} ({$date}):\n";
            $text .= "{$msg->message}\n\n";
        }
        
        if ($ticket->status == 0) {
            $buttons = [
                [['text' => '💬 پاسخ دادن', 'callback_data' => "reply_ticket_{$ticketId}"]],
                [['text' => '🔙 بازگشت', 'callback_data' => 'my_tickets']]
            ];
        } else {
            $text .= "⚠️ این تیکت بسته شده و امکان پاسخ وجود ندارد.";
            $buttons = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'my_tickets']]
            ];
        }
        
        $this->sendMessage($chatId, $text, null, $buttons);
    }
    
    /**
     * شروع پاسخ به تیکت
     */
    protected function startReplyTicket(int $chatId, int $ticketId): void
    {
        $ticket = \App\Models\Ticket::where('id', $ticketId)
            ->where('user_id', $this->user->id)
            ->where('status', 0)
            ->first();
        
        if (!$ticket) {
            $this->sendMessage($chatId, "❌ تیکت یافت نشد یا بسته شده است.");
            return;
        }
        
        $this->user->update([
            'bot_step' => 'ticket_reply',
            'bot_data' => json_encode(['ticket_id' => $ticketId])
        ]);
        
        $this->sendMessage($chatId, "💬 پاسخ خود را برای تیکت #{$ticketId} بنویسید:\n\n(برای انصراف /cancel بزنید)");
    }
    
    /**
     * پردازش پاسخ تیکت
     */
    protected function handleAdminTicketReply(int $chatId, int $ticketId, string $message): void
    {
        if (!$this->user || !$this->user->is_admin) {
            return;
        }
        $ticket = \App\Models\Ticket::find($ticketId);
        if (!$ticket) {
            $this->sendMessage($chatId, "❌ تیکت #{$ticketId} یافت نشد.");
            return;
        }
        try {
            $service = new \App\Services\TicketService();
            $service->replyByAdmin($ticketId, $message, $this->user->id);
            $this->sendMessage($chatId, "✅ پاسخ شما برای تیکت #{$ticketId} ثبت و به کاربر ارسال شد.");
        } catch (\Throwable $e) {
            \Log::error('Admin ticket reply via bot failed: ' . $e->getMessage());
            $this->sendMessage($chatId, "❌ خطا در ثبت پاسخ تیکت #{$ticketId}.");
        }
    }

    protected function processTicketReply(int $chatId, string $message): void
    {
        $botData = json_decode($this->user->bot_data ?? '{}', true);
        $ticketId = $botData['ticket_id'] ?? null;
        
        if (!$ticketId) {
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            $this->sendMessage($chatId, "❌ خطا در پردازش. لطفاً دوباره تلاش کنید.");
            return;
        }
        
        $ticket = \App\Models\Ticket::where('id', $ticketId)
            ->where('user_id', $this->user->id)
            ->where('status', 0)
            ->first();
        
        if (!$ticket) {
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            $this->sendMessage($chatId, "❌ تیکت یافت نشد یا بسته شده است.");
            return;
        }
        
        // ثبت پیام جدید
        \App\Models\TicketMessage::create([
            'user_id' => $this->user->id,
            'ticket_id' => $ticketId,
            'message' => $message
        ]);
        
        // بروزرسانی reply_status تیکت
        $ticket->update(['reply_status' => 0]);
        
        $this->user->update(['bot_step' => null, 'bot_data' => null]);
        
        // ارسال اعلان به ادمین
        $this->sendTicketNotifyToAdmin($ticket, $message);
        
        $this->sendMessage($chatId, "✅ پاسخ شما برای تیکت #{$ticketId} ثبت شد.", null, [
            [['text' => '👁 مشاهده تیکت', 'callback_data' => "view_ticket_{$ticketId}"]],
            [['text' => '🔙 بازگشت', 'callback_data' => 'my_tickets']]
        ]);
    }

    /**
     * نمایش تنظیمات (فقط ادمین)
     */
    protected function showSettings(int $chatId): void
    {
        $transitEnable = \DB::table('v2_bot_settings')->where('key', 'payment_transit_enable')->value('value');
        $transitUrl = \DB::table('v2_bot_settings')->where('key', 'payment_transit_url')->value('value');
        $proxyEnable = \DB::table('v2_bot_settings')->where('key', 'payment_proxy_enable')->value('value');
        
        $transitIcon = $transitEnable == '1' ? '✅' : '❌';
        $proxyIcon = $proxyEnable == '1' ? '✅' : '❌';
        
        $text = "⚙️ تنظیمات ربات\n\n";
        $text .= "━━━━━━━━━━━━━━━\n";
        $text .= "🔄 ترانزیت پرداخت: {$transitIcon}\n";
        $text .= "   " . ($transitUrl ?: 'تنظیم نشده') . "\n\n";
        $text .= "🛡 پروکسی درگاه: {$proxyIcon}\n";
        $text .= "━━━━━━━━━━━━━━━\n\n";
        $text .= "💡 ترانزیت: کاربر از صفحه شما به درگاه می‌رود\n";
        $text .= "💡 پروکسی: درخواست‌های API از سرور دیگر ارسال می‌شود";
        
        $transitToggle = $transitEnable == '1' ? '❌ غیرفعال ترانزیت' : '✅ فعال ترانزیت';
        $proxyToggle = $proxyEnable == '1' ? '❌ غیرفعال پروکسی' : '✅ فعال پروکسی';
        
        $buttons = [
            [['text' => $transitToggle, 'callback_data' => 'setting_transit_toggle']],
            [['text' => '🔗 آدرس ترانزیت', 'callback_data' => 'setting_transit_url']],
            [['text' => $proxyToggle, 'callback_data' => 'setting_proxy_toggle']],
            [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
        ];
        
        $this->sendMessage($chatId, $text, null, $buttons);
    }
    
    /**
     * تغییر وضعیت ترانزیت
     */
    protected function toggleTransitSetting(int $chatId): void
    {
        $current = \DB::table('v2_bot_settings')->where('key', 'payment_transit_enable')->value('value');
        $newValue = $current == '1' ? '0' : '1';
        
        \DB::table('v2_bot_settings')->where('key', 'payment_transit_enable')->update(['value' => $newValue]);
        
        $this->showSettings($chatId);
    }
    
    /**
     * تغییر وضعیت پروکسی درگاه
     */
    protected function toggleProxySetting(int $chatId): void
    {
        $current = \DB::table('v2_bot_settings')->where('key', 'payment_proxy_enable')->value('value');
        $newValue = $current == '1' ? '0' : '1';
        
        \DB::table('v2_bot_settings')->where('key', 'payment_proxy_enable')->update(['value' => $newValue]);
        
        $this->showSettings($chatId);
    }
    
    /**
     * درخواست آدرس ترانزیت
     */
    protected function askTransitUrl(int $chatId): void
    {
        $this->user->update(['bot_step' => 'setting_transit_url', 'bot_data' => null]);
        
        $this->sendMessage($chatId, "🔗 آدرس صفحه ترانزیت را وارد کنید:\n\nمثال: https://example.com/transit.php\n\n(برای انصراف /cancel بزنید)");
    }
    
    /**
     * پردازش آدرس ترانزیت
     */
    protected function processTransitUrl(int $chatId, string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->sendMessage($chatId, "❌ آدرس وارد شده معتبر نیست. لطفاً یک URL صحیح وارد کنید.");
            return;
        }
        
        \DB::table('v2_bot_settings')->where('key', 'payment_transit_url')->update(['value' => $url]);
        
        $this->user->update(['bot_step' => null, 'bot_data' => null]);
        
        $this->sendMessage($chatId, "✅ آدرس ترانزیت با موفقیت ذخیره شد.");
        $this->showSettings($chatId);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 💳 Card Payment Methods
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * تأیید کامل پرداخت کارت به کارت
     */
    protected function handleCardVerifyFull(int $chatId, int $paymentId, string $callbackId): void
    {
        if (!$this->user || !$this->user->is_admin) {
            $this->answerCallback($callbackId, '❌ دسترسی ندارید');
            return;
        }

        $payment = \App\Models\CardPayment::find($paymentId);
        if (!$payment) {
            $this->answerCallback($callbackId, '❌ پرداخت یافت نشد');
            return;
        }

        if ($payment->status !== \App\Models\CardPayment::STATUS_CLAIMED) {
            $this->answerCallback($callbackId, '❌ این پرداخت قبلاً پردازش شده');
            return;
        }

        $service = new \App\Services\CardPaymentService();
        $result = $service->verifyFull($payment, $this->user->id);

        if ($result['success']) {
            if ($payment->telegram_message_id) {
                $this->editMessageReplyMarkup($chatId, $payment->telegram_message_id, [
                    'inline_keyboard' => [[['text' => '✅ تأیید شد', 'callback_data' => 'card_done']]]
                ]);
            }
            $this->answerCallback($callbackId, '✅ پرداخت تأیید شد');
        } else {
            $this->answerCallback($callbackId, '❌ ' . $result['message']);
        }
    }

    /**
     * شروع تأیید با مبلغ متفاوت
     */
    protected function handleCardVerifyDiff(int $chatId, int $paymentId, string $callbackId): void
    {
        if (!$this->user || !$this->user->is_admin) {
            $this->answerCallback($callbackId, '❌ دسترسی ندارید');
            return;
        }

        $payment = \App\Models\CardPayment::find($paymentId);
        if (!$payment) {
            $this->answerCallback($callbackId, '❌ پرداخت یافت نشد');
            return;
        }

        if ($payment->status !== \App\Models\CardPayment::STATUS_CLAIMED) {
            $this->answerCallback($callbackId, '❌ این پرداخت قبلاً پردازش شده');
            return;
        }

        $amountToman = number_format($payment->expected_amount);

        $text = "💰 *تأیید با مبلغ متفاوت*\n\n";
        $text .= "مبلغ سفارش: {$amountToman} تومان\n\n";
        $text .= "مبلغ واقعی واریز شده را وارد کنید (تومان):\n";
        $text .= "_(برای انصراف /cancel بزنید)_";

        $this->user->update([
            'bot_step' => 'card_diff_amount',
            'bot_data' => json_encode(['payment_id' => $paymentId])
        ]);

        $this->sendMessage($chatId, $text, null, null, 'Markdown');
        $this->answerCallback($callbackId);
    }

    /**
     * شروع رد پرداخت
     */
    protected function handleCardReject(int $chatId, int $paymentId, string $callbackId): void
    {
        if (!$this->user || !$this->user->is_admin) {
            $this->answerCallback($callbackId, '❌ دسترسی ندارید');
            return;
        }

        $payment = \App\Models\CardPayment::find($paymentId);
        if (!$payment) {
            $this->answerCallback($callbackId, '❌ پرداخت یافت نشد');
            return;
        }

        if ($payment->status !== \App\Models\CardPayment::STATUS_CLAIMED) {
            $this->answerCallback($callbackId, '❌ این پرداخت قبلاً پردازش شده');
            return;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'واریز مشاهده نشد', 'callback_data' => "card_confirm_reject_{$paymentId}_no_deposit"],
                    ['text' => 'مبلغ اشتباه', 'callback_data' => "card_confirm_reject_{$paymentId}_wrong_amount"]
                ],
                [
                    ['text' => 'شماره پیگیری نامعتبر', 'callback_data' => "card_confirm_reject_{$paymentId}_invalid_tracking"],
                    ['text' => 'تقلب', 'callback_data' => "card_confirm_reject_{$paymentId}_fraud"]
                ],
                [
                    ['text' => '↩️ انصراف', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->editMessageReplyMarkup($chatId, $payment->telegram_message_id, $keyboard);
        $this->answerCallback($callbackId, 'دلیل رد را انتخاب کنید');
    }

    /**
     * تأیید نهایی رد پرداخت
     */
    protected function handleCardConfirmReject(int $chatId, string $data, string $callbackId): void
    {
        if (!$this->user || !$this->user->is_admin) {
            $this->answerCallback($callbackId, '❌ دسترسی ندارید');
            return;
        }

        // data format: card_confirm_reject_{paymentId}_{reason}
        $parts = explode('_', $data);
        $paymentId = (int) $parts[3];
        $reasonKey = implode('_', array_slice($parts, 4));

        $reasons = [
            'no_deposit' => 'واریزی مشاهده نشد',
            'wrong_amount' => 'مبلغ اشتباه است',
            'invalid_tracking' => 'شماره پیگیری نامعتبر است',
            'fraud' => 'تشخیص تقلب'
        ];

        $reason = $reasons[$reasonKey] ?? 'رد شده';

        $payment = \App\Models\CardPayment::find($paymentId);
        if (!$payment) {
            $this->answerCallback($callbackId, '❌ پرداخت یافت نشد');
            return;
        }

        $service = new \App\Services\CardPaymentService();
        $result = $service->reject($payment, $this->user->id, $reason);

        if ($result['success']) {
            if ($payment->telegram_message_id) {
                $this->editMessageReplyMarkup($chatId, $payment->telegram_message_id, [
                    'inline_keyboard' => [[['text' => "❌ رد شد: {$reason}", 'callback_data' => 'card_done']]]
                ]);
            }
            $this->answerCallback($callbackId, '❌ پرداخت رد شد');
        } else {
            $this->answerCallback($callbackId, '❌ ' . $result['message']);
        }
    }

    /**
     * تأیید نهایی مبلغ متفاوت
     */
    protected function handleCardConfirmDiff(int $chatId, string $data, string $callbackId): void
    {
        // این متد در صورت نیاز به تأیید با دکمه استفاده می‌شود
        $this->answerCallback($callbackId);
    }

    /**
     * پردازش مبلغ متفاوت (از handleStep)
     */
    protected function processCardDiffAmount(int $chatId, string $text): void
    {
        $botData = json_decode($this->user->bot_data, true);
        $paymentId = $botData['payment_id'] ?? null;

        if (!$paymentId) {
            $this->sendMessage($chatId, '❌ خطا: اطلاعات پرداخت یافت نشد');
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        // حذف کاراکترهای غیرعددی
        $amountToman = preg_replace('/[^0-9]/', '', $text);
        
        if (empty($amountToman) || $amountToman <= 0) {
            $this->sendMessage($chatId, '❌ مبلغ نامعتبر است. لطفاً فقط عدد وارد کنید.');
            return;
        }

        $amountRial = (int) $amountToman;

        $payment = \App\Models\CardPayment::find($paymentId);
        if (!$payment) {
            $this->sendMessage($chatId, '❌ پرداخت یافت نشد');
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        $service = new \App\Services\CardPaymentService();
        $result = $service->verifyWithDifferentAmount($payment, $amountRial, $this->user->id);

        $this->user->update(['bot_step' => null, 'bot_data' => null]);

        if ($result['success']) {
            $this->sendMessage($chatId, "✅ {$result['message']}");
        } else {
            $this->sendMessage($chatId, "❌ {$result['message']}");
        }
    }

    /**
     * ویرایش دکمه‌های پیام
     */
    protected function editMessageReplyMarkup(int $chatId, int $messageId, array $keyboard): void
    {
        try {
            Http::post($this->apiUrl . 'editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to edit message reply markup', ['error' => $e->getMessage()]);
        }
    }

    /**
     * پردازش عکس رسید کارت به کارت
     */
    protected function processCardReceipt(int $chatId, string $fileId): void
    {
        $botData = json_decode($this->user->bot_data, true);
        $tradeNo = $botData['trade_no'] ?? null;
        $paymentId = $botData['payment_id'] ?? null;
        $amount = $botData['amount'] ?? 0;

        if (!$tradeNo || !$paymentId) {
            $this->sendMessage($chatId, '❌ خطا: اطلاعات پرداخت یافت نشد.');
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        // بروزرسانی CardPayment
        $cardPayment = \App\Models\CardPayment::find($paymentId);
        if (!$cardPayment) {
            $this->sendMessage($chatId, '❌ رکورد پرداخت یافت نشد.');
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        if ($cardPayment->status !== \App\Models\CardPayment::STATUS_PENDING) {
            $this->sendMessage($chatId, '❌ این پرداخت قبلاً پردازش شده.');
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        // ثبت claim
        $cardPayment->status = \App\Models\CardPayment::STATUS_CLAIMED;
        $cardPayment->claimed_at = time();
        $cardPayment->tracking_number = 'receipt_photo';
        // Keep the Telegram photo file_id so the admin app can fetch & show the
        // receipt (getFile → download). Without this only Telegram admins see it.
        $cardPayment->receipt_file_id = $fileId;
        $cardPayment->save();

        // پاک کردن step
        $this->user->update(['bot_step' => null, 'bot_data' => null]);

        // دریافت اطلاعات ادمین
        if (\App\Models\BotSetting::getBool('notify_all_admins', false)) {
            $adminChatIds = \App\Models\User::where('is_admin', 1)
                ->whereNotNull('telegram_id')
                ->pluck('telegram_id')->map(function ($v) { return (string) $v; })
                ->filter()->unique()->values()->all();
        } else {
            $payment = \App\Models\Payment::where('payment', 'Card2Card')->where('enable', 1)->first();
            $cid = $payment->config['telegram_admin_id'] ?? null;
            $adminChatIds = $cid ? [(string) $cid] : [];
        }
        $token = config('v2board.telegram_bot_token');

        if (empty($adminChatIds) || !$token) {
            $this->sendMessage($chatId, '✅ رسید ارسال شد. منتظر تأیید ادمین باشید.');
            return;
        }

        // ارسال عکس رسید به ادمین
        $user = $this->user;
        $amountToman = number_format($amount);
        $caption = "🧾 *رسید پرداخت کارت به کارت*\n\n";
        $caption .= "👤 کاربر: `{$user->email}`\n";
        $caption .= "💰 مبلغ: {$amountToman} تومان\n";
        $caption .= "🔢 شماره سفارش: `{$tradeNo}`\n";
        $caption .= "📸 رسید از طریق ربات تلگرام ارسال شده";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تأیید کامل', 'callback_data' => "card_verify_full_{$paymentId}"],
                    ['text' => '💰 مبلغ متفاوت', 'callback_data' => "card_verify_diff_{$paymentId}"]
                ],
                [
                    ['text' => '❌ رد کردن', 'callback_data' => "card_reject_{$paymentId}"]
                ]
            ]
        ];

        $firstMsg = null; $firstChat = null;
        foreach ($adminChatIds as $cidT) {
            $payload = [
                'chat_id' => $cidT,
                'photo' => $fileId,
                'caption' => $caption,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ];
            try {
                $response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendPhoto", $payload);
                $result = $response->json();
                if (!$response->successful()) {
                    unset($payload['parse_mode']);
                    $response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendPhoto", $payload);
                    $result = $response->json();
                }
                if ($response->successful() && isset($result['result']['message_id'])) {
                    if ($firstMsg === null) {
                        $firstMsg = $result['result']['message_id'];
                        $firstChat = $cidT;
                    }
                } else {
                    \Illuminate\Support\Facades\Log::error('Card receipt delivery to admin failed', ['chat_id' => $cidT, 'resp' => $result]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send receipt to admin', ['chat_id' => $cidT, 'error' => $e->getMessage()]);
            }
        }
        if ($firstMsg !== null) {
            $cardPayment->telegram_message_id = $firstMsg;
            $cardPayment->telegram_chat_id = $firstChat;
            $cardPayment->save();
        }

        $this->sendMessage($chatId, "✅ رسید شما ارسال شد.\nمنتظر تأیید ادمین باشید. نتیجه از طریق ربات اطلاع‌رسانی می‌شود.");
    }




    protected function showReservedPlans(int $chatId, int $messageId): void
    {
        $this->deleteMessage($chatId, $messageId);
        $user = $this->user;
        $reserved = \App\Models\ReservedPlan::where('user_id', $user->id)
            ->where('status', 0)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($reserved->isEmpty()) {
            $this->sendMessage($chatId, "📋 شما بسته رزرو شده‌ای ندارید.", null, [
                [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
            ]);
            return;
        }

        $text = "📋 *بسته‌های رزرو شما:*\n\n";
        $i = 1;
        foreach ($reserved as $rp) {
            $plan = \App\Models\Plan::find($rp->plan_id);
            $planName = $plan ? $plan->name : 'نامشخص';
            $period = str_replace(
                ['month_price', 'quarter_price', 'half_year_price', 'year_price'],
                ['ماهانه', 'سه ماهه', 'شش ماهه', 'سالانه'],
                $rp->period
            );
            $date = $this->jalaliDate($rp->created_at, 'Y/m/d');
            $text .= "{$i}. 📦 {$planName}\n";
            $text .= "   ⏳ دوره: {$period}\n";
            $text .= "   📅 تاریخ رزرو: {$date}\n\n";
            $i++;
        }
        $text .= "💡 _بسته‌ها به ترتیب زمان رزرو فعال می‌شوند._";

        $buttons = [
            [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']]
        ];
        $this->sendMessage($chatId, $text, null, $buttons, 'Markdown');
    }
}
