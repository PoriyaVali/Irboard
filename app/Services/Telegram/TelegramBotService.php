<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Models\Plan;
use App\Models\Order;
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
    protected bool $callbackAnswered = false;
    protected ?TelegramMenu $menu = null;
    protected ?array $pendingMenu = null;
    protected bool $restoringMenu = false;

    public function __construct()
    {
        $this->token = (string) config('v2board.telegram_bot_token', '');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}/";
    }

    /**
     * پردازش webhook
     */
    public function handleWebhook(array $update): void
    {
        $this->update = $update;
        $this->user = null;
        $this->callbackAnswered = false;
        $this->menu = null;
        $this->pendingMenu = null;
        $this->restoringMenu = false;

        try {
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            }
        } catch (\Throwable $e) {
            // Throwable, not Exception: a TypeError escaping here turned into
            // an HTTP 500, and Telegram re-delivers an update that failed, so
            // one bad update was processed again and again.
            // Exception messages and Telegram responses can contain tokens or credentials.
            Log::error('Telegram update failed', ['exception' => get_class($e)]);
            $chat = $update['message']['chat'] ?? $update['callback_query']['message']['chat'] ?? [];
            $from = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
            if ($this->user && (int) ($chat['id'] ?? 0) === (int) $from && ($chat['type'] ?? 'private') === 'private') {
                $this->sendMessage((int) $chat['id'], 'مشکلی در پردازش درخواست پیش آمد. دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.', null, [BotView::home()]);
            }
        } finally {
            if ($this->menu && $this->pendingMenu) {
                [$text, $buttons, $mode] = $this->pendingMenu;
                $this->menu->render($text, $buttons, $mode);
            }
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
        // Account links, credentials and receipts belong only in the owner's private chat.
        if (!isset($message['chat']['id'], $message['from']['id'])
            || ($message['chat']['type'] ?? 'private') !== 'private'
            || (int) $message['chat']['id'] !== (int) $message['from']['id']) return;
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');
        $text = preg_replace('/^(\/[a-z_]+)@[a-z0-9_]+(?=\s|$)/i', '$1', $text);
        $telegramId = $message['from']['id'];

        // پیدا کردن یا ساخت کاربر
        $this->user = $this->findOrCreateUser($message['from']);
        $this->menu = new TelegramMenu($this->user, $this->token);

        // بررسی بن بودن
        if ($this->user->banned) {
            $this->sendMessage($chatId, '⛔ حساب شما مسدود شده است.');
            return;
        }

        if (!$this->checkChannelMembership($telegramId)) {
            $this->sendJoinChannelMessage($chatId);
            return;
        }

        // ادمین یکی از فایل‌های ربات هوشمند را فرستاده: شناسه‌ی تلگرامی‌اش نگه داشته می‌شود
        if ($this->user->is_admin && isset($message['document']) && $this->rememberAdminFile($chatId, $message['document'])) {
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

        // Keep support and account information accessible while a receipt is reviewed.
        if ($this->hasPendingCardClaim() && !$this->isReadOnlyCommand($text)
            && !in_array($this->user->bot_step, ['ticket_subject', 'ticket_message', 'ticket_confirm', 'ticket_reply'], true)) {
            $this->sendMessage($chatId, '⏳ رسید بانکی شما در حال بررسی است. تا اعلام نتیجه، خرید و شارژ جدید ممکن نیست.', null, [
                [['text' => '🧾 پیگیری پرداخت', 'callback_data' => 'orders_page_1']],
                [['text' => '📞 پشتیبانی', 'callback_data' => 'support_menu']], BotView::home(),
            ]);
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
        if (preg_match('/^\/start\s+(.+)$/', $text, $matches)) {
            $this->handleStartParam($chatId, trim($matches[1]));
            return;
        }
        
        switch ($text) {
            case '/start':
            case '/menu':
            case '🏠 منوی اصلی':
                $this->sendMainMenu($chatId);
                break;
            case '/cancel':
            case '/back':
            case '‹ برگشت':
                $this->restoreMenu($chatId, $this->menu ? $this->menu->back() : ['name' => 'home', 'args' => []]);
                break;
            case '/setting':
                Log::info("Setting command received", ["is_admin" => $this->user->is_admin]);
                if ($this->user->is_admin) {
                    $this->showSettings($chatId);
                } else {
                    $this->sendMessage($chatId, "❌ این دستور فقط برای ادمین‌ها است.");
                }
                break;
            case '/files':
                if ($this->user->is_admin) {
                    $this->sendFilesStatus($chatId);
                } else {
                    $this->sendMessage($chatId, "❌ این دستور فقط برای ادمین‌ها است.");
                }
                break;

            case '🛒 خرید اشتراک':
            case '/plans':
                $this->sendPlansList($chatId);
                break;

            case '👤 حساب کاربری':
            case '/account':
                $this->sendAccountInfo($chatId);
                break;

            case '🎁 اکانت تست':
                $this->handleTestAccount($chatId);
                break;

            case '💰 کیف پول':
            case '/wallet':
                $this->sendWalletInfo($chatId);
                break;

            case '📦 سرویس‌های من':
            case '/services':
            case '/traffic':
                $this->sendMyServices($chatId);
                break;

            case '🧾 سفارش‌های من':
            case '/orders':
                $this->showOrders($chatId);
                break;

            case '🎁 گیفت کارت':
                $this->askForGiftCard($chatId);
                break;

            case '👥 زیرمجموعه':
                $this->sendAffiliateInfo($chatId);
                break;

            case '📞 پشتیبانی':
            case '/support':
                $this->sendSupportInfo($chatId);
                break;

            case '📚 راهنما':
            case '/help':
                $this->sendHelpInfo($chatId);
                break;

            default:
                // اجرای دستورات داینامیک
                if (Str::startsWith($text, '/')) {
                    $this->executePluginCommand($chatId, $text);
                } else {
                    $this->sendMessage($chatId, 'گزینه‌ای با این عنوان پیدا نشد؛ از دکمه‌های منو استفاده کنید. برای خروج از هر مرحله /cancel را بفرستید.');
                    $this->sendMainMenu($chatId);
                }
        }
    }

    /**
     * منوی اصلی
     */
    protected function sendMainMenu(int $chatId): void
    {
        $this->setMenuScreen('home');
        $this->clearInput();
        $brand = BotView::escape(config('v2board.app_name') ?: 'Irboard');
        $welcome = BotText::get('text_start', 'خوش آمدید؛ همه‌چیز برای مدیریت اشتراک شما اینجاست.');
        $text = "✨ <b>{$brand} | میز کار شما</b>\n\n" . BotView::escape($welcome);
        $text .= "\n\n" . BotView::status($this->user);
        $text .= "\n💰 موجودی کیف پول: <b>" . BotView::money($this->user->balance) . "</b>";
        if ($this->hasPendingCardClaim()) {
            $text .= "\n⏳ رسید بانکی شما در انتظار بررسی است. وضعیت را از «سفارش‌های من» پیگیری کنید.";
        }
        $text .= "\n\nبرای شروع، یکی از گزینه‌های زیر را انتخاب کنید ↓";
        $keyboard = [
            [['text' => '🛒 خرید اشتراک', 'callback_data' => 'plans_list'], ['text' => '📦 سرویس‌های من', 'callback_data' => 'nav_services']],
            [['text' => '💰 کیف پول', 'callback_data' => 'nav_wallet'], ['text' => '🧾 سفارش‌های من', 'callback_data' => 'orders_page_1']],
            [['text' => '👤 حساب کاربری', 'callback_data' => 'nav_account'], ['text' => '🎁 گیفت کارت', 'callback_data' => 'nav_gift']],
            [['text' => '📞 پشتیبانی', 'callback_data' => 'support_menu'], ['text' => '📚 راهنما', 'callback_data' => 'nav_help']],
            [['text' => '👥 زیرمجموعه', 'callback_data' => 'nav_affiliate']],
        ];
        if (config('v2board.try_out_plan_id') && (int) BotSetting::get('test_limit', 1) > 0
            && !$this->user->plan_id && !$this->user->bot_test_count) {
            $keyboard[4][] = ['text' => '🎁 اکانت تست', 'callback_data' => 'nav_trial'];
        }
        $panelUrl = TelegramLoginLink::make($this->user, 'dashboard');
        if (filter_var($panelUrl, FILTER_VALIDATE_URL) && parse_url($panelUrl, PHP_URL_SCHEME) === 'https') {
            array_unshift($keyboard, [['text' => '🌐 ورود به پنل', 'web_app' => ['url' => $panelUrl]]]);
        }
        $this->sendMessage($chatId, $text, null, $keyboard, 'HTML');
    }

    /**
     * لیست پلن‌ها
     */
    protected function sendPlansList(int $chatId, int $page = 1): void
    {
        $this->setMenuScreen('plans', ['page' => $page]);
        $query = Plan::where('show', true)->where(function ($query) {
            foreach (BotView::PERIODS as $period => $label) $query->orWhereNotNull($period . '_price');
        });
        $pages = max(1, (int) ceil((clone $query)->count() / 5));
        $page = max(1, min($page, $pages));
        $plans = $query->orderBy('sort')->orderBy('id')->skip(($page - 1) * 5)->take(5)->get();
        $text = "🛒 <b>انتخاب اشتراک</b>\n\nپلن مناسب خود را انتخاب کنید؛ دوره و مبلغ نهایی در مرحلهٔ بعد نمایش داده می‌شود.\n";
        $buttons = [];
        foreach ($plans as $plan) {
            $prices = [];
            foreach (BotView::PERIODS as $period => $label) {
                if ($plan->{$period . '_price'} !== null) $prices[] = (int) $plan->{$period . '_price'};
            }
            $name = BotView::escape(mb_strimwidth($plan->name, 0, 90, '…'));
            $text .= "\n<b>{$name}</b>\n";
            $text .= "📊 " . $this->formatBytes((int) ($plan->transfer_enable * 1073741824));
            $text .= '  ·  از ' . BotView::money(min($prices)) . "\n";
            $buttons[] = [['text' => 'انتخاب ' . mb_strimwidth($plan->name, 0, 55, '…'), 'callback_data' => 'plan_' . $plan->id]];
        }
        if ($plans->isEmpty()) $text = "🛒 <b>فروشگاه اشتراک</b>\n\nدر حال حاضر پلنی برای فروش موجود نیست. برای راهنمایی با پشتیبانی در تماس باشید.";
        $this->appendPagination($buttons, $page, $pages, 'plans_page_');
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }

    /**
     * اطلاعات حساب
     */
    protected function sendAccountInfo(int $chatId): void
    {
        $this->setMenuScreen('account');
        $user = $this->user;
        $text = "👤 <b>حساب کاربری</b>\n\n";
        $text .= "شناسهٔ حساب: <code>{$user->id}</code>\n";
        $text .= 'ایمیل: <code>' . BotView::escape($user->email) . "</code>\n";
        $text .= 'عضویت: ' . $this->jalaliDate($user->created_at, 'Y/m/d') . "\n\n";
        $text .= BotView::status($user) . "\n💰 کیف پول: " . BotView::money($user->balance);
        $buttons = [
            [['text' => '📦 جزئیات سرویس', 'callback_data' => 'nav_services'], ['text' => '💰 کیف پول', 'callback_data' => 'nav_wallet']],
            [['text' => '🧾 سفارش‌های من', 'callback_data' => 'orders_page_1']],
            [['text' => '📋 بسته‌های رزرو', 'callback_data' => 'reserved_plans']],
            BotView::home(),
        ];
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }

    /**
     * اکانت تست
     */
    protected function handleTestAccount(int $chatId): void
    {
        $this->setMenuScreen('trial');
        $plan = Plan::find((int) config('v2board.try_out_plan_id', 0));
        $hours = (float) config('v2board.try_out_hour', 1);
        if (!$plan || $hours <= 0 || $plan->transfer_enable <= 0 || (int) BotSetting::get('test_limit', 1) <= 0) {
            $this->sendMessage($chatId, '🎁 اکانت تست در حال حاضر فعال نیست.', null, [BotView::home()]);
            return;
        }
        $text = "🎁 <b>قبل از خرید امتحان کنید</b>\n\n";
        $text .= '📊 حجم: ' . $this->formatBytes((int) ($plan->transfer_enable * 1073741824));
        $text .= "\n⏱ اعتبار: {$hours} ساعت\n\nاکانت تست مخصوص حساب‌های جدید و بدون اشتراک است.";
        $this->sendMessage($chatId, $text, null, [
            [['text' => '🎁 فعال‌سازی اکانت تست', 'callback_data' => 'trial_activate']],
            BotView::home(),
        ], 'HTML');
    }

    /**
     * اطلاعات کیف پول
     */
    protected function sendWalletInfo(int $chatId): void
    {
        $this->setMenuScreen('wallet');
        $text = "💰 <b>کیف پول شما</b>\n\nموجودی قابل استفاده\n<b>" . BotView::money($this->user->balance) . "</b>\n\n";
        $text .= 'موجودی کیف پول هنگام خرید اشتراک به‌صورت خودکار استفاده می‌شود. سوابق و وضعیت پرداخت‌ها در بخش سفارش‌ها در دسترس است.';
        $this->sendMessage($chatId, $text, null, [
            [['text' => '＋ شارژ کیف پول', 'callback_data' => 'charge_wallet']],
            [['text' => '🧾 سوابق پرداخت', 'callback_data' => 'orders_page_1']],
            BotView::home(),
        ], 'HTML');
    }

    /**
     * پردازش callback
     */
    protected function handleCallback(array $callback): void
    {
        if (!isset($callback['message']['chat']['id'], $callback['from']['id'], $callback['id'], $callback['data'])
            || ($callback['message']['chat']['type'] ?? 'private') !== 'private'
            || (int) $callback['message']['chat']['id'] !== (int) $callback['from']['id']) return;
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $data = $callback['data'];
        $telegramId = $callback['from']['id'];

        $this->user = User::where('telegram_id', $telegramId)->first();

        if (!$this->user) {
            $this->answerCallback($callback['id'], '❌ لطفاً ابتدا /start بزنید.');
            return;
        }

        $this->menu = new TelegramMenu($this->user, $this->token, $callback['message']);

        if ($this->user->banned) {
            $this->answerCallback($callback['id'], '⛔ حساب شما مسدود شده است.');
            return;
        }
        if (!$this->checkChannelMembership($telegramId)) {
            $this->answerCallback($callback['id'], 'ابتدا عضویت در کانال‌ها را تکمیل کنید.');
            $this->sendJoinChannelMessage($chatId);
            return;
        }
        if ($data === 'check_join') {
            $this->answerCallback($callback['id'], '✅ عضویت شما تأیید شد.');
            $this->sendMainMenu($chatId);
            return;
        }

        if ($this->menu->isStale() && !Str::startsWith($data, ['card_', 'remind_admin_'])) {
            $this->answerCallback($callback['id'], 'این پیام قدیمی است؛ از منوی فعلی استفاده کنید.');
            return;
        }
        $action = Str::startsWith($data, ['buy_', 'pay_', 'charge_amount_', 'coupon_yes_', 'coupon_no_', 'cancel_order_'])
            || in_array($data, ['trial_activate', 'cred_reset_yes', 'ticket_send_now', 'transfer_commission', 'create_invite_code'], true);
        if ($action && !$this->menu->claimAction($data)) {
            $this->answerCallback($callback['id'], 'این مرحله تغییر کرده است؛ از دکمه‌های فعلی استفاده کنید.');
            return;
        }

        if ($data === 'menu_back' || $data === 'cancel_input') {
            $this->answerCallback($callback['id']);
            $this->restoreMenu($chatId, $this->menu->back());
            return;
        }
        if (preg_match('/^menu_page_(\d+)$/D', $data, $matches)) {
            $this->answerCallback($callback['id']);
            if (!$this->menu->page((int) $matches[1])) $this->sendMainMenu($chatId);
            return;
        }

        // Pending receipts block financial actions; tracking and support remain available.
        if ($this->hasPendingCardClaim()
            && !$this->isReadOnlyCallback($data)
            && !Str::startsWith($data, 'card_verify_')
            && !Str::startsWith($data, 'card_reject_')
            && !Str::startsWith($data, 'card_confirm_')
            && !Str::startsWith($data, 'remind_admin_')) {
            $this->answerCallback($callback['id'], '⏳ منتظر پاسخ ادمین باشید.');
            return;
        }

        // Stop Telegram's spinner before doing database or gateway work.
        if (!Str::startsWith($data, ['card_', 'remind_admin_'])) $this->answerCallback($callback['id']);
        if (Str::startsWith($data, ['nav_', 'orders_page_', 'order_view_', 'plans_page_', 'tickets_page_', 'plan_', 'renew_', 'help_', 'test_'])
            || in_array($data, ['plans_list', 'support_menu', 'my_tickets', 'main_menu', 'cancel_input', 'get_sub_link', 'reserved_plans'], true)) {
            $this->clearInput();
        }

        // پردازش callback ها
        if ($data === 'main_menu' || $data === 'cancel_input') {
            $this->sendMainMenu($chatId);
        } elseif ($data === 'nav_services') {
            $this->sendMyServices($chatId);
        } elseif ($data === 'nav_wallet') {
            $this->sendWalletInfo($chatId);
        } elseif ($data === 'nav_account') {
            $this->sendAccountInfo($chatId);
        } elseif ($data === 'nav_gift') {
            $this->askForGiftCard($chatId);
        } elseif ($data === 'nav_affiliate') {
            $this->sendAffiliateInfo($chatId);
        } elseif ($data === 'nav_trial') {
            $this->handleTestAccount($chatId);
        } elseif ($data === 'nav_help') {
            $this->sendHelpInfo($chatId);
        } elseif (preg_match('/^plans_page_(\d+)$/D', $data, $matches)) {
            $this->sendPlansList($chatId, (int) $matches[1]);
        } elseif (preg_match('/^orders_page_(\d+)$/D', $data, $matches)) {
            $this->showOrders($chatId, (int) $matches[1]);
        } elseif (Str::startsWith($data, 'order_view_')) {
            $this->showOrderDetails($chatId, Str::after($data, 'order_view_'));
        } elseif (preg_match('/^tickets_page_(\d+)$/D', $data, $matches)) {
            $this->showMyTickets($chatId, (int) $matches[1]);
        } elseif (preg_match('/^ticket_thread_(\d+)_(\d+)$/D', $data, $matches)) {
            $this->viewTicket($chatId, (int) $matches[1], (int) $matches[2]);
        } elseif (preg_match('/^ticket_close_(\d+)$/D', $data, $matches)) {
            $this->closeTicket($chatId, (int) $matches[1], false);
        } elseif (preg_match('/^ticket_close_yes_(\d+)$/D', $data, $matches)) {
            $this->closeTicket($chatId, (int) $matches[1], true);
        } elseif ($data === 'trial_activate') {
            $result = (new TelegramTrialService())->activate($this->user);
            $this->user->refresh();
            $this->sendMessage($chatId, ($result['success'] ? '✅ ' : 'ℹ️ ') . $result['message'], null, [BotView::home()]);
            if ($result['success']) {
                $this->sendMyServices($chatId);
                $this->pendingMenu[0] = '✅ ' . $result['message'] . "\n\n" . $this->pendingMenu[0];
            }
        } elseif (preg_match('/^charge_amount_(50000|100000|200000)$/D', $data, $matches)) {
            $this->processChargeAmount($chatId, $matches[1]);
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
        } elseif ($data === 'cred_reset') {
            $this->answerCallback($callback['id']);
            $this->setMenuScreen('credential_reset');
            $this->sendMessage($chatId, "⚠️ با ساخت رمز جدید، رمز فعلی دیگر کار نمی‌کند و برنامه روی همه‌ی دستگاه‌ها باید دوباره وارد شود.\n\nرمز جدید ساخته شود؟", null, [
                [['text' => '✅ بله، رمز جدید بساز', 'callback_data' => 'cred_reset_yes']],
                [['text' => '🏠 بازگشت', 'callback_data' => 'main_menu']],
            ]);
            return;
        } elseif ($data === 'cred_reset_yes') {
            $this->answerCallback($callback['id']);
            if ($this->user->banned) {
                $this->sendMessage($chatId, '⛔ حساب شما مسدود شده است.');
                return;
            }
            if (BotCredentials::reset($this->user) === null) {
                $this->sendMessage($chatId, 'رمز حساب‌های مدیریتی از طریق ربات ساخته نمی‌شود.');
                return;
            }
            $this->sendCredentials($chatId, true);
            return;
        } elseif (preg_match('/^help_file_(android|ios|windows)_(\d+)$/D', $data, $matches)) {
            $this->sendHelpFile($chatId, $matches[1], (int) $matches[2]);
        } elseif (preg_match('/^help_image_(android|ios|windows)$/D', $data, $matches)) {
            $image = BotText::get('help_' . $matches[1] . '_image', '');
            if ($image !== '') $this->sendPhoto($chatId, $image, 'راهنمای اتصال');
        } elseif (preg_match('/^help_credentials_(android|ios|windows)$/D', $data, $matches)) {
            if (BotText::get('help_' . $matches[1] . '_send_credentials', '0') === '1') $this->sendCredentials($chatId);
            else $this->sendHelpPlatform($chatId, $matches[1]);
        } elseif (Str::startsWith($data, 'help_')) {
            $this->answerCallback($callback['id']);
            $this->sendHelpPlatform($chatId, Str::after($data, 'help_'));
            return;
        } else {
            $this->sendMessage($chatId, 'این گزینه قدیمی یا نامعتبر است. منوی اصلی را باز کنید.', null, [BotView::home()]);
        }

        $this->answerCallback($callback['id']);
    }

    protected function clearInput(): void
    {
        if ($this->user->bot_step !== null || $this->user->bot_data !== null) {
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
        }
    }

    protected function setMenuScreen(string $name, array $args = []): void
    {
        if ($this->menu) $this->menu->visit($name, $args, $this->restoringMenu);
    }

    /** Restore only display/prompt routes. Buying, paying and other actions never enter history. */
    protected function restoreMenu(int $chatId, array $screen): void
    {
        $this->restoringMenu = true;
        try {
            $this->renderStoredMenu($chatId, $screen);
        } finally {
            $this->restoringMenu = false;
        }
    }

    protected function renderStoredMenu(int $chatId, array $screen): void
    {
        $name = $screen['name'];
        $args = $screen['args'];
        $this->clearInput();
        if ($this->hasPendingCardClaim() && in_array($name, ['charge', 'gift', 'trial', 'coupon', 'gateways', 'checkout', 'receipt'], true)) {
            $this->showOrders($chatId);
            return;
        }
        switch ($name) {
            case 'plans': $this->sendPlansList($chatId, (int) ($args['page'] ?? 1)); break;
            case 'plan': $this->showPlanDetails($chatId, 0, (int) $args['id']); break;
            case 'services': $this->sendMyServices($chatId); break;
            case 'subscription': $this->sendSubscriptionLink($chatId); break;
            case 'account': $this->sendAccountInfo($chatId); break;
            case 'wallet': $this->sendWalletInfo($chatId); break;
            case 'charge': $this->askChargeAmount($chatId); break;
            case 'gift': $this->askForGiftCard($chatId); break;
            case 'affiliate': $this->sendAffiliateInfo($chatId); break;
            case 'trial': $this->handleTestAccount($chatId); break;
            case 'reserved': $this->showReservedPlans($chatId, 0); break;
            case 'support': $this->sendSupportInfo($chatId); break;
            case 'help': $this->sendHelpInfo($chatId); break;
            case 'help_platform': $this->sendHelpPlatform($chatId, $args['platform']); break;
            case 'credentials': $this->sendCredentials($chatId); break;
            case 'credential_reset':
                $this->setMenuScreen('credential_reset');
                $this->sendMessage($chatId, 'رمز جدید ساخته شود؟ رمز قبلی دیگر کار نخواهد کرد.', null, [
                    [['text' => '✅ بله، رمز جدید بساز', 'callback_data' => 'cred_reset_yes']],
                ]);
                break;
            case 'orders': $this->showOrders($chatId, (int) ($args['page'] ?? 1)); break;
            case 'order': $this->showOrderDetails($chatId, $args['trade_no']); break;
            case 'checkout': $this->showCheckout($chatId, $args['trade_no']); break;
            case 'gateways': $this->showPaymentGateways($chatId, $args['trade_no']); break;
            case 'coupon': $this->askForCouponCode($chatId, $args['trade_no']); break;
            case 'receipt': $this->showReceiptInstructions($chatId, $args['trade_no']); break;
            case 'tickets': $this->showMyTickets($chatId, (int) ($args['page'] ?? 1)); break;
            case 'ticket': $this->viewTicket($chatId, (int) $args['id'], (int) ($args['page'] ?? 1)); break;
            case 'ticket_close': $this->closeTicket($chatId, (int) $args['id'], false); break;
            case 'ticket_reply': $this->startReplyTicket($chatId, (int) $args['id']); break;
            case 'ticket_subject': $this->startNewTicket($chatId); break;
            case 'ticket_confirm': $this->showTicketConfirmation($chatId, $args['subject'] ?? ''); break;
            case 'ticket_message':
                $this->user->update(['bot_step' => 'ticket_confirm', 'bot_data' => json_encode(['subject' => $args['subject'] ?? ''], JSON_UNESCAPED_UNICODE)]);
                $this->askTicketMessage($chatId);
                break;
            case 'settings':
            case 'files':
            case 'transit':
                if (!$this->user->is_admin) { $this->sendMainMenu($chatId); break; }
                if ($name === 'files') $this->sendFilesStatus($chatId);
                elseif ($name === 'transit') $this->askTransitUrl($chatId);
                else $this->showSettings($chatId);
                break;
            default: $this->sendMainMenu($chatId);
        }
    }

    protected function isReadOnlyCommand(string $text): bool
    {
        return in_array($text, [
            '/start', '/menu', '/cancel', '/back', '‹ برگشت', '/account', '/services', '/traffic', '/orders', '/support', '/help', '/wallet',
            '🏠 منوی اصلی', '👤 حساب کاربری', '📦 سرویس‌های من', '🧾 سفارش‌های من', '📞 پشتیبانی', '📚 راهنما', '💰 کیف پول',
        ], true);
    }

    protected function isReadOnlyCallback(string $data): bool
    {
        return in_array($data, ['main_menu', 'cancel_input', 'menu_back', 'nav_account', 'nav_services', 'nav_wallet', 'nav_help', 'get_sub_link', 'reserved_plans',
                'support_menu', 'my_tickets', 'new_ticket', 'ticket_add_message', 'ticket_send_now'], true)
            || Str::startsWith($data, ['orders_page_', 'order_view_', 'tickets_page_', 'ticket_thread_', 'view_ticket_', 'reply_ticket_', 'ticket_close_', 'help_']);
    }

    protected function appendPagination(array &$buttons, int $page, int $pages, string $prefix): void
    {
        if ($pages <= 1) return;
        $row = [];
        if ($page > 1) $row[] = ['text' => '→ قبلی', 'callback_data' => $prefix . ($page - 1)];
        $row[] = ['text' => "{$page} / {$pages}", 'callback_data' => $prefix . $page];
        if ($page < $pages) $row[] = ['text' => 'بعدی ←', 'callback_data' => $prefix . ($page + 1)];
        $buttons[] = $row;
    }

    protected function orderStatus(Order $order): string
    {
        return [0 => '🟡 در انتظار پرداخت', 1 => '⏳ در حال پردازش', 2 => '⚪ لغو شده',
            3 => '✅ تکمیل شده', 4 => '↩️ مبلغ به کیف پول برگشت داده شد'][(int) $order->status] ?? 'در حال بررسی';
    }

    protected function showOrders(int $chatId, int $page = 1): void
    {
        $this->setMenuScreen('orders', ['page' => $page]);
        $query = Order::where('user_id', $this->user->id);
        $pages = max(1, (int) ceil((clone $query)->count() / 5));
        $page = max(1, min($page, $pages));
        $orders = $query->orderByDesc('id')->skip(($page - 1) * 5)->take(5)->get();
        $text = "🧾 <b>سفارش‌های من</b>\n\nبرای جزئیات، ادامهٔ پرداخت یا پیگیری رسید، یک سفارش را انتخاب کنید.\n";
        $buttons = [];
        foreach ($orders as $order) {
            $title = $order->period === 'deposit' ? 'شارژ کیف پول' : 'خرید اشتراک';
            $text .= "\n<b>#{$order->id} · {$title}</b>\n" . $this->orderStatus($order);
            $text .= "\n" . BotView::money($order->total_amount) . '  ·  ' . $this->jalaliDate($order->created_at, 'Y/m/d') . "\n";
            $buttons[] = [['text' => "جزئیات سفارش #{$order->id}", 'callback_data' => 'order_view_' . $order->trade_no]];
        }
        if ($orders->isEmpty()) $text = "🧾 <b>سفارش‌های من</b>\n\nهنوز سفارشی ثبت نکرده‌اید. خرید اشتراک و شارژ کیف پول شما اینجا نمایش داده می‌شود.";
        $this->appendPagination($buttons, $page, $pages, 'orders_page_');
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }

    protected function showOrderDetails(int $chatId, string $tradeNo): void
    {
        $this->setMenuScreen('order', ['trade_no' => $tradeNo]);
        $order = Order::where('user_id', $this->user->id)->where('trade_no', $tradeNo)->first();
        if (!$order) {
            $this->sendMessage($chatId, 'سفارش یافت نشد.', null, [BotView::home()]);
            return;
        }
        $plan = $order->plan_id ? Plan::find($order->plan_id) : null;
        $name = $order->period === 'deposit' ? 'شارژ کیف پول' : ($plan ? $plan->name : 'اشتراک');
        $period = BotView::PERIODS[preg_replace('/_price$/', '', $order->period)] ?? '';
        $text = "🧾 <b>سفارش #{$order->id}</b>\n\n" . $this->orderStatus($order) . "\n";
        $text .= 'شناسهٔ پیگیری: <code>' . BotView::escape($order->trade_no) . "</code>\n";
        $text .= 'عنوان: ' . BotView::escape($name) . ($period ? " · {$period}" : '') . "\n";
        $text .= 'ثبت سفارش: ' . $this->jalaliDate($order->created_at) . "\n\n";
        $text .= 'مبلغ سفارش پس از تخفیف و کیف پول: ' . BotView::money($order->total_amount) . "\n";
        if ($order->balance_amount) $text .= 'سهم کیف پول: ' . BotView::money($order->balance_amount) . "\n";
        if ($order->discount_amount) $text .= 'تخفیف: ' . BotView::money($order->discount_amount) . "\n";
        if ($order->handling_amount) $text .= 'کارمزد درگاه: ' . BotView::money($order->handling_amount) . "\n";
        $claim = \App\Models\CardPayment::where('user_id', $this->user->id)->where('trade_no', $tradeNo)
            ->where('status', \App\Models\CardPayment::STATUS_CLAIMED)->first();
        $buttons = [];
        if ($claim) {
            $text .= "\n⏳ رسید دریافت شده و در انتظار بررسی پشتیبانی است. نتیجه از طریق ربات اطلاع‌رسانی می‌شود.";
            $buttons[] = [['text' => '🔔 یادآوری بررسی رسید', 'callback_data' => 'remind_admin_' . $claim->id]];
        } elseif ((int) $order->status === 0) {
            $buttons[] = [['text' => '💳 ادامهٔ پرداخت', 'callback_data' => 'coupon_no_' . $tradeNo]];
            $buttons[] = [['text' => '✖️ لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]];
        } elseif ((int) $order->status === 3 && $order->period !== 'deposit') {
            $buttons[] = [['text' => '📦 سرویس‌ها و بسته‌های رزرو', 'callback_data' => 'nav_services']];
        }
        $buttons[] = [['text' => '🔃 بررسی مجدد وضعیت', 'callback_data' => 'order_view_' . $tradeNo]];
        $buttons[] = [['text' => '📞 پشتیبانی', 'callback_data' => 'support_menu'], ['text' => '🧾 همهٔ سفارش‌ها', 'callback_data' => 'orders_page_1']];
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }

    protected function closeTicket(int $chatId, int $ticketId, bool $confirmed): void
    {
        $this->setMenuScreen($confirmed ? 'ticket' : 'ticket_close', ['id' => $ticketId, 'page' => 1]);
        $ticket = \App\Models\Ticket::where('id', $ticketId)->where('user_id', $this->user->id)->where('status', 0)->first();
        if (!$ticket) {
            $this->sendMessage($chatId, 'درخواست یافت نشد یا قبلاً بسته شده است.', null, [[['text' => '📋 درخواست‌های من', 'callback_data' => 'my_tickets']]]);
            return;
        }
        if (!$confirmed) {
            $this->sendMessage($chatId, "درخواست #{$ticketId} بسته شود؟\nتاریخچهٔ پیام‌ها حفظ می‌شود.", null, [
                [['text' => '✅ بله، مشکل برطرف شده', 'callback_data' => 'ticket_close_yes_' . $ticketId]],
                [['text' => 'بازگشت به گفت‌وگو', 'callback_data' => 'view_ticket_' . $ticketId]],
            ]);
            return;
        }
        $ticket->update(['status' => 1]);
        $this->clearInput();
        $this->sendMessage($chatId, "✅ درخواست #{$ticketId} بسته شد.", null, [[['text' => '📋 درخواست‌های من', 'callback_data' => 'my_tickets']], BotView::home()]);
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
     * An invite may only be attached to an account that was just made: the
     * one this /start created, or one made in the last hour that has bought
     * nothing yet (a first /start that stopped at the channel-membership gate).
     * An existing account taken under an inviter could be farmed for
     * commission, and put under a reseller who can then reset its password.
     */
    protected function canAttachInviter(): bool
    {
        $user = $this->user;
        if (!$user || $user->is_admin || $user->is_staff) return false;
        if ($user->wasRecentlyCreated) return true;
        if ((int)$user->created_at < time() - 3600) return false;
        return !Order::where('user_id', $user->id)->whereNotIn('status', [0, 2])->exists();
    }

    const PRIVILEGED_BIND_MESSAGE = "❌ این حساب، حساب مدیریتی یا نمایندگی است و با لینک اشتراک به تلگرام متصل نمی‌شود.\n\nبرای اتصال از دستور زیر استفاده کنید:\n/bind [آدرس_اشتراک] [رمز_عبور]";

    /**
     * Accounts that must not be bound to a Telegram chat on the strength of a
     * subscription link alone. That link is handed to VPN apps and shared; a
     * bound admin or reseller gets the admin commands and the approval buttons.
     */
    protected function isPrivilegedAccount(User $user): bool
    {
        return (bool)$user->is_admin || (bool)$user->is_staff;
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
            $response = Http::timeout(10)->get($this->apiUrl . 'getChatMember', [
                'chat_id' => $channel->channel_id,
                'user_id' => $telegramId
            ]);

            $result = $response->json();
            
            if (!isset($result['ok']) || !$result['ok']) {
                return false;
            }

            $status = $result['result']['status'] ?? '';
            if (!in_array($status, ['member', 'administrator', 'creator'], true)
                && !($status === 'restricted' && ($result['result']['is_member'] ?? false))) {
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
        $this->setMenuScreen('join');
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
        if ($this->menu && $this->user && $chatId === (int) $this->user->telegram_id) {
            // One final render per update: confirmations and redirects cannot flood the chat.
            if ($this->pendingMenu && !$this->pendingMenu[1] && $inlineKeyboard
                && mb_strlen($this->pendingMenu[0]) <= 700) {
                [$notice, , $noticeMode] = $this->pendingMenu;
                if ($parseMode === 'HTML' && $noticeMode !== 'HTML') $notice = BotView::escape($notice);
                elseif ($parseMode === '' && $noticeMode === 'HTML') $notice = html_entity_decode(strip_tags($notice), ENT_QUOTES, 'UTF-8');
                if ($parseMode === $noticeMode || $parseMode === 'HTML' || ($parseMode === '' && $noticeMode === 'HTML')) {
                    $text = $notice . "\n\n" . $text;
                }
            }
            $this->pendingMenu = [$text, $inlineKeyboard ?? $keyboard ?? [], $parseMode];
            return;
        }
        // Long ticket conversations must still be delivered within Telegram's limit.
        // Split as plain text so no HTML entity or tag is cut in half.
        if (mb_strlen($text, 'UTF-8') > 1800) {
            if ($parseMode === 'HTML') $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
            $length = mb_strlen($text, 'UTF-8');
            for ($offset = 0; $offset < $length; $offset += 1800) {
                $last = $offset + 1800 >= $length;
                $this->sendMessage($chatId, mb_substr($text, $offset, 1800, 'UTF-8'), $last ? $keyboard : null, $last ? $inlineKeyboard : null);
            }
            return;
        }
        $data = ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true];
        if ($parseMode) $data['parse_mode'] = $parseMode;
        if ($keyboard) {
            $data['reply_markup'] = json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'is_persistent' => true,
                'input_field_placeholder' => 'یک گزینه انتخاب کنید…',
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($inlineKeyboard) {
            $data['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
        }
        try {
            $response = Http::timeout(15)->post($this->apiUrl . 'sendMessage', $data);
            if (!$response->json('ok') && $parseMode && $response->status() === 400
                && stripos((string) $response->json('description'), 'parse entities') !== false) {
                unset($data['parse_mode']);
                if ($parseMode === 'HTML') $data['text'] = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
                $response = Http::timeout(15)->post($this->apiUrl . 'sendMessage', $data);
            }
            if (!$response->json('ok')) Log::warning('Telegram sendMessage rejected', ['status' => $response->status(), 'error_code' => $response->json('error_code')]);
        } catch (\Throwable $e) {
            Log::warning('Telegram sendMessage unavailable', ['exception' => get_class($e)]);
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
        if ($this->callbackAnswered) return;
        $this->callbackAnswered = true;
        try {
            Http::timeout(8)->post($this->apiUrl . 'answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram callback acknowledgement unavailable');
        }
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
        $this->setMenuScreen('services');
        $user = $this->user;
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        if (!$user->plan_id) {
            $this->sendMessage($chatId, "📦 هنوز اشتراکی ندارید.\n\nاز فروشگاه، پلن مناسب خود را انتخاب کنید یا راهنمای اتصال را ببینید.", null, [
                [['text' => '🛒 مشاهده اشتراک‌ها', 'callback_data' => 'plans_list']],
                [['text' => '📚 راهنمای اتصال', 'callback_data' => 'nav_help']],
                BotView::home(),
            ]);
            return;
        }
        $used = (int) ($user->u + $user->d);
        $total = (int) $user->transfer_enable;
        $ratio = $total > 0 ? min(1, max(0, $used / $total)) : 1;
        $filled = (int) round($ratio * 10);
        $bar = str_repeat('▰', $filled) . str_repeat('▱', 10 - $filled);
        $name = BotView::escape($plan ? $plan->name : 'اشتراک شما');
        $text = "📦 <b>{$name}</b>\n" . BotView::status($user) . "\n\n";
        $text .= "<code>{$bar}</code>  " . round($ratio * 100) . "% مصرف\n";
        $text .= 'مصرف شده: ' . $this->formatBytes($used) . "\n";
        $text .= 'حجم باقی‌مانده: <b>' . $this->formatBytes(max(0, $total - $used)) . "</b>\n";
        $text .= 'حجم کل: ' . $this->formatBytes($total) . "\n\n";
        if ($user->expired_at === null) {
            $text .= '📅 اعتبار زمانی: بدون انقضا';
        } else {
            $text .= '📅 پایان اعتبار: ' . $this->jalaliDate($user->expired_at, 'Y/m/d H:i');
            $text .= "\n⏳ زمان باقی‌مانده: " . max(0, (int) ceil(($user->expired_at - time()) / 86400)) . ' روز';
        }
        $buttons = [[['text' => '🔗 دریافت لینک اتصال', 'callback_data' => 'get_sub_link']]];
        if ($plan && $plan->renew) $buttons[] = [['text' => '🔄 تمدید / خرید دورهٔ بعد', 'callback_data' => 'renew_' . $plan->id]];
        $buttons[] = [['text' => '🔃 به‌روزرسانی وضعیت', 'callback_data' => 'nav_services'], ['text' => '📋 بسته‌های رزرو', 'callback_data' => 'reserved_plans']];
        $buttons[] = [['text' => '📚 راهنمای اتصال', 'callback_data' => 'nav_help']];
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }

    /**
     * درخواست کد تخفیف
     */
    protected function askForGiftCard(int $chatId): void
    {
        $this->setMenuScreen('gift');
        $this->user->update(['bot_step' => 'enter_giftcard', 'bot_data' => null]);
        $this->sendMessage($chatId, "🎁 <b>فعال‌سازی گیفت کارت</b>\n\nکد هدیه را ارسال کنید. نتیجهٔ فعال‌سازی همین‌جا نمایش داده می‌شود.\n\nبرای بازگشت /cancel را بفرستید.", null, BotView::cancel(), 'HTML');
    }
    /**
     * اطلاعات زیرمجموعه
     */
    protected function sendAffiliateInfo(int $chatId): void
    {
        $this->setMenuScreen('affiliate');
        $user = $this->user;
        $codes = \App\Models\InviteCode::where('user_id', $user->id)->where('status', 0)->orderBy('id')->get();
        $count = User::where('invite_user_id', $user->id)->count();
        $rate = $user->commission_rate ?? config('v2board.invite_commission', 10);
        $balance = $user->commission_balance ?? 0;
        $pending = (int) Order::where('status', 3)->where('commission_status', 0)->where('invite_user_id', $user->id)->sum('commission_balance');
        $confirmed = (int) \App\Models\CommissionLog::where('invite_user_id', $user->id)->sum('get_amount');
        $text = "👥 <b>دعوت دوستان</b>\n\nلینک دعوت خود را با دوستانتان به اشتراک بگذارید. کمیسیون خریدهای واجد شرایط طبق قوانین پنل محاسبه می‌شود.\n\n";
        $text .= "دوستان دعوت‌شده: {$count} نفر\nنرخ کمیسیون: {$rate}%\n";
        $text .= 'قابل انتقال به کیف پول: <b>' . BotView::money($balance) . "</b>\n";
        $text .= 'در انتظار تأیید: ' . BotView::money($pending) . "\n";
        $text .= 'کل کمیسیون ثبت‌شده: ' . BotView::money($confirmed) . "\n";
        $site = rtrim((string) config('v2board.frontend_url'), '/');
        $path = ltrim((string) config('v2board.frontend_login_path', 'index.html'), '/');
        $username = ltrim((string) config('v2board.telegram_bot_username'), '@');
        foreach ($codes as $code) {
            $text .= "\nکد دعوت: <code>" . BotView::escape($code->code) . "</code>\n";
            if ($username) $text .= '<code>' . BotView::escape('https://t.me/' . $username . '?start=invite_' . $code->code) . "</code>\n";
            if ($site) $text .= '<code>' . BotView::escape($site . '/' . $path . '#/register?code=' . rawurlencode($code->code)) . "</code>\n";
        }
        if ($codes->isEmpty()) $text .= "\nبرای دریافت لینک، یک کد دعوت بسازید.";
        $buttons = [];
        if ($codes->count() < (int) config('v2board.invite_gen_limit', 5)) $buttons[] = [['text' => '＋ ساخت کد دعوت', 'callback_data' => 'create_invite_code']];
        if ($balance > 0) $buttons[] = [['text' => '💸 انتقال کمیسیون به کیف پول', 'callback_data' => 'transfer_commission']];
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }

    /**
     * پشتیبانی
     */
    protected function sendSupportInfo(int $chatId): void
    {
        $this->setMenuScreen('support');
        $openTicket = \App\Models\Ticket::where('user_id', $this->user->id)->where('status', 0)->first();
        $text = "📞 <b>کنار شما هستیم</b>\n\nبرای مشکلات اتصال، خرید یا پرداخت، درخواست پشتیبانی ثبت کنید. موضوع و جزئیات مشکل را بنویسید تا سریع‌تر بررسی شود.";
        $buttons = [];
        if ($openTicket) {
            $text .= "\n\n🎫 درخواست باز شما: #{$openTicket->id}";
            $buttons[] = [['text' => '💬 ادامهٔ گفت‌وگو', 'callback_data' => 'view_ticket_' . $openTicket->id]];
        } else {
            $buttons[] = [['text' => '＋ درخواست پشتیبانی', 'callback_data' => 'new_ticket']];
        }
        $buttons[] = [['text' => '📋 درخواست‌های من', 'callback_data' => 'my_tickets']];
        $buttons[] = [['text' => '📚 راهنمای اتصال', 'callback_data' => 'nav_help']];
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }
    /**
     * راهنما
     */
    protected function sendHelpPlatform(int $chatId, string $platform): void
    {
        if (!in_array($platform, ['android', 'ios', 'windows'], true)) {
            $this->sendHelpInfo($chatId);
            return;
        }
        $this->setMenuScreen('help_platform', ['platform' => $platform]);
        $text = BotText::get('help_' . $platform . '_text', '') ?: 'محتوای این بخش هنوز تنظیم نشده است.';
        $links = json_decode(BotText::get('help_' . $platform . '_links', '[]'), true) ?: [];
        $buttons = [];
        foreach ($links as $index => $link) {
            $label = $link['label'] ?? 'دریافت برنامه';
            if (($link['type'] ?? '') === 'file' && !empty($link['file'])) {
                $buttons[] = [['text' => '⬇️ ' . $label, 'callback_data' => "help_file_{$platform}_{$index}"]];
            } elseif (($link['type'] ?? '') === 'url' && !empty($link['url'])) {
                $buttons[] = [['text' => $label, 'url' => $link['url']]];
            }
        }
        if (BotText::get('help_' . $platform . '_image', '') !== '') {
            $buttons[] = [['text' => '🖼 تصویر راهنما', 'callback_data' => 'help_image_' . $platform]];
        }
        if (BotText::get('help_' . $platform . '_send_credentials', '0') === '1') {
            $buttons[] = [['text' => '🔐 دریافت اطلاعات ورود', 'callback_data' => 'help_credentials_' . $platform]];
        }
        $this->sendMessage($chatId, $text, null, $buttons);
    }

    protected function sendHelpFile(int $chatId, string $platform, int $index): void
    {
        if (!in_array($platform, ['android', 'ios', 'windows'], true)) return;
        $links = json_decode(BotText::get('help_' . $platform . '_links', '[]'), true) ?: [];
        if (!isset($links[$index]) || ($links[$index]['type'] ?? '') !== 'file' || empty($links[$index]['file'])) {
            $this->sendHelpPlatform($chatId, $platform);
            return;
        }
        foreach ([$links[$index]] as $lk) {
            if (($lk['type'] ?? '') !== 'file' || empty($lk['file'])) continue;
            $path    = public_path(ltrim($lk['file'], '/'));
            $caption = !empty($lk['label']) ? $lk['label'] : '';
            $size    = file_exists($path) ? filesize($path) : 0;

            // 1) Telegram already holds this exact file: send it by id, at any size.
            if ($size > 0 && $this->sendCachedFile($chatId, $lk['file'], $size, $caption)) {
                continue;
            }
            // 2) Small enough to upload: upload once and keep the id for everyone after.
            if ($size > 0 && $size <= BotFileCache::$uploadLimit) {
                $fileId = $this->sendDocumentFile($chatId, $path, $caption);
                if ($fileId !== null) {
                    if ($fileId !== '') {
                        BotFileCache::put($lk['file'], $size, $fileId, basename((string) (parse_url((string) ($lk['url'] ?? ''), PHP_URL_PATH) ?: $path)));
                    }
                    continue;
                }
            }
            // 3) Too big for a bot to upload, and no admin has sent it yet: a link,
            //    and the admins are told once which file to send.
            $fileUrl = secure_url('/' . ltrim($lk['file'], '/'));
            $btnText = '⬇️ ' . ($caption !== '' ? $caption : 'دانلود فایل');
            $note    = ($caption !== '' ? $caption : 'فایل') . ' (حجم: ' . $this->humanSize($size) . ')';
            $this->sendMessage($chatId, $note, null, [[['text' => $btnText, 'url' => $fileUrl]]]);
            if ($size > BotFileCache::$uploadLimit) {
                BotFileCache::askAdmins();
            }
        }

    }

    /** The email and password for the app, after a help section that says to log in with them. */
    protected function sendCredentials(int $chatId, bool $renewed = false): void
    {
        $this->setMenuScreen('credentials');
        $cred = BotCredentials::forUser($this->user);
        $email = htmlspecialchars($cred['email'], ENT_QUOTES);

        if ($cred['password'] !== null) {
            $text = ($renewed ? "✅ رمز جدید ساخته شد؛ رمز قبلی دیگر کار نمی‌کند.\n\n" : '')
                . "🔐 اطلاعات ورود به برنامه\n\n"
                . "📧 ایمیل: <code>{$email}</code>\n"
                . '🔑 رمز عبور: <code>' . htmlspecialchars($cred['password'], ENT_QUOTES) . "</code>\n\n"
                . 'برای کپی، روی هر کدام بزنید.';
            $this->sendMessage($chatId, $text, null, null, 'HTML');
            return;
        }
        if ($cred['reason'] === 'privileged') {
            $this->sendMessage($chatId, "🔐 ایمیل ورود: <code>{$email}</code>\nرمز حساب‌های مدیریتی از طریق ربات نمایش داده یا ساخته نمی‌شود.", null, null, 'HTML');
            return;
        }
        $this->sendMessage($chatId,
            "🔐 اطلاعات ورود به برنامه\n\n"
            . "📧 ایمیل: <code>{$email}</code>\n"
            . "🔑 رمز عبور: همان رمزی که خودتان برای حساب گذاشته‌اید.\n\n"
            . 'اگر رمز را ندارید، با دکمه‌ی زیر یک رمز تازه بسازید.',
            null, [[['text' => '🔄 ساخت رمز جدید', 'callback_data' => 'cred_reset']]], 'HTML');
    }

    /** Sends [file] by the id Telegram gave it, if one is stored for this size. */
    protected function sendCachedFile(int $chatId, string $file, int $size, string $caption): bool
    {
        $fileId = BotFileCache::get($file, $size);
        if ($fileId === null) {
            return false;
        }
        $data = ['chat_id' => $chatId, 'document' => $fileId];
        if ($caption !== '') $data['caption'] = $caption;
        try {
            $resp = Http::timeout(30)->post($this->apiUrl . 'sendDocument', $data);
            if ($resp->json('ok') === true) {
                return true;
            }
            // 400 = Telegram does not know this id (any more). Anything else -
            // a user who blocked the bot, a rate limit - says nothing about the id.
            if ($resp->status() === 400) {
                BotFileCache::forget($file);
            }
            Log::warning('sendDocument(file_id) refused', ['file' => basename($file), 'status' => $resp->status(), 'description' => $resp->json('description')]);
        } catch (\Throwable $e) {
            Log::error('sendDocument(file_id) error', ['msg' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * An admin sent a document: if it is one of the smart bot's files (same
     * size to the byte as the panel's copy), keep Telegram's id for it.
     * False lets any other document go on its usual way.
     */
    protected function rememberAdminFile(int $chatId, array $document): bool
    {
        $size = (int) ($document['file_size'] ?? 0);
        $fileId = (string) ($document['file_id'] ?? '');
        if ($size <= 0 || $fileId === '') {
            return false;
        }

        $matches = BotFileCache::matching($size);
        if (!$matches) {
            // Only speak up for what looks like an app, and not in the middle of a step.
            $name = strtolower((string) ($document['file_name'] ?? ''));
            if ($this->user->bot_step || !preg_match('/\.(apk|exe|msi|zip|dmg|ipa)$/', $name)) {
                return false;
            }
            $this->sendMessage($chatId, "❓ این فایل با هیچ‌کدام از فایل‌های «ربات هوشمند» پنل هم‌اندازه نیست، پس ذخیره نشد.\n\n" . $this->filesStatusText());
            return true;
        }

        $name = (string) ($document['file_name'] ?? '');
        $lines = [];
        foreach ($matches as $m) {
            BotFileCache::put($m['file'], $size, $fileId, $name);
            $lines[] = '• ' . BotFileCache::describe($m);
        }
        $text = "✅ ذخیره شد. از این به بعد این فایل مستقیم برای کاربران فرستاده می‌شود:\n" . implode("\n", $lines);
        $left = BotFileCache::missing();
        if ($left) {
            $text .= "\n\nهنوز مانده:\n" . implode("\n", array_map(fn ($l) => '• ' . BotFileCache::describe($l) . " — {$l['name']}", $left));
        }
        $this->sendMessage($chatId, $text);
        return true;
    }

    /** /files: what the help sections will send, file or link. */
    protected function sendFilesStatus(int $chatId): void
    {
        $this->setMenuScreen('files');
        $this->sendMessage($chatId, $this->filesStatusText());
    }

    protected function filesStatusText(): string
    {
        $links = BotFileCache::fileLinks();
        if (!$links) {
            return 'در «ربات هوشمند» پنل هیچ فایلی برای ارسال تنظیم نشده است.';
        }
        $lines = [];
        foreach ($links as $l) {
            $head = BotFileCache::describe($l) . ' — ' . $l['name'] . ' (' . BotFileCache::humanSize($l['size']) . ')';
            if (BotFileCache::get($l['file'], $l['size']) !== null) {
                $lines[] = "✅ {$head}\n   به‌صورت فایل فرستاده می‌شود";
            } elseif ($l['size'] <= BotFileCache::$uploadLimit) {
                $lines[] = "✅ {$head}\n   بار اول آپلود می‌شود، بعد فوری";
            } else {
                $lines[] = "⚠️ {$head}\n   فعلاً لینک؛ همین فایل را یک بار برای ربات بفرستید";
            }
        }
        return "📦 فایل‌های راهنما:\n\n" . implode("\n\n", $lines);
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

    /**
     * Uploads [path]. Returns the file_id Telegram gave it ('' if it was sent
     * but no id came back), or null if it was not sent.
     */
    protected function sendDocumentFile(int $chatId, string $path, string $caption = ''): ?string
    {
        try {
            $payload = ['chat_id' => $chatId];
            if ($caption !== '') $payload['caption'] = $caption;
            $resp = Http::attach('document', fopen($path, 'r'), basename($path))
                ->timeout(180)
                ->post($this->apiUrl . 'sendDocument', $payload);
            Log::info('sendDocumentFile', ['file' => basename($path), 'size' => @filesize($path), 'status' => $resp->status(), 'ok' => $resp->json('ok')]);
            if ($resp->json('ok') === true) {
                $fileId = $resp->json('result.document.file_id');
                return is_string($fileId) ? $fileId : '';
            }
        } catch (\Throwable $e) {
            Log::error('sendDocumentFile error', ['msg' => $e->getMessage()]);
        }
        return null;
    }

    protected function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    protected function sendHelpInfo(int $chatId): void
    {
        $this->setMenuScreen('help');
        $intro = BotText::get('text_help', 'دستگاه خود را انتخاب کنید تا آموزش، برنامه و فایل‌های اتصال را دریافت کنید.');
        $text = "📚 <b>راهنمای اتصال</b>\n\n" . BotView::escape($intro);
        $text .= "\n\n۱. برنامهٔ مناسب دستگاه را نصب کنید.\n۲. لینک اشتراک را از «سرویس‌های من» بگیرید.\n۳. لینک را در برنامه وارد و اتصال را فعال کنید.\n\nاگر مشکلی داشتید، پشتیبانی در دسترس شماست.";
        $this->sendMessage($chatId, $text, null, [
            [['text' => '📱 اندروید', 'callback_data' => 'help_android'], ['text' => '🍎 آیفون و آیپد', 'callback_data' => 'help_ios']],
            [['text' => '💻 ویندوز', 'callback_data' => 'help_windows']],
            [['text' => '🔗 لینک اتصال من', 'callback_data' => 'get_sub_link']],
            [['text' => '📞 کمک از پشتیبانی', 'callback_data' => 'support_menu']],
            BotView::home(),
        ], 'HTML');
    }

    /**
     * پردازش step
     */
    protected function handleStep(int $chatId, string $text, string $step): void
    {
        if ($text === '/cancel' || $this->isMenuButton($text) || preg_match('/^\/start\s+/', $text)) {
            $this->clearInput();
            $this->handleCommand($chatId, $text);
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
            case 'ticket_confirm':
                $this->sendMessage($chatId, 'برای افزودن توضیحات یا ارسال درخواست، از دکمه‌های پیام قبلی استفاده کنید. برای انصراف /cancel را بفرستید.', null, BotView::cancel());
                break;
            case 'setting_transit_url':
                if ($this->user->is_admin) {
                    $this->processTransitUrl($chatId, $text);
                } else {
                    $this->user->update(['bot_step' => null, 'bot_data' => null]);
                    $this->sendMainMenu($chatId);
                }
                break;
            case 'waiting_card_receipt':
                $this->sendMessage($chatId, '📸 لطفاً عکس رسید بانکی را ارسال کنید.');
                break;
            case 'card_diff_amount':
                $this->processCardDiffAmount($chatId, $text);
                break;
            default:
                $this->clearInput();
                $this->sendMainMenu($chatId);
        }
    }

    /**
     * پردازش کد تخفیف
     */
    protected function processGiftCard(int $chatId, string $code): void
    {
        try {
            $purchasedGift = \App\Services\GiftOrderService::redeem((int)$this->user->id, $code);
            if ($purchasedGift) {
                $this->user->update(['bot_step' => null]);
                $this->sendMessage($chatId, '✅ هدیه شما اعمال شد. وضعیت بسته را در اشتراک و سفارش‌ها مشاهده کنید.');
                return;
            }
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, '❌ ' . $e->getMessage());
            return;
        }
        // One transaction, user then card read under a lock (the same order as
        // the web redemption). It had neither: the same code sent twice at once
        // passed the "already used" and usage-limit checks together and was
        // credited twice, and a single-use card could serve several people.
        [$ok, $text] = \Illuminate\Support\Facades\DB::transaction(function () use ($code) {
            $user = \App\Models\User::lockForUpdate()->find($this->user->id);
            $giftCard = \App\Models\Giftcard::where('code', $code)->lockForUpdate()->first();

            if (!$user || !$giftCard) {
                return [false, "❌ کد گیفت کارت نامعتبر است."];
            }

            $currentTime = time();
        
            // بررسی تاریخ شروع
            if ($giftCard->started_at && $currentTime < $giftCard->started_at) {
                return [false, "❌ این گیفت کارت هنوز فعال نشده است."];
            }
        
            // بررسی تاریخ انقضا
            if ($giftCard->ended_at && $currentTime > $giftCard->ended_at) {
                return [false, "❌ این گیفت کارت منقضی شده است."];
            }

            // بررسی محدودیت استفاده
            if ($giftCard->limit_use !== null) {
                if (!is_numeric($giftCard->limit_use) || $giftCard->limit_use <= 0) {
                    return [false, "❌ ظرفیت این گیفت کارت تکمیل شده است."];
                }
            }

            // بررسی استفاده قبلی
            $usedUserIds = $giftCard->used_user_ids ? json_decode($giftCard->used_user_ids, true) : [];
            if (!is_array($usedUserIds)) {
                $usedUserIds = [];
            }
            if (in_array($user->id, $usedUserIds)) {
                return [false, "❌ شما قبلاً از این گیفت کارت استفاده کرده‌اید."];
            }

            // ثبت استفاده
            $usedUserIds[] = $user->id;
            $giftCard->used_user_ids = json_encode($usedUserIds);

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
                        return [false, "❌ شما اشتراک فعالی ندارید که بتوان مدت آن را افزایش داد."];
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
                            return [false, "❌ پلن مربوط به این گیفت کارت یافت نشد."];
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
                        return [false, "❌ شما در حال حاضر اشتراک فعال دارید. این گیفت کارت فقط برای کاربران بدون اشتراک است."];
                    }
                    break;
                
                default:
                    return [false, "❌ نوع گیفت کارت نامعتبر است."];
            }

            // کاهش limit_use
            if ($giftCard->limit_use !== null) {
                $giftCard->limit_use -= 1;
            }
        
            $giftCard->save();
            $user->save();

            // Days added (2) or a plan handed over (5) move the plan's expiry;
            // paid add-on grants ride on it, as after any purchase.
            if (in_array((int)$giftCard->type, [2, 5], true)) {
                \App\Services\AddonBillingService::syncGrantExpiry($user->id, $user->expired_at);
            }

            return [true, $resultText];
        });

        if (!$ok) {
            $this->sendMessage($chatId, $text);
            return;
        }
        $this->user->update(['bot_step' => null]);
        $this->sendMessage($chatId, "✅ گیفت کارت با موفقیت اعمال شد!\n\n" . $text);
    }


    /**
     * درخواست مبلغ شارژ
     */
    protected function askChargeAmount(int $chatId): void
    {
        $this->setMenuScreen('charge');
        $this->user->update(['bot_step' => 'enter_charge_amount', 'bot_data' => null]);
        $this->sendMessage($chatId, "💳 <b>شارژ کیف پول</b>\n\nمبلغ دلخواه را به تومان ارسال کنید.\nحداقل: ۱۰٬۰۰۰ تومان\nمثال: <code>۵۰٬۰۰۰</code>\n\nیا یکی از مبلغ‌های زیر را انتخاب کنید:", null, [
            [['text' => '۵۰٬۰۰۰ تومان', 'callback_data' => 'charge_amount_50000'], ['text' => '۱۰۰٬۰۰۰ تومان', 'callback_data' => 'charge_amount_100000']],
            [['text' => '۲۰۰٬۰۰۰ تومان', 'callback_data' => 'charge_amount_200000']],
            BotView::cancel()[0],
        ], 'HTML');
    }

    /**
     * پردازش مبلغ شارژ
     */
    protected function processChargeAmount(int $chatId, string $text): void
    {
        $amount = BotView::amount($text);
        if ($amount === null || $amount < 10000) {
            $this->sendMessage($chatId, 'مبلغ را به‌صورت عدد صحیح و حداقل ۱۰٬۰۰۰ تومان وارد کنید؛ مثل ۵۰٬۰۰۰.', null, BotView::cancel());
            return;
        }
        $result = (new TelegramOrderService($this->user))->createDepositOrder($amount);
        if (!$result['success']) {
            $this->sendMessage($chatId, '❌ ' . $result['message'], null, BotView::cancel());
            return;
        }
        $this->clearInput();
        $this->showPaymentGateways($chatId, $result['trade_no']);
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
            } elseif (!$this->canAttachInviter()) {
                $this->sendMessage($chatId, "ℹ️ کد دعوت فقط هنگام ساخت حساب جدید قابل استفاده است.");
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
            
            if ($referrer && $referrer->id !== $this->user->id && !$this->user->invite_user_id && $this->canAttachInviter()) {
                $this->user->update(['invite_user_id' => $referrer->id]);
            }
        }
        // پردازش bind برای اتصال حساب
        elseif (\Illuminate\Support\Str::startsWith($param, 'bind_')) {
            $token = \Illuminate\Support\Str::after($param, 'bind_');
            $existingUser = User::where('token', $token)->first();
            
            if ($existingUser && !$existingUser->telegram_id) {
                if ($this->isPrivilegedAccount($existingUser)) {
                    $this->sendMessage($chatId, self::PRIVILEGED_BIND_MESSAGE);
                } else {
                    $existingUser->update(['telegram_id' => $chatId]);
                    $this->user = $existingUser;
                    $this->sendMessage($chatId, "✅ حساب شما با موفقیت به تلگرام متصل شد!");
                }
            }
        }
        
        $this->sendMainMenu($chatId);
    }

    /**
     * نمایش جزئیات پلن
     */
    protected function showPlanDetails(int $chatId, int $messageId, int $planId): void
    {
        $this->setMenuScreen('plan', ['id' => $planId]);
        $plan = Plan::find($planId);
        $renewal = $plan && (int) $this->user->plan_id === $planId && $plan->renew;
        if (!$plan || (!$plan->show && !$renewal)) {
            $this->sendMessage($chatId, 'این پلن در حال حاضر قابل خرید نیست.', null, [[['text' => '🛒 بازگشت به فروشگاه', 'callback_data' => 'plans_list']]]);
            return;
        }
        $name = BotView::escape(mb_strimwidth($plan->name, 0, 120, '…'));
        $text = "📦 <b>{$name}</b>\n\n";
        $text .= '📊 حجم: ' . $this->formatBytes((int) ($plan->transfer_enable * 1073741824));
        if ($plan->device_limit) $text .= "\n📱 تعداد دستگاه: {$plan->device_limit}";
        if ($plan->speed_limit) $text .= "\n⚡ سرعت: {$plan->speed_limit} Mbps";
        if ($plan->content) $text .= "\n\n" . BotView::escape(mb_strimwidth(strip_tags($plan->content), 0, 500, '…'));
        $text .= "\n\n<b>دورهٔ مورد نظر را انتخاب کنید</b>\nموجودی کیف پول و تخفیف حساب هنگام ثبت سفارش محاسبه می‌شود.";
        if ($this->user->plan_id) $text .= "\nاگر سرویس فعلی هنوز اعتبار و حجم داشته باشد، بستهٔ جدید طبق قوانین پنل رزرو می‌شود.";
        $buttons = [];
        foreach (BotView::PERIODS as $period => $label) {
            $price = $plan->{$period . '_price'};
            if ($price !== null) $buttons[] = [['text' => $label . ' · ' . BotView::money($price), 'callback_data' => "buy_{$planId}_{$period}"]];
        }
        if (!$buttons) $text .= "\n\nدر حال حاضر دوره‌ای برای خرید موجود نیست.";
        $buttons[] = [['text' => '🔙 همهٔ اشتراک‌ها', 'callback_data' => 'plans_list']];
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }

    /**
     * خرید پلن
     */
    protected function handleBuyPlan(int $chatId, string $data): void
    {
        // parse data: buy_planId_period
        // At most three parts: the period itself may contain "_" (half_year),
        // and splitting it made the six-month button ask for "half_price".
        $parts = explode("_", $data, 3);
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
            $done = \App\Services\OrderService::isReserved($result["order"])
                ? "📦 بسته شما رزرو شد و بعد از تمام شدن اشتراک فعلی، خودکار فعال می‌شود."
                : "سرویس شما فعال شد!";
            $this->clearInput();
            $this->user->refresh();
            $this->setMenuScreen('order', ['trade_no' => $result['order']->trade_no]);
            $this->sendMessage($chatId, "✅ " . $result["message"] . "\n\n" . $done, null, [
                [['text' => '📦 مشاهده سرویس و راهنمای اتصال', 'callback_data' => 'nav_services']],
                [['text' => '🧾 جزئیات سفارش', 'callback_data' => 'order_view_' . $result['order']->trade_no]], BotView::home(),
            ]);
            return;
        }

        $this->clearInput();
        $this->showCheckout($chatId, $result['trade_no']);
    }

    protected function showCheckout(int $chatId, string $tradeNo): void
    {
        $order = Order::where('user_id', $this->user->id)->where('trade_no', $tradeNo)->first();
        if (!$order || (int) $order->status !== 0) {
            $this->showOrderDetails($chatId, $tradeNo);
            return;
        }
        $this->setMenuScreen('checkout', ['trade_no' => $tradeNo]);
        $this->clearInput();
        $text = "🛒 <b>تکمیل سفارش</b>\n\nشناسه: <code>" . BotView::escape($tradeNo) . "</code>\n";
        $text .= 'مبلغ قابل پرداخت: <b>' . BotView::money($order->total_amount) . "</b>\n\n🎫 آیا کد تخفیف دارید؟";
        $buttons = [];
        if (!$order->coupon_id) $buttons[] = [['text' => '🎫 واردکردن کد تخفیف', 'callback_data' => 'coupon_yes_' . $tradeNo]];
        $buttons[] = [['text' => '💳 ادامهٔ پرداخت', 'callback_data' => 'coupon_no_' . $tradeNo]];
        $buttons[] = [['text' => '✖️ لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]];
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }

    protected function showReceiptInstructions(int $chatId, string $tradeNo): void
    {
        $payment = \App\Models\CardPayment::where('user_id', $this->user->id)->where('trade_no', $tradeNo)->orderByDesc('id')->first();
        $order = Order::where('user_id', $this->user->id)->where('trade_no', $tradeNo)->first();
        if (!$payment || !$order || (int) $order->status !== 0 || !$payment->canBeClaimed()) {
            $this->showOrderDetails($chatId, $tradeNo);
            return;
        }
        $this->setMenuScreen('receipt', ['trade_no' => $tradeNo]);
        $this->user->update(['bot_step' => 'waiting_card_receipt', 'bot_data' => json_encode([
            'trade_no' => $tradeNo, 'payment_id' => $payment->id, 'amount' => $payment->expected_amount,
        ])]);
        $text = "💳 <b>پرداخت کارت‌به‌کارت</b>\n\nشماره کارت:\n<code>" . BotView::escape($payment->card_number) . "</code>\n";
        $text .= 'صاحب کارت: ' . BotView::escape($payment->card_holder) . "\n";
        $text .= 'مبلغ: <b>' . BotView::money($payment->expected_amount) . "</b>\n";
        $text .= 'مهلت باقی‌مانده: ' . max(0, (int) ceil(($payment->expires_at - time()) / 60)) . " دقیقه\n\n";
        $text .= 'مبلغ دقیق را واریز و عکس رسید را همین‌جا ارسال کنید. برگشت از این صفحه، سفارش را لغو نمی‌کند.';
        $this->sendMessage($chatId, $text, null, [
            [['text' => '✖️ لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]],
        ], 'HTML');
    }

    /**
     * ساخت اکانت تست
     */
    protected function createTestAccount(int $chatId, int $panelId): void
    {
        // Old external-panel buttons never provisioned an account. Retire them safely.
        $this->sendMessage($chatId, 'این دکمه قدیمی است. اکانت تست از طریق پلن آزمایشی پنل فعال می‌شود.');
        $this->handleTestAccount($chatId);
    }

    /**
     * ارسال لینک اشتراک
     */
    protected function sendSubscriptionLink(int $chatId): void
    {
        $this->setMenuScreen('subscription');
        $user = $this->user;
        if (!$user->plan_id || ($user->expired_at !== null && $user->expired_at <= time())) {
            $this->sendMessage($chatId, 'برای دریافت لینک اتصال، یک اشتراک معتبر تهیه یا تمدید کنید.', null, [
                [['text' => '🛒 مشاهده اشتراک‌ها', 'callback_data' => 'plans_list']], BotView::home(),
            ]);
            return;
        }
        $link = BotView::escape(\App\Utils\Helper::getSubscribeUrl($user->token));
        $text = "🔗 <b>لینک اتصال شما</b>\n\n<code>{$link}</code>\n\nروی لینک بزنید تا کپی شود، سپس آن را در برنامهٔ اتصال وارد کنید.\nاین لینک شخصی است؛ آن را در اختیار دیگران نگذارید.";
        $this->sendMessage($chatId, $text, null, [
            [['text' => '📚 آموزش نصب و اتصال', 'callback_data' => 'nav_help']],
            [['text' => '📦 وضعیت سرویس', 'callback_data' => 'nav_services']], BotView::home(),
        ], 'HTML');
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
            $this->setMenuScreen('order', ['trade_no' => $tradeNo]);
            $order = Order::where('user_id', $this->user->id)->where('trade_no', $tradeNo)->first();
            $text = "💳 <b>پرداخت سفارش</b>\n\n";
            $text .= 'مبلغ نهایی درگاه: <b>' . BotView::money($order->total_amount + $order->handling_amount) . "</b>\n";
            if ($order->handling_amount) $text .= 'شامل کارمزد: ' . BotView::money($order->handling_amount) . "\n";
            $text .= "\nپس از پرداخت، نتیجه را از دکمهٔ پیگیری بررسی کنید.";
            $buttons = [
                [['text' => '💳 پرداخت آنلاین', 'url' => $result['data']]],
                [['text' => '🔃 پیگیری نتیجهٔ پرداخت', 'callback_data' => 'order_view_' . $tradeNo]],
                [['text' => '❌ لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]]
            ];
            $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
        } elseif ($result['type'] == 0) {
            $this->showReceiptInstructions($chatId, $tradeNo);
        } else {
            $this->setMenuScreen('order', ['trade_no' => $tradeNo]);
            $this->sendMessage($chatId, 'درخواست پرداخت ثبت شد. وضعیت و ادامهٔ مراحل را از جزئیات سفارش دنبال کنید.', null, [
                [['text' => '🧾 جزئیات سفارش', 'callback_data' => 'order_view_' . $tradeNo]], BotView::home(),
            ]);
        }
    }

    /**
     * لغو سفارش
     */
    protected function handleCancelOrder(int $chatId, string $tradeNo): void
    {
        $this->setMenuScreen('order', ['trade_no' => $tradeNo]);
        $result = (new TelegramOrderService($this->user))->cancelOrder($tradeNo);
        if ($result['success']) $this->clearInput();
        $this->sendMessage($chatId, ($result['success'] ? '✅ ' : '❌ ') . $result['message'], null, [
            [['text' => '🧾 سفارش‌های من', 'callback_data' => 'orders_page_1']], BotView::home(),
        ]);
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
            '🧾 سفارش‌های من',
            '/start', '/menu', '/cancel', '/back', '‹ برگشت', '/help', '/plans', '/account',
            '/services', '/traffic', '/wallet', '/orders', '/support',
        ];
        return in_array($text, $menuButtons);
    }

    /**
     * تمدید اشتراک
     */
    protected function handleRenew(int $chatId, int $planId): void
    {
        if ((int) $this->user->plan_id !== $planId) {
            $this->sendPlansList($chatId);
            return;
        }
        $plan = Plan::find($planId);
        if (!$plan || !$plan->renew) {
            $this->sendMessage($chatId, 'تمدید این پلن غیرفعال است؛ یک اشتراک دیگر انتخاب کنید.');
            $this->sendPlansList($chatId);
            return;
        }
        $this->showPlanDetails($chatId, 0, $planId);
    }

    /**
     * درخواست کد تخفیف
     */
    protected function askForCouponCode(int $chatId, string $tradeNo): void
    {
        $this->setMenuScreen('coupon', ['trade_no' => $tradeNo]);
        $order = Order::where('user_id', $this->user->id)->where('trade_no', $tradeNo)->where('status', 0)->first();
        if (!$order) {
            $this->showOrderDetails($chatId, $tradeNo);
            return;
        }
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
     *
     * The website's rules through CouponService: visibility, dates, usage and
     * per-user limits, plan and period limits, and the use is counted. The bot
     * used to check only dates and the plan (and crashed on the plan list),
     * read the coupon types the wrong way round - a fixed-amount coupon became
     * a percentage of thousands and zeroed the order - and let the same code
     * be applied to the same order again and again.
     *
     * The discount is taken from the plan's price, as on the website. The order
     * may already be part-paid from the wallet; whatever the discount covers
     * beyond the part still due goes back to the wallet.
     */
    protected function processCouponCode(int $chatId, string $code): void
    {
        $botData = json_decode($this->user->bot_data ?? '', true);
        $tradeNo = $botData['trade_no'] ?? null;
        
        if (!$tradeNo) {
            $this->sendMessage($chatId, "❌ خطا در پردازش. لطفاً دوباره سفارش دهید.");
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        $code = trim($code);
        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($tradeNo, $code) {
                $order = \App\Models\Order::where('trade_no', $tradeNo)
                    ->where('user_id', $this->user->id)
                    ->lockForUpdate()
                    ->first();
                if (!$order || (int)$order->status !== 0) {
                    return ['error' => "❌ سفارش یافت نشد یا دیگر در انتظار پرداخت نیست.", 'reset' => true];
                }
                if (!$order->plan_id) {
                    return ['error' => "❌ کد تخفیف برای شارژ کیف پول قابل استفاده نیست.", 'reset' => true];
                }
                if ($order->is_gift) {
                    return ['error' => __('Discount codes cannot be used to buy gifts'), 'reset' => true];
                }
                if ($order->coupon_id) {
                    return ['error' => "❌ برای این سفارش قبلاً کد تخفیف ثبت شده است.", 'reset' => true];
                }
                $plan = Plan::find($order->plan_id);
                $price = $plan ? $plan->{$order->period} : null;
                if ($price === null) {
                    return ['error' => "❌ این دوره از پلن دیگر قابل خرید نیست.", 'reset' => true];
                }

                $user = \App\Models\User::lockForUpdate()->find($order->user_id);
                if (!$user) throw new \RuntimeException('user not found');
                $couponService = new \App\Services\CouponService($code);
                $probe = new \App\Models\Order();
                $probe->user_id = $order->user_id;
                $probe->plan_id = $order->plan_id;
                $probe->period = $order->period;
                $probe->total_amount = $price;
                // Throws an HttpException with the reason when the coupon may
                // not be used; counts the use when it may.
                if (!$couponService->use($probe)) {
                    return ['error' => "❌ این کد تخفیف دیگر قابل استفاده نیست."];
                }
                $coupon = $couponService->getCoupon();
                $isPurchasedGift = $couponService->isPurchasedGift();

                $payable = (int)$order->total_amount;
                $walletPart = (int)$order->balance_amount;
                $discount = max(0, (int)round($probe->discount_amount));
                $discount = min($discount, $payable + $walletPart);

                $refund = 0;
                if ($isPurchasedGift) {
                    // This pending order may have been priced as an ordinary
                    // plan change. A gift cannot turn its old surplus/refund
                    // metadata into cash when this order is later opened.
                    $refund = $walletPart;
                    $discount = (int)$price;
                    $order->total_amount = 0;
                    $order->balance_amount = 0;
                    $order->surplus_amount = null;
                    $order->refund_amount = null;
                    $order->surplus_order_ids = null;
                    $order->discount_amount = $discount;
                    \App\Services\GiftOrderService::setRedemptionType($order, $user);
                } elseif ($discount <= $payable) {
                    $order->total_amount = $payable - $discount;
                } else {
                    $refund = $discount - $payable;
                    $order->total_amount = 0;
                    $order->balance_amount = $walletPart - $refund;
                }
                if (!$isPurchasedGift) $order->discount_amount = (int)$order->discount_amount + $discount;
                $order->coupon_id = $coupon->id;

                // Commission follows what is actually paid, as on the website
                // where the coupon is applied before it is worked out.
                $order->commission_balance = 0;
                $order->invite_user_id = null;
                if (!$isPurchasedGift) {
                    (new \App\Services\OrderService($order))->setInvite($user);
                }

                if ($refund > 0 && !(new \App\Services\UserService())->addBalance($order->user_id, $refund)) {
                    throw new \RuntimeException('wallet refund failed');
                }
                if (!$order->save()) {
                    throw new \RuntimeException('order save failed');
                }

                return [
                    'order' => $order,
                    'coupon' => $coupon,
                    'original' => $isPurchasedGift ? (int)$price : $payable,
                    'discount' => $discount,
                    'refund' => $refund,
                ];
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // CouponService's own reason (expired, limit reached, not for this
            // plan...). The step stays, so another code can be tried.
            $this->sendMessage($chatId, "❌ " . $e->getMessage() . "\nدوباره تلاش کنید یا روی انصراف بزنید.");
            return;
        } catch (\Throwable $e) {
            Log::error('Telegram coupon failed', ['trade_no' => $tradeNo, 'error' => $e->getMessage()]);
            $this->sendMessage($chatId, "❌ خطا در اعمال کد تخفیف. لطفاً دوباره تلاش کنید.");
            return;
        }

        if (isset($result['error'])) {
            if (!empty($result['reset'])) {
                $this->user->update(['bot_step' => null, 'bot_data' => null]);
            }
            $this->sendMessage($chatId, $result['error']);
            return;
        }

        $this->user->update(['bot_step' => null, 'bot_data' => null]);

        $order = $result['order'];
        $coupon = $result['coupon'];
        $discount = $result['discount'];
        $newAmount = (int)$order->total_amount;
        $discountText = (int)$coupon->type === 2
            ? $coupon->value . '%'
            : number_format($coupon->value) . ' تومان';

        $text = "✅ کد تخفیف اعمال شد!\n\n";
        $text .= "💰 مبلغ اولیه: " . number_format($result['original']) . " تومان\n";
        $text .= "🎫 تخفیف: " . $discountText . " (" . number_format($discount) . " تومان)\n";
        if ($result['refund'] > 0) {
            $text .= "👛 بازگشت به کیف پول: " . number_format($result['refund']) . " تومان\n";
        }
        $text .= "💵 مبلغ نهایی: " . number_format($newAmount) . " تومان\n\n";

        if ($newAmount <= 0) {
            // پرداخت رایگان
            $orderService = new \App\Services\OrderService($order);
            $orderService->paid($order->trade_no);
            $text .= \App\Services\OrderService::isReserved($order)
                ? "📦 سفارش شما رایگان شد و بسته رزرو شد؛ بعد از تمام شدن اشتراک فعلی، خودکار فعال می‌شود."
                : "🎉 سفارش شما رایگان شد و فعال گردید!";
            $this->sendMessage($chatId, $text, null, [
                [['text' => '📦 مشاهده سرویس‌ها', 'callback_data' => 'nav_services']], BotView::home(),
            ]);
        } else {
            $this->sendMessage($chatId, $text);
            $this->showPaymentGateways($chatId, $order->trade_no);
        }
    }

    /**
     * نمایش درگاه‌های پرداخت
     */
    protected function showPaymentGateways(int $chatId, string $tradeNo): void
    {
        $this->setMenuScreen('gateways', ['trade_no' => $tradeNo]);
        $order = Order::where('trade_no', $tradeNo)->where('user_id', $this->user->id)->first();
        if (!$order || (int) $order->status !== 0) {
            $this->showOrderDetails($chatId, $tradeNo);
            return;
        }
        $this->clearInput();
        $payments = TelegramOrderService::getActivePayments();
        $text = "💳 <b>تکمیل پرداخت</b>\n\nشماره سفارش: <code>" . BotView::escape($tradeNo) . "</code>\n";
        $text .= 'قابل پرداخت: <b>' . BotView::money($order->total_amount) . "</b>\n\n";
        $text .= $payments ? 'درگاه را انتخاب کنید. کارمزد احتمالی درگاه پیش از پرداخت نمایش داده می‌شود.' : 'در حال حاضر درگاه فعالی وجود ندارد. سفارش شما ذخیره شده؛ بعداً از بخش سفارش‌ها ادامه دهید یا با پشتیبانی تماس بگیرید.';
        $buttons = [];
        foreach ($payments as $payment) $buttons[] = [['text' => '💳 ' . $payment['name'], 'callback_data' => 'pay_' . $tradeNo . '_' . $payment['id']]];
        $buttons[] = [['text' => '🔃 وضعیت سفارش', 'callback_data' => 'order_view_' . $tradeNo]];
        $buttons[] = [['text' => '✖️ لغو سفارش', 'callback_data' => 'cancel_order_' . $tradeNo]];
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
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
        // Read and move under a row lock. The bot used the user it loaded at
        // the start of the update, so tapping the button twice credited the
        // same commission twice.
        [$commission, $user] = \Illuminate\Support\Facades\DB::transaction(function () {
            $user = \App\Models\User::lockForUpdate()->find($this->user->id);
            $commission = $user ? ($user->commission_balance ?? 0) : 0;
            if ($commission <= 0) {
                return [0, $user];
            }
            $user->balance += $commission;
            $user->commission_balance = 0;
            $user->save();
            return [$commission, $user];
        });

        if ($commission <= 0) {
            $this->sendMessage($chatId, "❌ موجودی کمیسیون شما صفر است.");
            return;
        }

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

        if ($siteUser->telegram_id != $chatId && $this->isPrivilegedAccount($siteUser)) {
            $this->sendMessage($chatId, self::PRIVILEGED_BIND_MESSAGE);
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
        $this->sendMyServices($chatId);
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

        if ($siteUser->telegram_id != $chatId && $this->isPrivilegedAccount($siteUser)) {
            $this->sendMessage($chatId, self::PRIVILEGED_BIND_MESSAGE);
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
        $this->setMenuScreen('ticket_subject');
        // بررسی تیکت باز
        $openTicket = \App\Models\Ticket::where('user_id', $this->user->id)
            ->where('status', 0)
            ->first();
        
        if ($openTicket) {
            $this->sendMessage($chatId, "یک درخواست باز دارید؛ می‌توانید گفت‌وگو را ادامه دهید یا پس از حل مشکل آن را ببندید.", null, [
                [['text' => '💬 ادامهٔ گفت‌وگو', 'callback_data' => 'view_ticket_' . $openTicket->id]], BotView::home(),
            ]);
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
        
        $this->sendMessage($chatId, "📩 درخواست پشتیبانی\n\nموضوع مشکل را در یک جمله بنویسید.\nمثال: مشکل اتصال در اندروید", null, BotView::cancel());
    }
    
    /**
     * درخواست متن پیام تیکت
     */
    protected function askTicketMessage(int $chatId): void
    {
        $data = json_decode($this->user->bot_data ?? '{}', true) ?: [];
        if ($this->user->bot_step !== 'ticket_confirm' || empty($data['subject'])) {
            $this->sendSupportInfo($chatId);
            return;
        }
        $this->setMenuScreen('ticket_message', ['subject' => $data['subject']]);
        $this->user->update(['bot_step' => 'ticket_message']);
        $this->sendMessage($chatId, '📝 جزئیات مشکل را بنویسید؛ مثل نام برنامه، دستگاه و متن خطا. رمز عبور خود را ارسال نکنید.', null, BotView::cancel());
    }
    
    /**
     * ارسال تیکت بدون پیام اضافی
     */
    protected function sendTicketNow(int $chatId): void
    {
        $data = json_decode($this->user->bot_data ?? '{}', true) ?: [];
        if ($this->user->bot_step !== 'ticket_confirm' || empty($data['subject'])) {
            $this->sendSupportInfo($chatId);
            return;
        }
        $this->createTicket($chatId, $data['subject'], $data['subject'], 1);
    }

    protected function showTicketConfirmation(int $chatId, string $subject): void
    {
        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 120
            || (int) config('v2board.ticket_status', 0) === 2
            || \App\Models\Ticket::where('user_id', $this->user->id)->where('status', 0)->exists()) {
            $this->sendSupportInfo($chatId);
            return;
        }
        $this->setMenuScreen('ticket_confirm', ['subject' => $subject]);
        $this->user->update(['bot_step' => 'ticket_confirm', 'bot_data' => json_encode(['subject' => $subject], JSON_UNESCAPED_UNICODE)]);
        $this->sendMessage($chatId, "📋 موضوع: {$subject}\n\nجزئیات بیشتری برای پشتیبانی دارید؟", null, [
            [['text' => '📝 افزودن توضیحات', 'callback_data' => 'ticket_add_message']],
            [['text' => '📨 ارسال همین متن', 'callback_data' => 'ticket_send_now']],
        ]);
    }
    
    /**
     * پردازش مراحل تیکت
     */
    protected function handleTicketStep(int $chatId, string $text, string $step): void
    {
        $text = trim($text);
        $botData = json_decode($this->user->bot_data ?? '{}', true) ?: [];
        if ($step === 'ticket_subject') {
            if (mb_strlen($text) < 3 || mb_strlen($text) > 120) {
                $this->sendMessage($chatId, 'موضوع را بین ۳ تا ۱۲۰ نویسه بنویسید.', null, BotView::cancel());
                return;
            }
            $this->showTicketConfirmation($chatId, $text);
        } elseif ($step === 'ticket_message') {
            if (mb_strlen($text) < 3 || mb_strlen($text) > 3500 || empty($botData['subject'])) {
                $this->sendMessage($chatId, 'توضیحات را بین ۳ تا ۳۵۰۰ نویسه بنویسید.', null, BotView::cancel());
                return;
            }
            $this->createTicket($chatId, $botData['subject'], $text, 1);
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

            User::lockForUpdate()->findOrFail($this->user->id);
            if (\App\Models\Ticket::where('user_id', $this->user->id)->where('status', 0)->exists()) {
                \DB::rollBack();
                $this->clearInput();
                $this->sendSupportInfo($chatId);
                return;
            }
            
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
            $this->setMenuScreen('ticket', ['id' => (int) $ticket->id, 'page' => 1]);
            
            $levelText = ['🟢 پایین', '🟡 متوسط', '🔴 بالا'][$level] ?? 'متوسط';
            
            $this->sendMessage($chatId, "✅ تیکت شما با موفقیت ارسال شد!\n\n" .
                "🎫 شماره تیکت: #{$ticket->id}\n" .
                "📋 موضوع: {$subject}\n" .
                "⚡ اولویت: {$levelText}\n\n" .
                "از بخش درخواست‌های من می‌توانید پاسخ را ببینید.", null, [
                    [['text' => '💬 مشاهدهٔ درخواست', 'callback_data' => 'view_ticket_' . $ticket->id]], BotView::home(),
                ]);
            
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
    protected function showMyTickets(int $chatId, int $page = 1): void
    {
        $this->setMenuScreen('tickets', ['page' => $page]);
        $query = \App\Models\Ticket::where('user_id', $this->user->id);
        $pages = max(1, (int) ceil((clone $query)->count() / 5));
        $page = max(1, min($page, $pages));
        $tickets = $query->orderByDesc('id')->skip(($page - 1) * 5)->take(5)->get();
        $text = "📋 <b>درخواست‌های پشتیبانی</b>\n";
        $buttons = [];
        foreach ($tickets as $ticket) {
            $status = $ticket->status ? '⚪ بسته شده' : ($ticket->reply_status ? '💬 پاسخ پشتیبانی' : '🟡 در انتظار پاسخ');
            $subject = BotView::escape(mb_strimwidth($ticket->subject, 0, 90, '…'));
            $text .= "\n<b>#{$ticket->id} · {$subject}</b>\n{$status}  ·  " . $this->jalaliDate($ticket->created_at, 'Y/m/d') . "\n";
            $buttons[] = [['text' => "گفت‌وگو #{$ticket->id}", 'callback_data' => 'view_ticket_' . $ticket->id]];
        }
        if ($tickets->isEmpty()) $text .= "\nهنوز درخواستی ثبت نکرده‌اید.";
        $this->appendPagination($buttons, $page, $pages, 'tickets_page_');
        $buttons[] = [['text' => '📞 بازگشت به پشتیبانی', 'callback_data' => 'support_menu']];
        $buttons[] = BotView::home();
        $this->sendMessage($chatId, $text, null, $buttons, 'HTML');
    }
    
    /**
     * مشاهده یک تیکت
     */
    protected function viewTicket(int $chatId, int $ticketId, int $page = 1): void
    {
        $this->setMenuScreen('ticket', ['id' => $ticketId, 'page' => $page]);
        $ticket = \App\Models\Ticket::where('id', $ticketId)->where('user_id', $this->user->id)->first();
        if (!$ticket) {
            $this->sendMessage($chatId, 'درخواست یافت نشد.', null, [[['text' => '📋 درخواست‌های من', 'callback_data' => 'my_tickets']]]);
            return;
        }
        $this->clearInput();
        $query = \App\Models\TicketMessage::where('ticket_id', $ticketId);
        $pages = max(1, (int) ceil((clone $query)->count() / 5));
        $page = max(1, min($page, $pages));
        $messages = $query->orderByDesc('id')->skip(($page - 1) * 5)->take(5)->get()->reverse();
        $text = "🎫 درخواست #{$ticketId}\nموضوع: {$ticket->subject}\n";
        $text .= $ticket->status ? '⚪ بسته شده' : '🟢 باز';
        $text .= "\nصفحهٔ ۱ شامل جدیدترین پیام‌هاست.\n━━━━━━━━━━━━\n";
        foreach ($messages as $message) {
            $sender = (int) $message->user_id === (int) $this->user->id ? '👤 شما' : '💬 پشتیبانی';
            $text .= "\n{$sender} · " . $this->jalaliDate($message->created_at) . "\n{$message->message}\n";
        }
        $buttons = [];
        if (!$ticket->status) {
            $buttons[] = [['text' => '💬 نوشتن پاسخ', 'callback_data' => 'reply_ticket_' . $ticketId]];
            $buttons[] = [['text' => '✅ مشکل برطرف شد؛ بستن درخواست', 'callback_data' => 'ticket_close_' . $ticketId]];
        }
        $this->appendPagination($buttons, $page, $pages, 'ticket_thread_' . $ticketId . '_');
        $buttons[] = [['text' => '🔃 جدیدترین پیام‌ها', 'callback_data' => 'view_ticket_' . $ticketId]];
        $buttons[] = [['text' => '📋 درخواست‌های من', 'callback_data' => 'my_tickets']];
        $this->sendMessage($chatId, $text, null, $buttons);
    }
    
    /**
     * شروع پاسخ به تیکت
     */
    protected function startReplyTicket(int $chatId, int $ticketId): void
    {
        $this->setMenuScreen('ticket_reply', ['id' => $ticketId]);
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
        
        $this->sendMessage($chatId, "💬 پاسخ خود را برای درخواست #{$ticketId} بنویسید.", null, BotView::cancel());
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
        $message = trim($message);
        if (mb_strlen($message) < 1 || mb_strlen($message) > 3500) {
            $this->sendMessage($chatId, 'پاسخ را به‌صورت متن و حداکثر ۳۵۰۰ نویسه ارسال کنید.', null, BotView::cancel());
            return;
        }
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
        $this->setMenuScreen('ticket', ['id' => (int) $ticketId, 'page' => 1]);
        
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
        $this->setMenuScreen('settings');
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
        $this->setMenuScreen('transit');
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
        if (!$this->user->is_admin) {
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            $this->sendMainMenu($chatId);
            return;
        }
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

        if ($cardPayment->status !== \App\Models\CardPayment::STATUS_PENDING
            || (int)$cardPayment->user_id !== (int)$this->user->id) {
            $this->sendMessage($chatId, '❌ این پرداخت قبلاً پردازش شده.');
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        // The order must still be waiting for this payment: a card record can
        // outlive an order that was cancelled (and its wallet part given back).
        $pendingOrder = Order::find($cardPayment->order_id);
        if (!$pendingOrder || (int)$pendingOrder->status !== 0) {
            $this->sendMessage($chatId, '❌ سفارش این پرداخت دیگر در انتظار پرداخت نیست. لطفاً سفارش جدید ثبت کنید.');
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }

        // ثبت claim
        // Keep the Telegram photo file_id so the admin app can fetch & show the
        // receipt (getFile → download). Without this only Telegram admins see it.
        // Conditional on the record still being pending, decided in the UPDATE.
        $claimed = \App\Models\CardPayment::where('id', $cardPayment->id)
            ->where('status', \App\Models\CardPayment::STATUS_PENDING)
            ->update([
                'status' => \App\Models\CardPayment::STATUS_CLAIMED,
                'claimed_at' => time(),
                'tracking_number' => 'receipt_photo',
                'receipt_file_id' => $fileId,
                'updated_at' => time(),
            ]);
        if (!$claimed) {
            $this->sendMessage($chatId, '❌ این پرداخت قبلاً پردازش شده.');
            $this->user->update(['bot_step' => null, 'bot_data' => null]);
            return;
        }
        $cardPayment = \App\Models\CardPayment::find($cardPayment->id);

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

        $this->setMenuScreen('order', ['trade_no' => $tradeNo]);
        $this->sendMessage($chatId, "✅ رسید شما ارسال شد.\nمنتظر تأیید ادمین باشید. نتیجه از طریق ربات اطلاع‌رسانی می‌شود.", null, [
            [['text' => '🧾 پیگیری رسید', 'callback_data' => 'order_view_' . $tradeNo]],
        ]);
    }




    protected function showReservedPlans(int $chatId, int $messageId): void
    {
        $this->setMenuScreen('reserved');
        $reserved = \App\Models\ReservedPlan::where('user_id', $this->user->id)->where('status', 0)->orderBy('id')->get();
        $text = "📋 <b>بسته‌های رزرو</b>\n\nبسته‌ها به ترتیب رزرو و پس از پایان حجم یا اعتبار سرویس فعلی، طبق قوانین پنل فعال می‌شوند.\n";
        foreach ($reserved as $index => $item) {
            $plan = Plan::find($item->plan_id);
            $name = BotView::escape(mb_strimwidth($plan ? $plan->name : 'اشتراک', 0, 90, '…'));
            $period = BotView::PERIODS[preg_replace('/_price$/', '', $item->period)] ?? 'اشتراک';
            $text .= "\n" . ($index + 1) . ". <b>{$name}</b>\n{$period} · " . $this->jalaliDate($item->created_at, 'Y/m/d') . "\n";
        }
        if ($reserved->isEmpty()) $text .= "\nدر حال حاضر بسته‌ای در صف فعال‌سازی ندارید.";
        $this->sendMessage($chatId, $text, null, [
            [['text' => '📦 وضعیت سرویس فعلی', 'callback_data' => 'nav_services']], BotView::home(),
        ], 'HTML');
    }
}
