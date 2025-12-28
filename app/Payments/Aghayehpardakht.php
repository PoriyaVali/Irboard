<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\PaymentTrack;

class Aghayehpardakht
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'aghayeh_pin' => [
                'label' => 'پین درگاه آقای پرداخت',
                'description' => 'پین دریافتی از پنل آقای پرداخت',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (!isset($this->config['aghayeh_pin'])) {
            Log::channel('payment')->error('Aghayehpardakht config is missing required keys');
            throw new \Exception('تنظیمات آقای پرداخت ناقص است.');
        }

        $params = [
            'pin' => $this->config['aghayeh_pin'],
            'amount' => $order['total_amount'],
            'callback' => $order['notify_url'],
            'invoice_id' => $order['trade_no'],
            'description' => 'پرداخت سفارش ' . $order['trade_no'],
        ];

        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://panel.aqayepardakht.ir/api/v2/create', $params);

            $result = $response->json();

            Log::channel('payment')->info('Aghayehpardakht payment request:', $this->filterLogData($params));
            Log::channel('payment')->info('Aghayehpardakht payment response:', $this->filterLogData($result));

            if ($response->successful() && isset($result['status']) && $result['status'] === 'success') {
                $transId = $result['transid'];

                // Store transId in payment_tracks table
                $trackSaved = false;

                try {
                    PaymentTrack::store(
                        $transId,
                        $order['id'] ?? 0,
                        $order['user_id'] ?? 0,
                        $order['total_amount'] ?? 0,
                        'aghayehpardakht',
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
                    cache()->put("aghayeh_trans_{$order['trade_no']}", $transId, 259200);

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
                    'data' => 'https://panel.aqayepardakht.ir/startpay/' . $transId,
                ];
            }

            Log::channel('payment')->error('Aghayehpardakht payment request failed', $result);
            throw new \Exception($result['message'] ?? 'خطای نامشخص از آقای پرداخت');

        } catch (\Exception $e) {
            Log::channel('payment')->error('Aghayehpardakht payment exception', [
                'error' => $e->getMessage(),
                'order' => $order['trade_no'] ?? 'unknown'
            ]);
            return false;
        }
    }

    public function notify($params)
    {
        Log::channel('payment')->info('Aghayehpardakht notify received', $this->filterLogData($params));

        // Check required parameters
        if (!isset($params['transid']) || !isset($params['invoice_id'])) {
            Log::channel('payment')->error('Missing required parameters');
            return false;
        }

        $transId = $params['transid'];
        $invoiceId = $params['invoice_id'];

        // Check if already processed
        $processKey = "processed_{$invoiceId}_{$transId}";
        if (cache()->has($processKey)) {
            Log::channel('payment')->info('Payment already processed', ['key' => $processKey]);
            return cache()->get("payment_result_{$invoiceId}") ?: false;
        }

        // Check payment status (status=1 means success)
        if (!isset($params['status']) || $params['status'] != 1) {
            Log::channel('payment')->error('Transaction failed', ['status' => $params['status'] ?? null]);
            return false;
        }

        // Validate transId with payment_tracks table
        if (!PaymentTrack::isValid($transId)) {
            $track = PaymentTrack::getByTrackId($transId);

            if ($track) {
                if ($track->is_used) {
                    Log::channel('payment')->error('TransId already used', [
                        'trans_id' => $transId,
                        'invoice_id' => $invoiceId,
                        'used_at' => $track->used_at ? $track->used_at->format('Y-m-d H:i:s') : null
                    ]);
                    return false;
                }
            } else {
                Log::channel('payment')->error('TransId not found in database', [
                    'trans_id' => $transId,
                    'invoice_id' => $invoiceId
                ]);

                // Check cache as fallback
                $cachedTransId = cache()->get("aghayeh_trans_{$invoiceId}");
                if (!$cachedTransId || $cachedTransId !== $transId) {
                    return false;
                }

                Log::channel('payment')->warning('TransId found in cache but not in DB, continuing', [
                    'trans_id' => $transId
                ]);
            }
        }

        // Get order information
        $order = cache()->remember("order_{$invoiceId}", 60, function() use ($invoiceId) {
            return Order::where('trade_no', $invoiceId)->first();
        });

        if (!$order) {
            Log::channel('payment')->error('Order not found', ['invoice_id' => $invoiceId]);
            return false;
        }

        // Verify with Aghayehpardakht gateway
        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://panel.aqayepardakht.ir/api/v2/verify', [
                    'pin' => $this->config['aghayeh_pin'],
                    'amount' => $order->total_amount,
                    'transid' => $transId
                ]);

            $result = $response->json();
            Log::channel('payment')->info('Aghayehpardakht verify response', $this->filterLogData($result));

            if (!$response->successful() || !isset($result['status']) || $result['status'] !== 'success') {
                Log::channel('payment')->error('Verify failed', [
                    'status' => $result['status'] ?? null,
                    'code' => $result['code'] ?? null
                ]);
                return false;
            }

            $cardNumber = isset($result['cardnumber']) ? $this->maskCardNumber($result['cardnumber']) : 'N/A';

            $successResult = [
                'trade_no' => $invoiceId,
                'callback_no' => $params['tracking_number'] ?? $transId,
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
            cache()->put("payment_result_{$invoiceId}", $successResult, 86400);
            cache()->forget("order_{$invoiceId}");
            cache()->forget("aghayeh_trans_{$invoiceId}");

            Log::channel('payment')->info('Payment completed successfully', [
                'trans_id' => $transId,
                'order_id' => $order->id,
                'trade_no' => $invoiceId,
                'amount' => $order->total_amount
            ]);

            return $successResult;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Verify exception', [
                'trans_id' => $transId,
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function filterLogData($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $filtered = $data;
        $sensitiveFields = ['pin', 'cardnumber', 'token'];

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
