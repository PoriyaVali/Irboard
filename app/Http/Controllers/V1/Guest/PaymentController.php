<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        $requestData = $request->all();
    
        $this->logInfo('Payment notification received', [
            'method' => $method,
            'uuid' => $uuid,
            'request_data' => $requestData,
        ]);

        // ✅ چک اولیه: آیا order قبلاً پردازش شده؟ (Idempotency Check)
        $order = Order::where('trade_no', $uuid)->first();
        if ($order && $order->status !== 0) {
            $this->logInfo('Order already processed', [
                'trade_no' => $uuid,
                'status' => $order->status
            ]);
            
            // فقط status = 3 (پرداخت شده) موفقیت است
            if ($order->status == 3) {
                return $this->renderPaymentResult(true, 'پرداخت با موفقیت انجام شد.', $uuid);
            }
            // status = 2 (لغو شده)
            if ($order->status == 2) {
                return $this->renderPaymentResult(false, 'این سفارش لغو شده است.', $uuid);
            }
            // سایر حالات
            return $this->renderPaymentResult(false, 'سفارش قبلاً پردازش شده است.', $uuid);
        }

        // جلوگیری از پردازش همزمان
        $lockKey = "payment_lock_{$uuid}";
        $lock = Cache::lock($lockKey, 30);
    
        if (!$lock->get()) {
            // اگر locked است، احتمالا در حال پردازش است
            sleep(2);
            $previousResult = Cache::get("payment_response_{$uuid}");
            if ($previousResult && is_array($previousResult)) {
                return $this->renderPaymentResult(
                    $previousResult['success'], 
                    $previousResult['success'] ? 'پرداخت با موفقیت انجام شد.' : 'خطا در پردازش پرداخت.',
                    $previousResult['trade_no'] ?? null
                );
            }
        }

        DB::beginTransaction();
    
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verificationResult = $paymentService->notify($requestData);

            $this->logInfo('Payment verification result', ['verify' => $verificationResult]);

            if ($verificationResult === false) {
                throw new \Exception('Transaction was not successful or verification failed');
            }
        
            $cardNumber = $verificationResult['card_number'] ?? 'N/A';
    
           if (!$this->handleOrder($verificationResult['trade_no'], $verificationResult['callback_no'], $cardNumber)) {
                throw new \Exception('Handle error');
            }
        
            DB::commit();
        
            // ذخیره نتیجه برای جلوگیری از پردازش تکراری
            Cache::put("payment_response_{$uuid}", [
                'success' => true,
                'trade_no' => $verificationResult['trade_no']
            ], 300);
        
        $this->logInfo('Payment process completed', ['response' => 'success']);
        
            $lock->release();
        
            return $this->renderPaymentResult(true, 'پرداخت با موفقیت انجام شد.', $verificationResult['trade_no']);
        
        } catch (\Exception $e) {
            DB::rollBack();
            $lock->release();
            $this->logError('Payment notification error', $e);
            return $this->renderPaymentResult(false, 'خطا در پردازش پرداخت.', $uuid);
        }
    }
	
    private function handleOrder($tradeNo, $transactionId, $cardNumber = 'N/A')
    {
        $this->logInfo('Handling payment', [
            'trade_no' => $tradeNo,
            'transaction_id' => $transactionId,
            'card_number' => $cardNumber
        ]);
        
        $order = Cache::remember("order_{$tradeNo}", 60, function() use ($tradeNo) {
            return Order::where('trade_no', $tradeNo)->first();
        });
        
        if (!$order) {
            $this->logError('Order not found', ['trade_no' => $tradeNo]);
            return false;
        }
        
        $this->logInfo('Order found', [
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'status' => $order->status,
            'total_amount' => $order->total_amount
        ]);

        // ← این بخش جدید است
        if ($order->status === 4) {
            $this->logInfo('Order already refunded to wallet', ['trade_no' => $tradeNo]);
            return true;
        }

        if ($order->status !== 0) {
            $this->logInfo('Order already processed', ['trade_no' => $tradeNo]);
            return true;
        }
    
        // بقیه کد بدون تغییر...
    
        if ($order->total_amount == 0 && $order->balance_amount > 0) {
            $this->logInfo('Order paid using balance', [
               'trade_no' => $tradeNo,
                'balance_used' => $order->balance_amount
            ]);
        
            $order->status = 3;
            $order->paid_at = now();
            $order->updated_at = now();
            $order->save();
        
            Cache::forget("order_{$tradeNo}");

            $this->logInfo('Order status updated successfully using balance', [
                'trade_no' => $tradeNo
            ]);
        
            $this->sendBalanceNotification($order);
        
            return true;
        }
    
        $orderService = new OrderService($order);

        if (!$orderService->paid($transactionId)) {
            $this->logError('Could not update order status', [
                'trade_no' => $tradeNo,
                'transaction_id' => $transactionId
            ]);
            return false;
        }
    
        Cache::forget("order_{$tradeNo}");
    
        $this->logInfo('Order status updated successfully', [
            'trade_no' => $tradeNo,
            'transaction_id' => $transactionId
        ]);
    
        $this->sendPaymentNotification($order, $cardNumber);

        return true;
    }
    
    private function sendPaymentNotification($order, $cardNumber)
    {
        try {
            $user = User::find($order->user_id);
            if (!$user) {
                $this->logError('User not found for telegram notification', [
                    'user_id' => $order->user_id
                ]);
                return;
            }
            
            $adjustedAmount = $order->total_amount;
            $message = $this->generateTelegramMessage($adjustedAmount, $order, $user, $cardNumber);

            $telegramService = new TelegramService();
            $telegramService->sendMessageWithAdmin($message);
            
            $this->logInfo('Telegram message sent', ['trade_no' => $order->trade_no]);
            
        } catch (\Exception $e) {
            $this->logError('Telegram send failed', $e);
        }
    }
    
    private function sendBalanceNotification($order)
    {
        try {
            $user = User::find($order->user_id);
            if (!$user) {
                return;
            }
            
            $message = sprintf(
                "💳 پرداخت با موجودی حساب\n" .
                "———————————————\n" .
                "شماره سفارش: %s\n" .
                "مبلغ: %s تومان\n" .
                "ایمیل: %s\n" .
                "———————————————\n" .
                "زمان: %s",
                $order->trade_no,
                number_format($order->balance_amount),
                $user->email,
                now()->format('Y-m-d H:i:s')
            );
            
            $telegramService = new TelegramService();
            $telegramService->sendMessageWithAdmin($message);
            
        } catch (\Exception $e) {
            $this->logError('Balance payment telegram failed', $e);
        }
    }
    
    private function renderPaymentResult($success, $message, $tradeNo = null)
    {
        $this->logInfo('Rendering payment result', [
            'success' => $success,
            'trade_no' => $tradeNo,
            'message' => $message
        ]);
        
        $orderInfo = '';
        $order = null;
        if ($tradeNo) {
            $order = Cache::get("order_{$tradeNo}") ?: Order::where('trade_no', $tradeNo)->first();
            
            if ($order && $success) {
                $adjustedAmount = ($order->total_amount > 0) ? $order->total_amount : $order->balance_amount;
                $orderInfo = "<p>شماره سفارش: {$order->trade_no}</p>" .
                             "<p>مبلغ پرداخت شده: " . number_format($adjustedAmount, 0, '.', ',') . " تومان</p>";
            }
        }
        
        // اگر سفارش از تلگرام بود، پیام به ربات ارسال شود
        if ($order && $order->source === 'telegram') {
            $this->notifyTelegramUser($order, $success, $message);
            return $this->renderTelegramResult($success, $message, $order);
        }

        if ($order && $order->source === 'app') {
            return $this->renderAppResult($success, $message, $order);
        }
        
        if ($success) {
            $this->logInfo('Success page displayed', ['trade_no' => $tradeNo]);
            return view('success', compact('orderInfo'));
        } else {
            $this->logInfo('Failure page displayed', ['message' => $message]);
            return view('failure', compact('message'));
        }
    }
    
    private function notifyTelegramUser($order, $success, $message)
    {
        try {
            $user = \App\Models\User::find($order->user_id);
            if (!$user || !$user->telegram_id) return;
            
            $token = config('v2board.telegram_bot_token');
            if (!$token) return;
            
            $adjustedAmount = ($order->total_amount > 0) ? $order->total_amount : $order->balance_amount;
            
            if ($success) {
                $text = "✅ پرداخت موفق!\n\n";
                $text .= "💰 مبلغ: " . number_format($adjustedAmount) . " تومان\n";
                $text .= "🔢 شماره سفارش: {$order->trade_no}\n\n";
                if ($order->plan_id) {
                    $text .= "🎉 اشتراک شما فعال شد!";
                } else {
                    $text .= "💵 کیف پول شما شارژ شد!";
                }
            } else {
                $text = "❌ پرداخت ناموفق\n\n";
                $text .= "🔢 شماره سفارش: {$order->trade_no}\n";
                $text .= "📝 علت: {$message}";
            }
            
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $data = [
                'chat_id' => $user->telegram_id,
                'text' => $text,
                'parse_mode' => ''
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            curl_exec($ch);
            curl_close($ch);
            
        } catch (\Exception $e) {
            Log::error('Telegram notify error: ' . $e->getMessage());
        }
    }
    
    private function renderAppResult($success, $message, $order)
    {
        $adjustedAmount = ($order->total_amount > 0) ? $order->total_amount : $order->balance_amount;
        $status = $success ? 'success' : 'failed';
        $deeplink = 'hiddify://payment-result?status=' . $status . '&trade_no=' . urlencode($order->trade_no);

        if ($success) {
            $title = 'پرداخت موفق';
            $icon = '✅';
            $color = '#28a745';
            $desc = $order->plan_id ? 'اشتراک شما فعال شد!' : 'کیف پول شما شارژ شد!';
        } else {
            $title = 'پرداخت ناموفق';
            $icon = '❌';
            $color = '#dc3545';
            $desc = $message;
        }

        $dl = htmlspecialchars($deeplink, ENT_QUOTES);
        $html = '<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . $title . '</title>
<style>
body{font-family:Tahoma,sans-serif;background:#f5f5f5;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0}
.card{background:#fff;border-radius:16px;padding:40px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:400px}
.icon{font-size:64px;margin-bottom:20px}
h1{color:' . $color . ';margin-bottom:10px}
.amount{font-size:24px;color:#333;margin:20px 0}
.desc{color:#666;margin-bottom:30px}
.btn{display:inline-block;background:#0088cc;color:#fff;padding:12px 30px;border-radius:8px;text-decoration:none}
</style>
</head>
<body>
<div class="card">
<div class="icon">' . $icon . '</div>
<h1>' . $title . '</h1>
<div class="amount">' . number_format($adjustedAmount) . ' تومان</div>
<p class="desc">' . $desc . '</p>
<a href="' . $dl . '" class="btn">بازگشت به برنامه</a>
</div>
<script>setTimeout(function(){window.location.href="' . $dl . '";},800);</script>
</body>
</html>';

        return response($html);
    }

    private function renderTelegramResult($success, $message, $order)
    {
        $adjustedAmount = ($order->total_amount > 0) ? $order->total_amount : $order->balance_amount;
        
        if ($success) {
            $title = 'پرداخت موفق';
            $icon = '✅';
            $color = '#28a745';
            $desc = $order->plan_id ? 'اشتراک شما فعال شد!' : 'کیف پول شما شارژ شد!';
        } else {
            $title = 'پرداخت ناموفق';
            $icon = '❌';
            $color = '#dc3545';
            $desc = $message;
        }
        
        $html = '<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: white; border-radius: 16px; padding: 40px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 400px; }
        .icon { font-size: 64px; margin-bottom: 20px; }
        h1 { color: ' . $color . '; margin-bottom: 10px; }
        .amount { font-size: 24px; color: #333; margin: 20px 0; }
        .desc { color: #666; margin-bottom: 30px; }
        .btn { display: inline-block; background: #0088cc; color: white; padding: 12px 30px; border-radius: 8px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">' . $icon . '</div>
        <h1>' . $title . '</h1>
        <div class="amount">' . number_format($adjustedAmount) . ' تومان</div>
        <p class="desc">' . $desc . '</p>
        <a href="https://t.me/' . $this->getTelegramBotUsername() . '" class="btn">بازگشت به ربات</a>
    </div>
</body>
</html>';
        
        return response($html);
    }
    

    private function getTelegramBotUsername()
    {
        $token = config('v2board.telegram_bot_token');
        if (!$token) return config('v2board.telegram_bot_username', 'bot');
        
        try {
            $cacheKey = 'telegram_bot_username';
            $username = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function() use ($token) {
                $response = @file_get_contents("https://api.telegram.org/bot{$token}/getMe");
                $data = json_decode($response, true);
                return $data['result']['username'] ?? config('v2board.telegram_bot_username', 'bot');
            });
            return $username;
        } catch (\Exception $e) {
            return config('v2board.telegram_bot_username', 'bot');
        }
    }

    private function logInfo($message, $data = [])
    {
        Log::channel('payment')->info($message, [
            'timestamp' => now(),
            'context' => $data,
            'memory_usage' => memory_get_usage(),
            'request_ip' => request()->ip(),
            'user_agent' => request()->header('User-Agent')
        ]);
    }
    
    private function logError($message, $data)
    {
        if ($data instanceof \Exception) {
            Log::channel('payment')->error($message, [
                'timestamp' => now(),
                'error' => $data->getMessage(),
                'trace' => $data->getTraceAsString(),
                'memory_usage' => memory_get_usage(),
                'request_ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent')
            ]);
        } else {
            Log::channel('payment')->error($message, [
                'timestamp' => now(),
                'context' => $data,
                'memory_usage' => memory_get_usage(),
                'request_ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent')
            ]);
        }
    }
    
    private function generateTelegramMessage($adjustedAmount, $order, $user, $cardNumber)
    {
        Log::info('CardNumber before formatting:', ['cardNumber' => $cardNumber]);
        $formattedCardNumber = $this->formatCardNumber($cardNumber);
        Log::info('Formatted cardNumber for Telegram:', ['formattedCardNumber' => $formattedCardNumber]);
        
        $subscribeLink = config('v2board.app_url') . "/api/v1/client/subscribe?token=" . $user->token;
        
        return sprintf(
            "💰 پرداخت موفق به مبلغ %s تومان\n———————————————\nشماره سفارش: %s\nایمیل کاربر: %s\nشماره کارت: %s\n———————————————\nلینک اشتراک: %s",
            number_format($adjustedAmount, 0, '.', ','),
            $order->trade_no,
            $user->email,
            $formattedCardNumber,
            $subscribeLink
        );
    }
    
    private function formatCardNumber($cardNumber)
    {
        if (empty($cardNumber) || !is_string($cardNumber)) {
            return 'N/A';
        }
        
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        
        if (strlen($cardNumber) < 10) {
            return 'N/A';
        }
        
        return substr($cardNumber, -4) . '......' . substr($cardNumber, 0, 6);
    }
}
