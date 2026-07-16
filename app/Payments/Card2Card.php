<?php

namespace App\Payments;

use App\Models\CardPayment;
use App\Models\CardPaymentConfig;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class Card2Card
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * فرم تنظیمات در پنل ادمین
     */
    public function form()
    {
        return [
            'card_number' => [
                'label' => 'شماره کارت',
                'description' => 'شماره کارت 16 رقمی برای واریز',
                'type' => 'input',
            ],
            'card_holder' => [
                'label' => 'نام صاحب کارت',
                'description' => 'نام و نام خانوادگی صاحب کارت',
                'type' => 'input',
            ],
            'bank_name' => [
                'label' => 'نام بانک',
                'description' => 'مثال: ملت، سامان، پارسیان',
                'type' => 'input',
            ],
            'expire_minutes' => [
                'label' => 'مهلت پرداخت (دقیقه)',
                'description' => 'پیش‌فرض: 30 دقیقه',
                'type' => 'input',
            ],
            'min_amount' => [
                'label' => 'حداقل مبلغ (تومان)',
                'description' => 'پیش‌فرض: 50,000 تومان',
                'type' => 'input',
            ],
            'max_amount' => [
                'label' => 'حداکثر مبلغ (تومان)',
                'description' => 'پیش‌فرض: 50,000,000 تومان',
                'type' => 'input',
            ],
            'telegram_admin_id' => [
                'label' => 'شناسه تلگرام ادمین',
                'description' => 'برای دریافت اعلان تأیید پرداخت',
                'type' => 'input',
            ],
        ];
    }

    /**
     * شروع پرداخت
     */
    public function pay($order)
    {
        Log::channel('payment')->info('CardToCard payment initiated', [
            'trade_no' => $order['trade_no'],
            'amount' => $order['total_amount'],
            'user_id' => $order['user_id']
        ]);

        // بررسی تنظیمات
        if (empty($this->config['card_number']) || empty($this->config['card_holder'])) {
            Log::channel('payment')->error('CardToCard config incomplete');
            throw new \Exception('تنظیمات کارت به کارت ناقص است');
        }

        // بررسی محدودیت مبلغ
        $minAmount = ($this->config['min_amount'] ?? 50000) * 10; // تبدیل به ریال
        $maxAmount = ($this->config['max_amount'] ?? 50000000) * 10;
        
        if ($order['total_amount'] < $minAmount) {
            throw new \Exception('مبلغ سفارش کمتر از حداقل مجاز است');
        }
        
        if ($order['total_amount'] > $maxAmount) {
            throw new \Exception('مبلغ سفارش بیشتر از حداکثر مجاز است');
        }

        $expireMinutes = $this->config['expire_minutes'] ?? 30;
        $now = time();

        try {
            // حذف رکورد قبلی در صورت وجود
            $orderModel = \App\Models\Order::where('trade_no', $order['trade_no'])->first();
            CardPayment::where('order_id', $orderModel->id)->whereIn('status', ['pending', 'expired'])->delete();

            // ایجاد رکورد پرداخت
            $cardPayment = new CardPayment();
            $cardPayment->order_id = $orderModel->id;
            $cardPayment->trade_no = $order['trade_no'];
            $cardPayment->user_id = $order['user_id'];
            $cardPayment->expected_amount = $order['total_amount'];
            $cardPayment->card_number = $this->config['card_number'];
            $cardPayment->card_holder = $this->config['card_holder'];
            $cardPayment->status = CardPayment::STATUS_PENDING;
            $cardPayment->amount_fingerprint = CardPayment::generateAmountFingerprint($order['total_amount']);
            $cardPayment->created_at = $now;
            $cardPayment->expires_at = $now + ($expireMinutes * 60);
            $cardPayment->updated_at = $now;
            $cardPayment->save();

            Log::channel('payment')->info('CardToCard payment record created', [
                'payment_id' => $cardPayment->id,
                'trade_no' => $order['trade_no'],
                'expires_at' => date('Y-m-d H:i:s', $cardPayment->expires_at)
            ]);

            // برگرداندن URL صفحه پرداخت کارت به کارت
            // type=0 یعنی redirect نکن، فقط data رو برگردون
            // فرانت‌اند خودش صفحه کارت به کارت رو نشون میده
            return [
                'type' => 0, // 0 = no redirect, return data
                'data' => [
                    'payment_id' => $cardPayment->id,
                    'card_number' => $this->formatCardNumber($this->config['card_number']),
                    'card_holder' => $this->config['card_holder'],
                    'bank_name' => $this->config['bank_name'] ?? '',
                    'amount' => $order['total_amount'],
                    'amount_toman' => $order['total_amount'],
                    'expires_at' => $cardPayment->expires_at,
                    'remaining_seconds' => $expireMinutes * 60,
                    'trade_no' => $order['trade_no']
                ]
            ];

        } catch (\Exception $e) {
            Log::channel('payment')->error('CardToCard payment creation failed', [
                'trade_no' => $order['trade_no'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * پردازش callback (در این روش استفاده نمی‌شود)
     */
    public function notify($params)
    {
        // کارت به کارت callback خودکار ندارد
        // تأیید از طریق ادمین انجام می‌شود
        return false;
    }

    /**
     * فرمت کردن شماره کارت
     */
    private function formatCardNumber(string $cardNumber): string
    {
        $clean = preg_replace('/\D/', '', $cardNumber);
        return implode('-', str_split($clean, 4));
    }
}
