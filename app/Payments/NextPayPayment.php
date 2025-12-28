<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\PaymentTrack;

class NextPayPayment
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'nextpay_api_key' => [
                'label' => 'کلید API نکست‌پی',
                'description' => 'کلید API دریافتی از پنل نکست‌پی',
                'type' => 'input',
            ],
            'nextpay_callback' => [
                'label' => 'آدرس بازگشت (Callback URL)',
                'description' => 'خالی = پیش‌فرض | یا آدرس کامل مثل: https://example.com/pay/{trade_no}',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (!isset($this->config['nextpay_api_key'])) {
            Log::channel('payment')->error('NextPay config is missing required keys');
            throw new \Exception('تنظیمات نکست‌پی ناقص است.');
        }

        $params = [
            'api_key' => $this->config['nextpay_api_key'],
            'order_id' => $order['trade_no'],
            'amount' => $order['total_amount'],
            'callback_uri' => $this->getCallbackUrl($order),
        ];

        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->asForm()
                ->post('https://nextpay.org/nx/gateway/token', $params);

            $result = $response->json();

            Log::channel('payment')->info('NextPay payment request:', $this->filterLogData($params));
            Log::channel('payment')->info('NextPay payment response:', $this->filterLogData($result));

            if ($response->successful() && isset($result['code']) && $result['code'] == -1) {
                $transId = $result['trans_id'];

                // Store transId in payment_tracks table
                $trackSaved = false;

                try {
                    PaymentTrack::store(
                        $transId,
                        $order['id'] ?? 0,
                        $order['user_id'] ?? 0,
                        $order['total_amount'] ?? 0,
                        'nextpay',
                        $order['trade_no'] ?? null
                    );

                    $trackSaved = true;

                    Log::channel('payment')->info('✓ TransId stored successfully', [
                        'trans_id' => $transId,
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'user_id' => $order['user_id'] ?? 0,
                        'amount' => $order['total_amount'] ?? 0
                    ]);

                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store transId in database', [
                        'trans_id' => $transId,
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'error' => $e->getMessage(),
                    ]);
                }

                // Store in cache as backup (3 days TTL)
                try {
                    cache()->put("nextpay_trans_{$order['trade_no']}", $transId, 259200);

                    if (!$trackSaved) {
                        Log::channel('payment')->warning('TransId saved in cache only (DB failed)', [
                            'trans_id' => $transId,
                            'trade_no' => $order['trade_no']
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store transId in cache', [
                        'trans_id' => $transId,
                        'error' => $e->getMessage()
                    ]);
                }

                return [
                    'type' => 1,
                    'data' => 'https://nextpay.org/nx/gateway/payment/' . $transId,
                ];
            }

            Log::channel('payment')->error('NextPay payment request failed', $result);
            throw new \Exception($this->getErrorMessage($result['code'] ?? 0));

        } catch (\Exception $e) {
            Log::channel('payment')->error('NextPay payment exception', [
                'error' => $e->getMessage(),
                'order' => $order['trade_no'] ?? 'unknown'
            ]);
            return false;
        }
    }

    public function notify($params)
    {
        Log::channel('payment')->info('NextPay notify received', $this->filterLogData($params));

        // Check required parameters
        if (!isset($params['trans_id']) || !isset($params['order_id'])) {
            Log::channel('payment')->error('Missing required parameters');
            return false;
        }

        $transId = $params['trans_id'];
        $orderId = $params['order_id'];

        // Check if already processed
        $processKey = "processed_{$orderId}_{$transId}";
        if (cache()->has($processKey)) {
            Log::channel('payment')->info('Payment already processed', ['key' => $processKey]);
            return cache()->get("payment_result_{$orderId}") ?: false;
        }

        // Validate transId with payment_tracks table
        if (!PaymentTrack::isValid($transId)) {
            $track = PaymentTrack::getByTrackId($transId);

            if ($track) {
                if ($track->is_used) {
                    Log::channel('payment')->error('TransId already used', [
                        'trans_id' => $transId,
                        'order_id' => $orderId,
                        'used_at' => $track->used_at ? $track->used_at->format('Y-m-d H:i:s') : null
                    ]);
                    return false;
                }
            } else {
                Log::channel('payment')->error('TransId not found in database', [
                    'trans_id' => $transId,
                    'order_id' => $orderId
                ]);

                // Check cache as fallback
                $cachedTransId = cache()->get("nextpay_trans_{$orderId}");
                if (!$cachedTransId || $cachedTransId !== $transId) {
                    return false;
                }

                Log::channel('payment')->warning('TransId found in cache but not in DB, continuing', [
                    'trans_id' => $transId
                ]);
            }
        }

        // Get order information
        $order = cache()->remember("order_{$orderId}", 60, function() use ($orderId) {
            return Order::where('trade_no', $orderId)->first();
        });

        if (!$order) {
            Log::channel('payment')->error('Order not found', ['order_id' => $orderId]);
            return false;
        }

        // Verify with NextPay gateway
        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->asForm()
                ->post('https://nextpay.org/nx/gateway/verify', [
                    'api_key' => $this->config['nextpay_api_key'],
                    'trans_id' => $transId,
                    'amount' => $order->total_amount
                ]);

            $result = $response->json();
            Log::channel('payment')->info('NextPay verify response', $this->filterLogData($result));

            if (!$response->successful() || !isset($result['code']) || $result['code'] != 0) {
                Log::channel('payment')->error('Verify failed', [
                    'code' => $result['code'] ?? null,
                    'message' => $this->getErrorMessage($result['code'] ?? 0)
                ]);
                return false;
            }

            $cardNumber = isset($result['card_holder']) ? $this->maskCardNumber($result['card_holder']) : 'N/A';

            $successResult = [
                'trade_no' => $orderId,
                'callback_no' => $result['Shaparak_Ref_Id'] ?? $transId,
                'amount' => $order->total_amount,
                'card_number' => $cardNumber
            ];

            // Mark transId as used
            $track = PaymentTrack::getByTrackId($transId);
            if ($track && !$track->is_used) {
                $track->markAsUsed();
                Log::channel('payment')->info('✓ TransId marked as used');
            }

            // Cache the result
            cache()->put($processKey, true, 86400);
            cache()->put("payment_result_{$orderId}", $successResult, 86400);
            cache()->forget("order_{$orderId}");
            cache()->forget("nextpay_trans_{$orderId}");

            Log::channel('payment')->info('Payment completed successfully', [
                'trans_id' => $transId,
                'order_id' => $order->id,
                'trade_no' => $orderId,
                'amount' => $order->total_amount
            ]);

            return $successResult;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Verify exception', [
                'trans_id' => $transId,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function getCallbackUrl($order)
    {
        // If custom callback is set, use it with {trade_no} replacement
        if (!empty($this->config['nextpay_callback'])) {
            return str_replace('{trade_no}', $order['trade_no'], $this->config['nextpay_callback']);
        }
        
        // Default: use system notify_url
        return $order['notify_url'];
    }

    private function getErrorMessage($code)
    {
        $errors = [
            0 => 'پرداخت موفق',
            -1 => 'در انتظار پرداخت',
            -2 => 'خطای سیستمی',
            -3 => 'کلید API نامعتبر',
            -4 => 'IP نامعتبر',
            -5 => 'سایت غیرفعال',
            -6 => 'مبلغ نامعتبر',
            -7 => 'آدرس بازگشت نامعتبر',
            -8 => 'سطح دسترسی کافی نیست',
            -9 => 'تراکنش قبلا تایید شده',
            -10 => 'مبلغ تراکنش با مبلغ ارسالی مطابقت ندارد',
            -11 => 'آدرس IP تغییر کرده',
            -12 => 'توکن نامعتبر',
            -13 => 'تراکنش یافت نشد',
            -14 => 'زمان انجام تراکنش منقضی شده',
            -15 => 'تراکنش لغو شده',
            -16 => 'پرداخت انجام نشده',
            -17 => 'امکان ثبت مجدد درخواست نیست',
            -18 => 'IP قابل قبول نیست',
            -19 => 'مقدار ارسالی یافت نشد',
        ];

        return $errors[$code] ?? 'خطای نامشخص از نکست‌پی';
    }

    private function filterLogData($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $filtered = $data;
        $sensitiveFields = ['api_key', 'nextpay_api_key', 'card_holder', 'token'];

        foreach ($sensitiveFields as $field) {
            if (isset($filtered[$field])) {
                $filtered[$field] = '***' . substr($filtered[$field], -4);
            }
        }

        array_walk_recursive($filtered, function (&$value, $key) use ($sensitiveFields) {
            if (in_array($key, $sensitiveFields) && is_string($value)) {
                $value = '***' . substr($value, -4);
            }
        });

        return $filtered;
    }

    private function maskCardNumber($cardNumber)
    {
        if (empty($cardNumber) || !is_string($cardNumber)) {
            return 'N/A';
        }

        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        if (strlen($cardNumber) < 10) {
            return 'N/A';
        }

        return substr($cardNumber, 0, 6) . str_repeat('*', max(6, strlen($cardNumber) - 10)) . substr($cardNumber, -4);
    }
}
