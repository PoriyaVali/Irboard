<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\PaymentTrack;

class ZarinpalPayment
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'zarinpal_merchant' => [
                'label' => 'کد مرچنت زرین‌پال',
                'description' => 'کد مرچنت 36 کاراکتری دریافتی از زرین‌پال',
                'type' => 'input',
            ],
            'zarinpal_sandbox' => [
                'label' => 'حالت تست (Sandbox)',
                'description' => '1 = فعال | 0 = غیرفعال',
                'type' => 'input',
            ],
            'zarinpal_callback' => [
                'label' => 'آدرس بازگشت (Callback URL)',
                'description' => 'خالی = پیش‌فرض | یا آدرس کامل مثل: https://example.com/pay/{trade_no}',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (!isset($this->config['zarinpal_merchant'])) {
            Log::channel('payment')->error('Zarinpal config is missing required keys');
            throw new \Exception('تنظیمات زرین‌پال ناقص است.');
        }

        $sandbox = isset($this->config['zarinpal_sandbox']) && $this->config['zarinpal_sandbox'] == '1';
        $baseUrl = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://api.zarinpal.com';

        $params = [
            'merchant_id' => $this->config['zarinpal_merchant'],
            'amount' => $order['total_amount'],
            'callback_url' => $this->getCallbackUrl($order),
            'description' => 'پرداخت سفارش ' . $order['trade_no'],
            'metadata' => [
                'order_id' => $order['trade_no'],
            ],
        ];

        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($baseUrl . '/pg/v4/payment/request.json', $params);

            $result = $response->json();

            Log::channel('payment')->info('Zarinpal payment request:', $this->filterLogData($params));
            Log::channel('payment')->info('Zarinpal payment response:', $this->filterLogData($result));

            if ($response->successful() && isset($result['data']['code']) && $result['data']['code'] == 100) {
                $authority = $result['data']['authority'];

                // Store authority in payment_tracks table
                $trackSaved = false;

                try {
                    PaymentTrack::store(
                        $authority,
                        $order['id'] ?? 0,
                        $order['user_id'] ?? 0,
                        $order['total_amount'] ?? 0,
                        'zarinpal',
                        $order['trade_no'] ?? null
                    );

                    $trackSaved = true;

                    Log::channel('payment')->info('✓ Authority stored successfully', [
                        'authority' => $authority,
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'user_id' => $order['user_id'] ?? 0,
                        'amount' => $order['total_amount'] ?? 0
                    ]);

                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store authority in database', [
                        'authority' => $authority,
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'error' => $e->getMessage(),
                    ]);
                }

                // Store in cache as backup (3 days TTL)
                try {
                    cache()->put("zarinpal_auth_{$order['trade_no']}", $authority, 259200);

                    if (!$trackSaved) {
                        Log::channel('payment')->warning('Authority saved in cache only (DB failed)', [
                            'authority' => $authority,
                            'trade_no' => $order['trade_no']
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store authority in cache', [
                        'authority' => $authority,
                        'error' => $e->getMessage()
                    ]);
                }

                $gatewayUrl = $sandbox 
                    ? 'https://sandbox.zarinpal.com/pg/StartPay/' 
                    : 'https://www.zarinpal.com/pg/StartPay/';

                return [
                    'type' => 1,
                    'data' => $gatewayUrl . $authority,
                ];
            }

            Log::channel('payment')->error('Zarinpal payment request failed', $result);
            $errorMessage = $result['errors']['message'] ?? $result['data']['message'] ?? 'خطای نامشخص از زرین‌پال';
            throw new \Exception($errorMessage);

        } catch (\Exception $e) {
            Log::channel('payment')->error('Zarinpal payment exception', [
                'error' => $e->getMessage(),
                'order' => $order['trade_no'] ?? 'unknown'
            ]);
            return false;
        }
    }

    public function notify($params)
    {
        Log::channel('payment')->info('Zarinpal notify received', $this->filterLogData($params));

        // Check required parameters
        if (!isset($params['Authority']) || !isset($params['Status'])) {
            Log::channel('payment')->error('Missing required parameters');
            return false;
        }

        $authority = $params['Authority'];
        $status = $params['Status'];

        // Get trade_no from URL or find by authority
        $tradeNo = $params['trade_no'] ?? null;

        if (!$tradeNo) {
            // Try to find from payment_tracks
            $track = PaymentTrack::getByTrackId($authority);
            if ($track) {
                $tradeNo = $track->trade_no;
            }
        }

        if (!$tradeNo) {
            Log::channel('payment')->error('Cannot determine trade_no', ['authority' => $authority]);
            return false;
        }

        // Check if already processed
        $processKey = "processed_{$tradeNo}_{$authority}";
        if (cache()->has($processKey)) {
            Log::channel('payment')->info('Payment already processed', ['key' => $processKey]);
            return cache()->get("payment_result_{$tradeNo}") ?: false;
        }

        // Check payment status
        if ($status !== 'OK') {
            Log::channel('payment')->error('Transaction failed', ['status' => $status]);
            return false;
        }

        // Validate authority with payment_tracks table
        if (!PaymentTrack::isValid($authority)) {
            $track = PaymentTrack::getByTrackId($authority);

            if ($track) {
                if ($track->is_used) {
                    Log::channel('payment')->error('Authority already used', [
                        'authority' => $authority,
                        'trade_no' => $tradeNo,
                        'used_at' => $track->used_at ? $track->used_at->format('Y-m-d H:i:s') : null
                    ]);
                    return false;
                }
            } else {
                Log::channel('payment')->error('Authority not found in database', [
                    'authority' => $authority,
                    'trade_no' => $tradeNo
                ]);

                // Check cache as fallback
                $cachedAuthority = cache()->get("zarinpal_auth_{$tradeNo}");
                if (!$cachedAuthority || $cachedAuthority !== $authority) {
                    return false;
                }

                Log::channel('payment')->warning('Authority found in cache but not in DB, continuing', [
                    'authority' => $authority
                ]);
            }
        }

        // Get order information
        $order = cache()->remember("order_{$tradeNo}", 60, function() use ($tradeNo) {
            return Order::where('trade_no', $tradeNo)->first();
        });

        if (!$order) {
            Log::channel('payment')->error('Order not found', ['trade_no' => $tradeNo]);
            return false;
        }

        $sandbox = isset($this->config['zarinpal_sandbox']) && $this->config['zarinpal_sandbox'] == '1';
        $baseUrl = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://api.zarinpal.com';

        // Verify with Zarinpal gateway
        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($baseUrl . '/pg/v4/payment/verify.json', [
                    'merchant_id' => $this->config['zarinpal_merchant'],
                    'amount' => $order->total_amount,
                    'authority' => $authority
                ]);

            $result = $response->json();
            Log::channel('payment')->info('Zarinpal verify response', $this->filterLogData($result));

            if (!$response->successful() || !isset($result['data']['code']) || !in_array($result['data']['code'], [100, 101])) {
                Log::channel('payment')->error('Verify failed', [
                    'code' => $result['data']['code'] ?? null,
                    'message' => $result['data']['message'] ?? null
                ]);
                return false;
            }

            $cardNumber = isset($result['data']['card_pan']) ? $this->maskCardNumber($result['data']['card_pan']) : 'N/A';

            $successResult = [
                'trade_no' => $tradeNo,
                'callback_no' => $result['data']['ref_id'] ?? $authority,
                'amount' => $order->total_amount,
                'card_number' => $cardNumber
            ];

            // Mark authority as used
            $track = PaymentTrack::getByTrackId($authority);
            if ($track && !$track->is_used) {
                $track->markAsUsed();
                Log::channel('payment')->info('✓ Authority marked as used');
            }

            // Cache the result
            cache()->put($processKey, true, 86400);
            cache()->put("payment_result_{$tradeNo}", $successResult, 86400);
            cache()->forget("order_{$tradeNo}");
            cache()->forget("zarinpal_auth_{$tradeNo}");

            Log::channel('payment')->info('Payment completed successfully', [
                'authority' => $authority,
                'order_id' => $order->id,
                'trade_no' => $tradeNo,
                'amount' => $order->total_amount
            ]);

            return $successResult;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Verify exception', [
                'authority' => $authority,
                'trade_no' => $tradeNo,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function getCallbackUrl($order)
    {
        // If custom callback is set, use it with {trade_no} replacement
        if (!empty($this->config['zarinpal_callback'])) {
            return str_replace('{trade_no}', $order['trade_no'], $this->config['zarinpal_callback']);
        }
        
        // Default: use system notify_url
        return $order['notify_url'];
    }

    private function filterLogData($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $filtered = $data;
        $sensitiveFields = ['merchant_id', 'zarinpal_merchant', 'card_pan', 'token'];

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
