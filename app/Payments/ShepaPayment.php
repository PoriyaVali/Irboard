<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\PaymentTrack;

class ShepaPayment
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'shepa_api_key' => [
                'label' => 'کلید API شپا',
                'description' => 'کلید API دریافتی از پنل شپا',
                'type' => 'input',
            ],
            'shepa_sandbox' => [
                'label' => 'حالت تست (Sandbox)',
                'description' => '1 = فعال | 0 = غیرفعال',
                'type' => 'input',
            ],
            'shepa_callback' => [
                'label' => 'آدرس بازگشت (Callback URL)',
                'description' => 'خالی = پیش‌فرض | یا آدرس کامل مثل: https://example.com/pay/{trade_no}',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (!isset($this->config['shepa_api_key'])) {
            Log::channel('payment')->error('Shepa config is missing required keys');
            throw new \Exception('تنظیمات شپا ناقص است.');
        }

        $sandbox = isset($this->config['shepa_sandbox']) && $this->config['shepa_sandbox'] == '1';
        $baseUrl = $sandbox ? 'https://sandbox.shepa.com' : 'https://merchant.shepa.com';

        $params = [
            'api' => $this->config['shepa_api_key'],
            'amount' => $order['total_amount'],
            'callback' => $this->getCallbackUrl($order),
            'description' => 'پرداخت سفارش ' . $order['trade_no'],
            'factorNumber' => $order['trade_no'],
        ];

        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($baseUrl . '/api/v1/token', $params);

            $result = $response->json();

            Log::channel('payment')->info('Shepa payment request:', $this->filterLogData($params));
            Log::channel('payment')->info('Shepa payment response:', $this->filterLogData($result));

            if ($response->successful() && isset($result['success']) && $result['success'] == true) {
                $token = $result['result']['token'];

                // Store token in payment_tracks table
                $trackSaved = false;

                try {
                    PaymentTrack::store(
                        $token,
                        $order['id'] ?? 0,
                        $order['user_id'] ?? 0,
                        $order['total_amount'] ?? 0,
                        'shepa',
                        $order['trade_no'] ?? null
                    );

                    $trackSaved = true;

                    Log::channel('payment')->info('✓ Token stored successfully', [
                        'token' => substr($token, 0, 10) . '...',
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'user_id' => $order['user_id'] ?? 0,
                        'amount' => $order['total_amount'] ?? 0
                    ]);

                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store token in database', [
                        'token' => substr($token, 0, 10) . '...',
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'error' => $e->getMessage(),
                    ]);
                }

                // Store in cache as backup (3 days TTL)
                try {
                    cache()->put("shepa_token_{$order['trade_no']}", $token, 259200);

                    if (!$trackSaved) {
                        Log::channel('payment')->warning('Token saved in cache only (DB failed)', [
                            'token' => substr($token, 0, 10) . '...',
                            'trade_no' => $order['trade_no']
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store token in cache', [
                        'token' => substr($token, 0, 10) . '...',
                        'error' => $e->getMessage()
                    ]);
                }

                return [
                    'type' => 1,
                    'data' => $result['result']['url'],
                ];
            }

            Log::channel('payment')->error('Shepa payment request failed', $result);
            throw new \Exception($result['error'] ?? 'خطای نامشخص از شپا');

        } catch (\Exception $e) {
            Log::channel('payment')->error('Shepa payment exception', [
                'error' => $e->getMessage(),
                'order' => $order['trade_no'] ?? 'unknown'
            ]);
            return false;
        }
    }

    public function notify($params)
    {
        Log::channel('payment')->info('Shepa notify received', $this->filterLogData($params));

        // Check required parameters
        if (!isset($params['token']) || !isset($params['status'])) {
            Log::channel('payment')->error('Missing required parameters');
            return false;
        }

        $token = $params['token'];
        $status = $params['status'];
        $factorNumber = $params['factorNumber'] ?? null;

        // Try to get trade_no
        if (!$factorNumber) {
            $track = PaymentTrack::getByTrackId($token);
            if ($track) {
                $factorNumber = $track->trade_no;
            }
        }

        if (!$factorNumber) {
            Log::channel('payment')->error('Cannot determine trade_no', ['token' => substr($token, 0, 10) . '...']);
            return false;
        }

        // Check if already processed
        $processKey = "processed_{$factorNumber}_{$token}";
        if (cache()->has($processKey)) {
            Log::channel('payment')->info('Payment already processed', ['key' => $processKey]);
            return cache()->get("payment_result_{$factorNumber}") ?: false;
        }

        // Check payment status
        if ($status !== 'success') {
            Log::channel('payment')->error('Transaction failed', ['status' => $status]);
            return false;
        }

        // Validate token with payment_tracks table
        if (!PaymentTrack::isValid($token)) {
            $track = PaymentTrack::getByTrackId($token);

            if ($track) {
                if ($track->is_used) {
                    Log::channel('payment')->error('Token already used', [
                        'token' => substr($token, 0, 10) . '...',
                        'factor_number' => $factorNumber,
                        'used_at' => $track->used_at ? $track->used_at->format('Y-m-d H:i:s') : null
                    ]);
                    return false;
                }
            } else {
                Log::channel('payment')->error('Token not found in database', [
                    'token' => substr($token, 0, 10) . '...',
                    'factor_number' => $factorNumber
                ]);

                // Check cache as fallback
                $cachedToken = cache()->get("shepa_token_{$factorNumber}");
                if (!$cachedToken || $cachedToken !== $token) {
                    return false;
                }

                Log::channel('payment')->warning('Token found in cache but not in DB, continuing', [
                    'token' => substr($token, 0, 10) . '...'
                ]);
            }
        }

        // Get order information
        $order = cache()->remember("order_{$factorNumber}", 60, function() use ($factorNumber) {
            return Order::where('trade_no', $factorNumber)->first();
        });

        if (!$order) {
            Log::channel('payment')->error('Order not found', ['factor_number' => $factorNumber]);
            return false;
        }

        $sandbox = isset($this->config['shepa_sandbox']) && $this->config['shepa_sandbox'] == '1';
        $baseUrl = $sandbox ? 'https://sandbox.shepa.com' : 'https://merchant.shepa.com';

        // Verify with Shepa gateway
        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($baseUrl . '/api/v1/verify', [
                    'api' => $this->config['shepa_api_key'],
                    'token' => $token,
                    'amount' => $order->total_amount
                ]);

            $result = $response->json();
            Log::channel('payment')->info('Shepa verify response', $this->filterLogData($result));

            if (!$response->successful() || !isset($result['success']) || $result['success'] != true) {
                Log::channel('payment')->error('Verify failed', [
                    'success' => $result['success'] ?? null,
                    'error' => $result['error'] ?? null
                ]);
                return false;
            }

            $cardNumber = isset($result['result']['card_pan']) ? $this->maskCardNumber($result['result']['card_pan']) : 'N/A';

            $successResult = [
                'trade_no' => $factorNumber,
                'callback_no' => $result['result']['refid'] ?? $token,
                'amount' => $order->total_amount,
                'card_number' => $cardNumber
            ];

            // Mark token as used
            $track = PaymentTrack::getByTrackId($token);
            if ($track && !$track->is_used) {
                $track->markAsUsed();
                Log::channel('payment')->info('✓ Token marked as used');
            }

            // Cache the result
            cache()->put($processKey, true, 86400);
            cache()->put("payment_result_{$factorNumber}", $successResult, 86400);
            cache()->forget("order_{$factorNumber}");
            cache()->forget("shepa_token_{$factorNumber}");

            Log::channel('payment')->info('Payment completed successfully', [
                'token' => substr($token, 0, 10) . '...',
                'order_id' => $order->id,
                'trade_no' => $factorNumber,
                'amount' => $order->total_amount
            ]);

            return $successResult;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Verify exception', [
                'token' => substr($token, 0, 10) . '...',
                'factor_number' => $factorNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function getCallbackUrl($order)
    {
        // If custom callback is set, use it with {trade_no} replacement
        if (!empty($this->config['shepa_callback'])) {
            return str_replace('{trade_no}', $order['trade_no'], $this->config['shepa_callback']);
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
        $sensitiveFields = ['api', 'shepa_api_key', 'card_pan', 'token'];

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
