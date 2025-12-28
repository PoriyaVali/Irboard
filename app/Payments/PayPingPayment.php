<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\PaymentTrack;

class PayPingPayment
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'payping_token' => [
                'label' => 'توکن API پی‌پینگ',
                'description' => 'توکن دریافتی از پنل پی‌پینگ',
                'type' => 'input',
            ],
            'payping_callback' => [
                'label' => 'آدرس بازگشت (Callback URL)',
                'description' => 'خالی = پیش‌فرض | یا آدرس کامل مثل: https://example.com/pay/{trade_no}',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (!isset($this->config['payping_token'])) {
            Log::channel('payment')->error('PayPing config is missing required keys');
            throw new \Exception('تنظیمات پی‌پینگ ناقص است.');
        }

        $params = [
            'amount' => $order['total_amount'],
            'returnUrl' => $this->getCallbackUrl($order),
            'clientRefId' => $order['trade_no'],
            'description' => 'پرداخت سفارش ' . $order['trade_no'],
        ];

        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->config['payping_token'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://api.payping.ir/v2/pay', $params);

            $result = $response->json();

            Log::channel('payment')->info('PayPing payment request:', $this->filterLogData($params));
            Log::channel('payment')->info('PayPing payment response:', $this->filterLogData($result));

            if ($response->successful() && isset($result['code'])) {
                $code = $result['code'];

                // Store code in payment_tracks table
                $trackSaved = false;

                try {
                    PaymentTrack::store(
                        $code,
                        $order['id'] ?? 0,
                        $order['user_id'] ?? 0,
                        $order['total_amount'] ?? 0,
                        'payping',
                        $order['trade_no'] ?? null
                    );

                    $trackSaved = true;

                    Log::channel('payment')->info('✓ Code stored successfully', [
                        'code' => $code,
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'user_id' => $order['user_id'] ?? 0,
                        'amount' => $order['total_amount'] ?? 0
                    ]);

                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store code in database', [
                        'code' => $code,
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'error' => $e->getMessage(),
                    ]);
                }

                // Store in cache as backup (3 days TTL)
                try {
                    cache()->put("payping_code_{$order['trade_no']}", $code, 259200);

                    if (!$trackSaved) {
                        Log::channel('payment')->warning('Code saved in cache only (DB failed)', [
                            'code' => $code,
                            'trade_no' => $order['trade_no']
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store code in cache', [
                        'code' => $code,
                        'error' => $e->getMessage()
                    ]);
                }

                return [
                    'type' => 1,
                    'data' => 'https://api.payping.ir/v2/pay/gotoipg/' . $code,
                ];
            }

            Log::channel('payment')->error('PayPing payment request failed', $result);
            throw new \Exception($result['Error'] ?? 'خطای نامشخص از پی‌پینگ');

        } catch (\Exception $e) {
            Log::channel('payment')->error('PayPing payment exception', [
                'error' => $e->getMessage(),
                'order' => $order['trade_no'] ?? 'unknown'
            ]);
            return false;
        }
    }

    public function notify($params)
    {
        Log::channel('payment')->info('PayPing notify received', $this->filterLogData($params));

        // Check required parameters
        if (!isset($params['refid']) || !isset($params['clientrefid'])) {
            Log::channel('payment')->error('Missing required parameters');
            return false;
        }

        $refId = $params['refid'];
        $clientRefId = $params['clientrefid'];
        $code = $params['code'] ?? null;

        // Check if already processed
        $processKey = "processed_{$clientRefId}_{$refId}";
        if (cache()->has($processKey)) {
            Log::channel('payment')->info('Payment already processed', ['key' => $processKey]);
            return cache()->get("payment_result_{$clientRefId}") ?: false;
        }

        // Validate code with payment_tracks table
        if ($code && !PaymentTrack::isValid($code)) {
            $track = PaymentTrack::getByTrackId($code);

            if ($track) {
                if ($track->is_used) {
                    Log::channel('payment')->error('Code already used', [
                        'code' => $code,
                        'client_ref_id' => $clientRefId,
                        'used_at' => $track->used_at ? $track->used_at->format('Y-m-d H:i:s') : null
                    ]);
                    return false;
                }
            } else {
                Log::channel('payment')->error('Code not found in database', [
                    'code' => $code,
                    'client_ref_id' => $clientRefId
                ]);

                // Check cache as fallback
                $cachedCode = cache()->get("payping_code_{$clientRefId}");
                if (!$cachedCode || $cachedCode !== $code) {
                    return false;
                }

                Log::channel('payment')->warning('Code found in cache but not in DB, continuing', [
                    'code' => $code
                ]);
            }
        }

        // Get order information
        $order = cache()->remember("order_{$clientRefId}", 60, function() use ($clientRefId) {
            return Order::where('trade_no', $clientRefId)->first();
        });

        if (!$order) {
            Log::channel('payment')->error('Order not found', ['client_ref_id' => $clientRefId]);
            return false;
        }

        // Verify with PayPing gateway
        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->config['payping_token'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://api.payping.ir/v2/pay/verify', [
                    'refId' => $refId,
                    'amount' => $order->total_amount
                ]);

            $result = $response->json();
            Log::channel('payment')->info('PayPing verify response', $this->filterLogData($result));

            if (!$response->successful()) {
                Log::channel('payment')->error('Verify failed', [
                    'status_code' => $response->status(),
                    'result' => $result
                ]);
                return false;
            }

            $cardNumber = isset($params['cardnumber']) ? $this->maskCardNumber($params['cardnumber']) : 'N/A';

            $successResult = [
                'trade_no' => $clientRefId,
                'callback_no' => $refId,
                'amount' => $order->total_amount,
                'card_number' => $cardNumber
            ];

            // Mark code as used
            if ($code) {
                $track = PaymentTrack::getByTrackId($code);
                if ($track && !$track->is_used) {
                    $track->markAsUsed();
                    Log::channel('payment')->info('✓ Code marked as used');
                }
            }

            // Cache the result
            cache()->put($processKey, true, 86400);
            cache()->put("payment_result_{$clientRefId}", $successResult, 86400);
            cache()->forget("order_{$clientRefId}");
            cache()->forget("payping_code_{$clientRefId}");

            Log::channel('payment')->info('Payment completed successfully', [
                'ref_id' => $refId,
                'order_id' => $order->id,
                'trade_no' => $clientRefId,
                'amount' => $order->total_amount
            ]);

            return $successResult;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Verify exception', [
                'ref_id' => $refId,
                'client_ref_id' => $clientRefId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function getCallbackUrl($order)
    {
        // If custom callback is set, use it with {trade_no} replacement
        if (!empty($this->config['payping_callback'])) {
            return str_replace('{trade_no}', $order['trade_no'], $this->config['payping_callback']);
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
        $sensitiveFields = ['payping_token', 'Authorization', 'cardnumber', 'token'];

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
