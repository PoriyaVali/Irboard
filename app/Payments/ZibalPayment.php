<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\PaymentTrack;

class ZibalPayment
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'zibal_merchant' => [
                'label' => 'کد مرچنت زیبال',
                'description' => '',
                'type' => 'input',
            ],
            'zibal_callback' => [
                'label' => 'آدرس بازگشت',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (!isset($this->config['zibal_merchant'], $this->config['zibal_callback'])) {
            Log::error('Zibal config is missing required keys');
            throw new \Exception('تنظیمات زیبال ناقص است.');
        }

        $params = [
            'merchant' => $this->config['zibal_merchant'],
            'amount' => $order['total_amount'] * 10,
            'callbackUrl' => rtrim($this->config['zibal_callback'], '/') . '/' . $order['trade_no'],
            'orderId' => $order['trade_no'],
            'description' => 'پرداخت سفارش ' . $order['trade_no'],
        ];

        try {
            // تلاش برای ارسال از طریق proxy
            $proxyResult = $this->sendViaProxy('zibal_request', $params);
            $result = null;
            
            if ($proxyResult['use_proxy']) {
                $result = $proxyResult['result'];
            } else {
                $response = Http::retry(3, 100)
                    ->timeout(20)
                    ->post('https://gateway.zibal.ir/v1/request', $params);
                $result = $response->json();
            }

            Log::channel('payment')->info('Zibal payment request:', $this->filterLogData($params));
            Log::channel('payment')->info('Zibal payment response:', $this->filterLogData($result));

            if (($result['result'] ?? 0) === 100) {
                $trackId = $result['trackId'];
                
                // Store trackId in payment_tracks table (critical for payment recovery)
                $trackSaved = false;
                
                try {
                    PaymentTrack::store(
                        trackId: $trackId,
                        orderId: $order['id'] ?? 0,
                        userId: $order['user_id'] ?? 0,
                        amount: $order['total_amount'] ?? 0,
                        method: 'zibal',
                        tradeNo: $order['trade_no'] ?? null
                    );
                    
                    $trackSaved = true;
                    
                    Log::channel('payment')->info('✓ TrackId stored successfully', [
                        'track_id' => $trackId,
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'user_id' => $order['user_id'] ?? 0,
                        'amount' => $order['total_amount'] ?? 0
                    ]);
                    
                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store trackId in database', [
                        'track_id' => $trackId,
                        'order_id' => $order['id'] ?? 0,
                        'trade_no' => $order['trade_no'],
                        'user_id' => $order['user_id'] ?? 0,
                        'amount' => $order['total_amount'] ?? 0,
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'warning' => 'This payment may be lost if callback fails'
                    ]);
                }
                
                // Store in cache as backup (3 days TTL)
                try {
                    cache()->put("zibal_track_{$order['trade_no']}", $trackId, 259200);
                    
                    if (!$trackSaved) {
                        Log::channel('payment')->warning('TrackId saved in cache only (DB failed)', [
                            'track_id' => $trackId,
                            'trade_no' => $order['trade_no']
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::channel('payment')->critical('CRITICAL: Failed to store trackId in both DB and cache', [
                        'track_id' => $trackId,
                        'trade_no' => $order['trade_no'],
                        'error' => $e->getMessage()
                    ]);
                }
                
                return [
                    'type' => 1,
                    'data' => 'https://gateway.zibal.ir/start/' . $trackId,
                ];
            }

            Log::channel('payment')->error('Zibal payment request failed', $result);
            throw new \Exception($result['message'] ?? 'خطای نامشخص از زیبال');

        } catch (\Exception $e) {
            Log::channel('payment')->error('Zibal payment exception', [
                'error' => $e->getMessage(),
                'order' => $order['trade_no'] ?? 'unknown'
            ]);
            return false;
        }
    }

    public function notify($params)
    {
        Log::channel('payment')->info('Zibal notify received', $this->filterLogData($params));

        // trackId الزامیه
        if (empty($params['trackId'])) {
            Log::channel('payment')->error('Missing trackId');
            return false;
        }

        // اگه orderId نبود از payment_tracks بخون
        if (empty($params['orderId'])) {
            $track = PaymentTrack::getByTrackId($params['trackId']);
            if ($track && $track->trade_no) {
                $params['orderId'] = $track->trade_no;
                Log::channel('payment')->info('orderId resolved from payment_tracks', [
                    'trackId' => $params['trackId'],
                    'orderId' => $params['orderId']
                ]);
            } else {
                Log::channel('payment')->error('Cannot resolve orderId', ['trackId' => $params['trackId']]);
                return false;
            }
        }

        if (!isset($params['success'])) {
            $params['success'] = 0;
        }

        // Check if already processed
        $processKey = "processed_{$params['orderId']}_{$params['trackId']}";
        if (cache()->has($processKey)) {
            Log::channel('payment')->info('Payment already processed', ['key' => $processKey]);
            return cache()->get("payment_result_{$params['orderId']}") ?: false;
        }

        // Validate trackId with payment_tracks table
        $trackId = $params['trackId'];
        
        if (!PaymentTrack::isValid($trackId)) {
            $track = PaymentTrack::getByTrackId($trackId);
            
            if ($track) {
                if ($track->is_used) {
                    Log::channel('payment')->error('TrackId already used', [
                        'track_id' => $trackId,
                        'order_id' => $params['orderId'],
                        'used_at' => $track->used_at ? $track->used_at->format('Y-m-d H:i:s') : null
                    ]);
                    return false;
                }
            } else {
                Log::channel('payment')->error('TrackId not found in database', [
                    'track_id' => $trackId,
                    'order_id' => $params['orderId']
                ]);
                
                // Check cache as fallback
                $cachedTrackId = cache()->get("zibal_track_{$params['orderId']}");
                if (!$cachedTrackId || $cachedTrackId !== $trackId) {
                    return false;
                }
                
                Log::channel('payment')->warning('TrackId found in cache but not in DB, continuing', [
                    'track_id' => $trackId
                ]);
            }
        }

        // Check payment status
        if ($params['success'] != 1) {
            Log::channel('payment')->error('Transaction failed', ['success_code' => $params['success']]);
            return false;
        }

        // Get order information
        $order = cache()->remember("order_{$params['orderId']}", 60, function() use ($params) {
            return Order::where('trade_no', $params['orderId'])->first();
        });

        if (!$order) {
            Log::channel('payment')->error('Order not found', ['order_id' => $params['orderId']]);
            return false;
        }

        // Verify with Zibal gateway
        try {
            $verifyParams = [
                'merchant' => $this->config['zibal_merchant'],
                'trackId' => $params['trackId']
            ];
            
            $proxyResult = $this->sendViaProxy('zibal_verify', $verifyParams);
            
            if ($proxyResult['use_proxy']) {
                $result = $proxyResult['result'];
            } else {
                $response = Http::retry(3, 100)
                    ->timeout(20)
                    ->post('https://gateway.zibal.ir/v1/verify', $verifyParams);
                $result = $response->json();
            }
            Log::channel('payment')->info('Zibal verify response', $this->filterLogData($result));

            if (($result['result'] ?? 0) !== 100) {
                Log::channel('payment')->error('Verify failed: Invalid result code', [
                    'result_code' => $result['result'] ?? null
                ]);
                return false;
            }

            if ($result['amount'] != ($order->total_amount * 10)) {
                Log::channel('payment')->error('Verify failed: Amount mismatch', [
                    'expected_amount' => $order->total_amount * 10,
                    'received_amount' => $result['amount'] ?? null
                ]);
                return false;
            }

            $cardNumber = isset($result['cardNumber']) ? $this->maskCardNumber($result['cardNumber']) : 'N/A';

            $successResult = [
                'trade_no' => $params['orderId'],
                'callback_no' => $params['trackId'],
                'amount' => $order->total_amount,
                'card_number' => $cardNumber
            ];

            // Mark trackId as used
            $track = PaymentTrack::getByTrackId($trackId);
            if ($track && !$track->is_used) {
                $track->markAsUsed();
                Log::channel('payment')->info('✓ TrackId marked as used');
            }

            // Cache the result
            cache()->put($processKey, true, 86400);
            cache()->put("payment_result_{$params['orderId']}", $successResult, 86400);
            cache()->forget("order_{$params['orderId']}");

            Log::channel('payment')->info('Payment completed successfully', [
                'track_id' => $trackId,
                'order_id' => $order->id,
                'trade_no' => $params['orderId'],
                'amount' => $order->total_amount
            ]);

            return $successResult;
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('Verify exception', [
                'track_id' => $trackId,
                'order_id' => $params['orderId'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Inquiry payment status from Zibal
     */
    public function inquiry($trackId)
    {
        try {
            $inquiryParams = [
                'merchant' => $this->config['zibal_merchant'],
                'trackId' => $trackId
            ];
            
            $proxyResult = $this->sendViaProxy('zibal_inquiry', $inquiryParams);
            
            if ($proxyResult['use_proxy']) {
                $result = $proxyResult['result'];
            } else {
                $response = Http::retry(3, 100)
                    ->timeout(20)
                    ->post('https://gateway.zibal.ir/v1/inquiry', $inquiryParams);
                $result = $response->json();
            }
            Log::channel('payment')->info('Zibal inquiry response', $this->filterLogData($result));

            if (($result['result'] ?? 0) === 100) {
                return [
                    'status' => $result['status'] ?? null,
                    'amount' => isset($result['amount']) ? $result['amount'] / 10 : null,
                    'cardNumber' => $result['cardNumber'] ?? null,
                    'refNumber' => $result['refNumber'] ?? null,
                    'paidAt' => $result['paidAt'] ?? null,
                    'orderId' => $result['orderId'] ?? null,
                    'description' => $result['description'] ?? null,
                ];
            }

            Log::channel('payment')->error('Zibal inquiry failed', $result);
            return false;
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('Zibal inquiry exception', [
                'track_id' => $trackId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Standalone verify used by payment recovery (CheckPendingPayments).
     * Returns true on result 100 (verified) or 201 (already verified).
     */
    public function verify($trackId)
    {
        try {
            $verifyParams = [
                'merchant' => $this->config['zibal_merchant'],
                'trackId' => $trackId
            ];

            $proxyResult = $this->sendViaProxy('zibal_verify', $verifyParams);

            if ($proxyResult['use_proxy']) {
                $result = $proxyResult['result'];
            } else {
                $response = Http::retry(3, 100)
                    ->timeout(20)
                    ->post('https://gateway.zibal.ir/v1/verify', $verifyParams);
                $result = $response->json();
            }

            Log::channel('payment')->info('Zibal verify response (recovery)', $this->filterLogData($result));

            $code = $result['result'] ?? 0;
            if ($code === 100 || $code === 201) {
                return true;
            }

            Log::channel('payment')->error('Zibal verify failed (recovery)', $result);
            return false;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Zibal verify exception (recovery)', [
                'track_id' => $trackId,
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
        $sensitiveFields = ['merchant', 'cardNumber', 'token'];
        
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

    /**
     * ارسال درخواست از طریق proxy
     */
    protected function sendViaProxy(string $action, array $data): array
    {
        $proxyEnable = \DB::table('v2_bot_settings')->where('key', 'payment_proxy_enable')->value('value');
        
        if ($proxyEnable !== '1') {
            return ['use_proxy' => false];
        }
        
        $proxyUrl = \DB::table('v2_bot_settings')->where('key', 'payment_proxy_url')->value('value');
        $proxySecret = \DB::table('v2_bot_settings')->where('key', 'payment_proxy_secret')->value('value');
        
        if (empty($proxyUrl) || empty($proxySecret)) {
            return ['use_proxy' => false];
        }
        
        $data['_action'] = $action;
        $data['_secret'] = $proxySecret;
        
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->post($proxyUrl, $data);
            
            if ($response->successful()) {
                return ['use_proxy' => true, 'result' => $response->json()];
            }
        } catch (\Exception $e) {
            \Log::channel('payment')->error('Proxy request failed', ['error' => $e->getMessage()]);
        }
        
        return ['use_proxy' => false];
    }

}