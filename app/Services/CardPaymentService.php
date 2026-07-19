<?php

namespace App\Services;

use App\Models\CardPayment;
use App\Models\Order;
use App\Models\User;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class CardPaymentService
{
    protected $telegramToken;
    protected $adminChatId;

    public function __construct()
    {
        $this->telegramToken = config('v2board.telegram_bot_token');
        
        // دریافت chat_id ادمین از تنظیمات gateway
        $payment = Payment::where('payment', 'Card2Card')
            ->where('enable', 1)
            ->first();
        
        if ($payment && isset($payment->config['telegram_admin_id'])) {
            $this->adminChatId = $payment->config['telegram_admin_id'];
        }
    }

    /**
     * ارسال نوتیفیکیشن به ادمین
     */
    private function getAdminChatIds(): array
    {
        if (\App\Models\BotSetting::getBool('notify_all_admins', false)) {
            return \App\Models\User::where('is_admin', 1)
                ->whereNotNull('telegram_id')
                ->pluck('telegram_id')
                ->map(function ($v) { return (string) $v; })
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
        return $this->adminChatId ? [$this->adminChatId] : [];
    }

    public function sendAdminNotification(CardPayment $cardPayment): bool
    {
        $targets = $this->getAdminChatIds();
        if (empty($this->telegramToken) || empty($targets)) {
            Log::channel('payment')->warning('Telegram config missing for card payment notification');
            return false;
        }

        $user = User::find($cardPayment->user_id);
        $order = Order::find($cardPayment->order_id);

        $amountToman = number_format($cardPayment->expected_amount);
        $claimedAt = date('Y-m-d H:i:s', $cardPayment->claimed_at);
        $createdAt = date('Y-m-d H:i:s', $cardPayment->created_at);
        
        // محاسبه فاصله زمانی
        $diffSeconds = $cardPayment->claimed_at - $cardPayment->created_at;
        $diffMinutes = floor($diffSeconds / 60);
        $diffSecondsRem = $diffSeconds % 60;

        // هشدارها
        $warnings = [];
        if ($cardPayment->duplicate_warning) {
            $warnings[] = "⚠️ واریز مشابه در 10 دقیقه اخیر وجود دارد!";
        }

        $warningText = empty($warnings) ? "✅ بدون هشدار" : implode("\n", $warnings);

        $text = "💳 *درخواست تأیید پرداخت کارت به کارت*\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "🔢 شماره سفارش: `{$cardPayment->trade_no}`\n";
        $text .= "👤 کاربر: " . ($user->email ?? 'نامشخص') . "\n";
        $text .= "📱 شناسه کاربر: `{$cardPayment->user_id}`\n\n";
        $text .= "💰 مبلغ سفارش: *{$amountToman} تومان*\n";
        $text .= "🏦 شماره پیگیری: `{$cardPayment->tracking_number}`\n\n";
        $text .= "⏰ زمان سفارش: {$createdAt}\n";
        $text .= "⏰ زمان ادعا: {$claimedAt}\n";
        $text .= "⏳ فاصله: {$diffMinutes} دقیقه و {$diffSecondsRem} ثانیه\n\n";
        $text .= "📋 وضعیت:\n{$warningText}\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '✅ تأیید کامل',
                        'callback_data' => "card_verify_full_{$cardPayment->id}"
                    ]
                ],
                [
                    [
                        'text' => '💰 تأیید با مبلغ متفاوت',
                        'callback_data' => "card_verify_diff_{$cardPayment->id}"
                    ]
                ],
                [
                    [
                        'text' => '❌ رد کردن',
                        'callback_data' => "card_reject_{$cardPayment->id}"
                    ]
                ]
            ]
        ];

        $sentAny = false;
        $firstMsg = null;
        $firstChat = null;
        foreach ($targets as $cid) {
            try {
                $response = Http::post("https://api.telegram.org/bot{$this->telegramToken}/sendMessage", [
                    'chat_id' => $cid,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard)
                ]);
                $result = $response->json();
                if ($response->successful() && isset($result['result']['message_id'])) {
                    if ($firstMsg === null) {
                        $firstMsg = $result['result']['message_id'];
                        $firstChat = $cid;
                    }
                    $sentAny = true;
                } else {
                    Log::channel('payment')->error('Failed to send admin notification', [
                        'payment_id' => $cardPayment->id,
                        'chat_id' => $cid,
                        'response' => $result
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('payment')->error('Telegram API error', [
                    'payment_id' => $cardPayment->id,
                    'chat_id' => $cid,
                    'error' => $e->getMessage()
                ]);
            }
        }
        if ($sentAny) {
            $cardPayment->telegram_message_id = $firstMsg;
            $cardPayment->telegram_chat_id = $firstChat;
            $cardPayment->save();
            Log::channel('payment')->info('Admin notification sent', [
                'payment_id' => $cardPayment->id,
                'message_id' => $firstMsg
            ]);
            return true;
        }
        return false;
    }

    /**
     * تأیید کامل پرداخت
     */
    public function verifyFull(CardPayment $cardPayment, int $adminId): array
    {
        if (!in_array($cardPayment->status, [CardPayment::STATUS_CLAIMED, CardPayment::STATUS_EXPIRED])) {
            return ['success' => false, 'message' => 'وضعیت پرداخت نامعتبر است'];
        }

        DB::beginTransaction();
        try {
            $now = time();

            // بروزرسانی پرداخت
            $cardPayment->status = CardPayment::STATUS_VERIFIED_FULL;
            $cardPayment->actual_amount = $cardPayment->expected_amount;
            $cardPayment->verified_at = $now;
            $cardPayment->verified_by = $adminId;
            $cardPayment->updated_at = $now;
            $cardPayment->save();

            // فعال کردن سفارش
            $order = Order::find($cardPayment->order_id);
            if ($order) {
                $orderService = new OrderService($order);
                $orderService->paid($order->trade_no);
            }

            DB::commit();

            // بروزرسانی پیام تلگرام
            $this->updateTelegramMessage($cardPayment, '✅ تأیید شد', $adminId);

            // اطلاع به کاربر
            $this->notifyUser($cardPayment, 'verified');

            Log::channel('payment')->info('Card payment verified fully', [
                'payment_id' => $cardPayment->id,
                'admin_id' => $adminId
            ]);

            return ['success' => true, 'message' => 'پرداخت تأیید شد'];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::channel('payment')->error('Verify full failed', [
                'payment_id' => $cardPayment->id,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در تأیید: ' . $e->getMessage()];
        }
    }

    /**
     * تأیید با مبلغ متفاوت
     */
    public function verifyWithDifferentAmount(CardPayment $cardPayment, int $actualAmount, int $adminId): array
    {
        if (!in_array($cardPayment->status, [CardPayment::STATUS_CLAIMED, CardPayment::STATUS_EXPIRED])) {
            return ['success' => false, 'message' => 'وضعیت پرداخت نامعتبر است'];
        }

        if ($actualAmount <= 0) {
            return ['success' => false, 'message' => 'مبلغ نامعتبر است'];
        }

        DB::beginTransaction();
        try {
            $now = time();
            $expectedAmount = $cardPayment->expected_amount;
            $user = User::find($cardPayment->user_id);
            $order = Order::find($cardPayment->order_id);

            if ($actualAmount < $expectedAmount) {
                // مبلغ کمتر → به کیف پول اضافه کن، سفارش کنسل
                $cardPayment->status = CardPayment::STATUS_VERIFIED_PARTIAL;
                $cardPayment->wallet_amount = $actualAmount;

                // اضافه کردن به کیف پول
                $userService = new UserService();
                $userService->addBalance($user->id, $actualAmount);

                // کنسل کردن سفارش
                if ($order) {
                    $order->status = 2; // cancelled
                    $order->updated_at = $now;
                    $order->save();
                }

                $resultMessage = "مبلغ {$this->formatAmount($actualAmount)} به کیف پول اضافه شد. سفارش لغو شد.";

            } else {
                // مبلغ بیشتر → سفارش فعال + مابقی به کیف پول
                $cardPayment->status = CardPayment::STATUS_VERIFIED_EXCESS;
                $excessAmount = $actualAmount - $expectedAmount;
                $cardPayment->wallet_amount = $excessAmount;

                // فعال کردن سفارش
                if ($order) {
                    $orderService = new OrderService($order);
                    $orderService->paid($order->trade_no);
                }

                // اضافه کردن مابقی به کیف پول
                if ($excessAmount > 0) {
                    $userService = new UserService();
                    $userService->addBalance($user->id, $excessAmount);
                }

                $resultMessage = "سفارش فعال شد. مبلغ اضافی {$this->formatAmount($excessAmount)} به کیف پول اضافه شد.";
            }

            $cardPayment->actual_amount = $actualAmount;
            $cardPayment->verified_at = $now;
            $cardPayment->verified_by = $adminId;
            $cardPayment->updated_at = $now;
            $cardPayment->save();

            DB::commit();

            // بروزرسانی پیام تلگرام
            $statusText = $actualAmount < $expectedAmount 
                ? "💰 واریز ناقص ({$this->formatAmount($actualAmount)})" 
                : "✅ تأیید + مابقی به کیف پول";
            $this->updateTelegramMessage($cardPayment, $statusText, $adminId);

            // اطلاع به کاربر
            $this->notifyUser($cardPayment, $actualAmount < $expectedAmount ? 'partial' : 'excess');

            Log::channel('payment')->info('Card payment verified with different amount', [
                'payment_id' => $cardPayment->id,
                'expected' => $expectedAmount,
                'actual' => $actualAmount,
                'admin_id' => $adminId
            ]);

            return ['success' => true, 'message' => $resultMessage];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::channel('payment')->error('Verify with different amount failed', [
                'payment_id' => $cardPayment->id,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا: ' . $e->getMessage()];
        }
    }

    /**
     * رد پرداخت
     */
    public function reject(CardPayment $cardPayment, int $adminId, string $reason = ''): array
    {
        if (!in_array($cardPayment->status, [CardPayment::STATUS_CLAIMED, CardPayment::STATUS_EXPIRED])) {
            return ['success' => false, 'message' => 'وضعیت پرداخت نامعتبر است'];
        }

        $now = time();

        $cardPayment->status = CardPayment::STATUS_REJECTED;
        $cardPayment->verified_at = $now;
        $cardPayment->verified_by = $adminId;
        $cardPayment->reject_reason = $reason ?: 'واریز تأیید نشد';
        $cardPayment->updated_at = $now;
        $cardPayment->save();

        // کنسل کردن سفارش
        $order = Order::find($cardPayment->order_id);
        if ($order && $order->status === 0) {
            $order->status = 2;
            $order->updated_at = $now;
            $order->save();
        }

        // بروزرسانی پیام تلگرام
        $this->updateTelegramMessage($cardPayment, '❌ رد شد: ' . ($reason ?: 'بدون دلیل'), $adminId);

        // اطلاع به کاربر
        $this->notifyUser($cardPayment, 'rejected');

        Log::channel('payment')->info('Card payment rejected', [
            'payment_id' => $cardPayment->id,
            'reason' => $reason,
            'admin_id' => $adminId
        ]);

        return ['success' => true, 'message' => 'پرداخت رد شد'];
    }

    /**
     * بروزرسانی پیام تلگرام
     */
    protected function updateTelegramMessage(CardPayment $cardPayment, string $statusText, int $adminId): void
    {
        if (empty($cardPayment->telegram_message_id) || empty($cardPayment->telegram_chat_id)) {
            return;
        }

        $amountToman = number_format($cardPayment->expected_amount);
        
        $text = "💳 *پرداخت کارت به کارت*\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "🔢 سفارش: `{$cardPayment->trade_no}`\n";
        $text .= "💰 مبلغ: {$amountToman} تومان\n";
        $text .= "🏦 پیگیری: `{$cardPayment->tracking_number}`\n\n";
        $text .= "📋 نتیجه: {$statusText}\n";
        $text .= "👤 توسط: ادمین #{$adminId}\n";
        $text .= "⏰ زمان: " . date('Y-m-d H:i:s') . "\n";

        try {
            Http::post("https://api.telegram.org/bot{$this->telegramToken}/editMessageText", [
                'chat_id' => $cardPayment->telegram_chat_id,
                'message_id' => $cardPayment->telegram_message_id,
                'text' => $text,
                'parse_mode' => 'Markdown'
            ]);
        } catch (\Exception $e) {
            Log::channel('payment')->error('Failed to update telegram message', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * اطلاع‌رسانی به کاربر
     */
    protected function notifyUser(CardPayment $cardPayment, string $type): void
    {
        $user = User::find($cardPayment->user_id);
        if (!$user || empty($user->telegram_id)) {
            return;
        }

        $amountToman = number_format($cardPayment->expected_amount);

        switch ($type) {
            case 'verified':
                $text = "✅ پرداخت شما تأیید شد!\n\n";
                $text .= "💰 مبلغ: {$amountToman} تومان\n";
                $text .= "🔢 سفارش: {$cardPayment->trade_no}\n\n";
                $text .= "سفارش شما فعال شد. 🎉";
                break;

            case 'partial':
                $actualToman = number_format($cardPayment->actual_amount);
                $text = "💰 واریز شما ثبت شد\n\n";
                $text .= "مبلغ واریزی ({$actualToman} تومان) کمتر از مبلغ سفارش ({$amountToman} تومان) بود.\n";
                $text .= "مبلغ واریزی به کیف پول شما اضافه شد.\n";
                $text .= "می‌توانید سفارش جدید ثبت کنید.";
                break;

            case 'excess':
                $excessToman = number_format($cardPayment->wallet_amount);
                $text = "✅ پرداخت شما تأیید شد!\n\n";
                $text .= "سفارش فعال شد.\n";
                $text .= "مبلغ اضافی ({$excessToman} تومان) به کیف پول شما اضافه شد. 🎉";
                break;

            case 'rejected':
                $text = "❌ پرداخت تأیید نشد\n\n";
                $text .= "🔢 سفارش: {$cardPayment->trade_no}\n";
                $text .= "📋 دلیل: {$cardPayment->reject_reason}\n\n";
                $text .= "در صورت نیاز با پشتیبانی تماس بگیرید.";
                break;

            default:
                return;
        }

        try {
            Http::post("https://api.telegram.org/bot{$this->telegramToken}/sendMessage", [
                'chat_id' => $user->telegram_id,
                'text' => $text,
                'parse_mode' => 'Markdown'
            ]);
        } catch (\Exception $e) {
            Log::channel('payment')->error('Failed to notify user', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * فرمت کردن مبلغ
     */
    protected function formatAmount(int $amount): string
    {
        return number_format($amount) . ' تومان';
    }
}
