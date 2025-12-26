<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = null, $trade_no = null)
    {
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        
        if (!class_exists($this->class)) {
            Log::channel('payment')->error('Payment class not found', [
                'class' => $this->class,
                'method' => $method
            ]);
            abort(500, 'gate is not found');
        }

        $order = null;
        
        if ($id) {
            $order = Order::find($id);
        }

        if ($trade_no) {
            $order = Cache::remember("order_service_{$trade_no}", 60, function() use ($trade_no) {
                return Order::where('trade_no', $trade_no)->first();
            });
        }

        if (!$order && ($id || $trade_no)) {
            Log::channel('payment')->error('Order not found', [
                'timestamp' => now(),
                'trade_no' => $trade_no,
                'id' => $id,
                'memory_usage' => memory_get_usage(),
                'request_ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent')
            ]);
            
            if ($trade_no) {
                abort(500, 'Order not found for trade_no: ' . $trade_no);
            }
        }

        try {
            // خواندن از دیتابیس با cache (TTL: 5 دقیقه)
            $payment = Cache::remember("payment_config_{$method}", 300, function() use ($method) {
                return Payment::where('payment', $method)
                    ->where('enable', 1)
                    ->first();
            });

            if (!$payment) {
                Log::channel('payment')->error('Payment method not found or disabled', [
                    'method' => $method
                ]);
                abort(500, 'Payment method not enabled');
            }

            // دریافت config (مدل Payment خودش config رو به array تبدیل می‌کنه)
            $configData = $payment->config;
            
            // اگر به هر دلیل config یک string بود (compatibility با نسخه‌های قدیمی)
            if (is_string($configData)) {
                $configData = json_decode($configData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::channel('payment')->error('Invalid JSON in payment config', [
                        'method' => $method,
                        'error' => json_last_error_msg()
                    ]);
                    abort(500, 'Invalid payment configuration');
                }
            } elseif (!is_array($configData)) {
                Log::channel('payment')->error('Payment config is not an array', [
                    'method' => $method,
                    'type' => gettype($configData)
                ]);
                abort(500, 'Invalid payment configuration');
            }

            // ✅ چک کردن اینکه config خالی نباشه
            if (empty($configData)) {
                Log::channel('payment')->error('Payment config is empty', [
                    'method' => $method,
                    'payment_id' => $payment->id
                ]);
                abort(500, 'Payment configuration is empty. Please configure it in admin panel.');
            }

            // ✅ برای ZibalPayment چک کردن فیلدهای ضروری
            if ($method === 'ZibalPayment') {
                if (!isset($configData['zibal_merchant']) || !isset($configData['zibal_callback'])) {
                    Log::channel('payment')->error('ZibalPayment config is incomplete', [
                        'method' => $method,
                        'has_merchant' => isset($configData['zibal_merchant']),
                        'has_callback' => isset($configData['zibal_callback'])
                    ]);
                    abort(500, 'ZibalPayment configuration is incomplete. Please set merchant and callback in admin panel.');
                }
            }

            $this->config = [
                'config' => $configData,
                'enable' => $payment->enable ?? 1,
                'trade_no' => $trade_no ?? null,
                'notify_domain' => $payment->notify_domain ?? null,
                'id' => $id ?? null
            ];

            $this->payment = new $this->class($this->config['config']);
            
            Log::channel('payment')->info('Payment service initialized', [
                'method' => $method,
                'trade_no' => $trade_no,
                'config_keys' => array_keys($configData)
            ]);
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('Payment service initialization failed', [
                'method' => $method,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function notify($params)
    {
        Log::channel('payment')->info('Processing payment notification', [
            'timestamp' => now(),
            'method' => $this->method,
            'trade_no' => $this->config['trade_no'],
            'params' => $params,
            'memory_usage' => memory_get_usage(),
            'request_ip' => request()->ip(),
            'user_agent' => request()->header('User-Agent')
        ]);
    
        if (!$this->config['enable']) {
            Log::channel('payment')->error('Payment gateway is not enabled', [
                'timestamp' => now(),
                'method' => $this->method,
                'trade_no' => $this->config['trade_no']
            ]);
            abort(500, 'gate is not enabled');
        }

        $result = $this->payment->notify($params);
        
        if ($result !== false && isset($result['trade_no'])) {
            Cache::forget("order_service_{$result['trade_no']}");
            Cache::forget("payment_init_{$result['trade_no']}");
        }
        
        return $result;
    }

    public function pay($order)
    {
        Log::channel('payment')->info('Initiating payment process', [
            'timestamp' => now(),
            'method' => $this->method,
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'memory_usage' => memory_get_usage(),
            'request_ip' => request()->ip(),
            'user_agent' => request()->header('User-Agent')
        ]);

        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['trade_no']}");

        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        $paymentData = [
            'notify_url' => $notifyUrl,
            'return_url' => url('/#/order/' . $order['trade_no']),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token'] ?? null
        ];
        
        $result = $this->payment->pay($paymentData);
        
        if ($result !== false) {
            Cache::put("payment_init_{$order['trade_no']}", [
                'method' => $this->method,
                'amount' => $order['total_amount'],
                'initiated_at' => now(),
            ], 300);
        }
        
        return $result;
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        
        $configArray = $this->config['config'] ?? [];
        if (!is_array($configArray)) {
            $configArray = [];
        }

        foreach ($keys as $key) {
            if (isset($configArray[$key])) {
                $form[$key]['value'] = $configArray[$key];
            }
        }

        return $form;
    }
}
