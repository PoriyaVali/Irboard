<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\PaymentTrack;

class IDPayPayment
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'idpay_api_key' => [
                'label' => 'کلید API آیدی پی',
                'description' => 'کلید API دریافتی از پنل آیدی پی',
                'type' => 'input',
            ],
            'idpay_sandbox' => [
                'label' => 'حالت تست (Sandbox)',
                'description' => '1 = فعال | 0 = غیرفعال',
                'type' => 'input',
            ],
            'idpay_callback' => [
                'label' => 'آدرس بازگشت (Callback URL)',
                'description' => 'خالی = پیش‌فرض | یا آدرس کامل مثل: https://example.com/pay/{trade_no}',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (!isset($this->config['idpay_api_key'])) {
            Log::channel('payment')->error('IDPay config is missing required keys');
            throw new \Exception('تنظیمات آیدی پی ناقص است.');
        }

        $params = [
            'order_id' => $order['trade_no'],
            'amount' => $order['total_amount'],
            'callback' => $this->getCallbackUrl($order),
            'desc' => 'پرداخت سفارش ' . $order['trade_no'],
        ];

        $sandbox = isset($this->config['idpay_sandbox']) && $this->config['idpay_sandbox'] == '1';

        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'X-API-KEY' => $this->config['idpay_api_key'],
                    'X-SANDBOX' => $sandbox ? '1' : '0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://api.idpay.ir/v1.1/payment', $params);

            $result = $response->json();

            Log::channel('payment')->info('IDPay payment request:', $this->filterLogData($params));
            Log::channel('payment')->info('IDPay payment response:', $this->filterLogData($result));

            if ($response->successful() && isset($result['id']) && isset($result['link'])) {
                $transId = $result['id'];

                // Store transId in payment_tracks table
                $trackSaved = false;

                try {
                    PaymentTrack::store(
                        $transId,
                        $order['id'] ?? 0,
                        $order['user_id'] ?? 0,
                        $order['total_amount'] ?? 0,
                        'idpay',
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
                    cache()->put("idpay_trans_{$order['trade_no']}", $transId, 259200);

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
                    'data' => $result['link'],
                ];
            }

            Log::channel('payment')->error('IDPay payment request failed', $result);
            throw new \Exception($result['error_message'] ?? 'خطای نامشخص از آیدی پی');

        } catch (\Exception $e) {
            Log::channel('payment')->error('IDPay payment exception', [
                'error' => $e->getMessage(),
                'order' => $order['trade_no'] ?? 'unknown'
            ]);
            return false;
        }
    }

    public function notify($params)
    {
        Log::channel('payment')->info('IDPay notify received', $this->filterLogData($params));

        // Check required parameters
        if (!isset($params['id']) || !isset($params['order_id'])) {
            Log::channel('payment')->error('Missing required parameters');
            return false;
        }

        $transId = $params['id'];
        $orderId = $params['order_id'];
        $status = $params['status'] ?? 0;

        // Check if already processed
        $processKey = "processed_{$orderId}_{$transId}";
        if (cache()->has($processKey)) {
            Log::channel('payment')->info('Payment already processed', ['key' => $processKey]);
            return cache()->get("payment_result_{$orderId}") ?: false;
        }

        // Check payment status (status=10 means success)
        if ($status != 10) {
            Log::channel('payment')->error('Transaction failed', ['status' => $status]);
            return false;
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
                $cachedTransId = cache()->get("idpay_trans_{$orderId}");
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

        $sandbox = isset($this->config['idpay_sandbox']) && $this->config['idpay_sandbox'] == '1';

        // Verify with IDPay gateway
        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'X-API-KEY' => $this->config['idpay_api_key'],
                    'X-SANDBOX' => $sandbox ? '1' : '0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://api.idpay.ir/v1.1/payment/verify', [
                    'id' => $transId,
                    'order_id' => $orderId
                ]);

            $result = $response->json();
            Log::channel('payment')->info('IDPay verify response', $this->filterLogData($result));

            if (!$response->successful() || !isset($result['status']) || $result['status'] != 100) {
                Log::channel('payment')->error('Verify failed', [
                    'status' => $result['status'] ?? null,
                    'error' => $result['error_message'] ?? null
                ]);
                return false;
            }

            $cardNumber = isset($result['payment']['card_no']) ? $this->maskCardNumber($result['payment']['card_no']) : 'N/A';

            $successResult = [
                'trade_no' => $orderId,
                'callback_no' => $result['track_id'] ?? $transId,
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
            cache()->forget("idpay_trans_{$orderId}");

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
        if (!empty($this->config['idpay_callback'])) {
            return str_replace('{trade_no}', $order['trade_no'], $this->config['idpay_callback']);
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
        $sensitiveFields = ['idpay_api_key', 'X-API-KEY', 'card_no', 'token'];

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
