<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Models\PaymentTrack;
use App\Payments\ZibalPayment;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CheckPendingPayments extends Command
{
    protected $signature = 'payment:check-pending 
                            {--refund-after=30 : Minutes until auto refund}
                            {--check-interval=5 : Minimum minutes between checks}
                            {--expire-after=30 : Minutes until order expiration}
                            {--check-cancelled : Check cancelled orders}
                            {--check-expired : Check expired orders}
                            {--hours=24 : Check orders from last N hours}
                            {--max-inquiry-fails=3 : Max inquiry failures before force refund}
                            {--mark-old-unused=120 : Mark unused tracks as used after N minutes}
                            {--notify-admin : Send Telegram notifications to admin}
                            {--debug : Show detailed output}';

    protected $description = 'Check and recover pending payments v2.8 - Using TelegramService like PaymentController';

    private $notifyAdmin;
    private $telegramService;

    public function handle()
    {
        $refundAfter = (int) $this->option('refund-after');
        $checkInterval = (int) $this->option('check-interval');
        $expireAfter = (int) $this->option('expire-after');
        $checkCancelled = $this->option('check-cancelled');
        $checkExpired = $this->option('check-expired');
        $hours = (int) $this->option('hours');
        $maxInquiryFails = (int) $this->option('max-inquiry-fails');
        $markOldUnused = (int) $this->option('mark-old-unused');
        $this->notifyAdmin = $this->option('notify-admin');
        $debug = $this->option('debug');
        
        // Set recovery timestamp for health check
        Cache::put('payment_recovery_last_run', time(), 3600);

        // Initialize Telegram service like PaymentController
        if ($this->notifyAdmin) {
            try {
                $this->telegramService = new TelegramService();
            } catch (\Exception $e) {
                $this->warn("⚠️  Telegram service initialization failed - notifications disabled");
                $this->notifyAdmin = false;
            }
        }

        if ($debug) {
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("🔍 Payment Recovery System v2.8");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("Admin notifications: " . ($this->notifyAdmin ? 'ON' : 'OFF'));
        }

        $stats = [
            'checked' => 0,
            'verified' => 0,
            'refunded' => 0,
            'expired' => 0,
            'cancelled' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $statusesToCheck = [0];
        if ($checkCancelled) $statusesToCheck[] = 2;
        if ($checkExpired) $statusesToCheck[] = 4;

        $pendingOrders = Order::whereIn('status', $statusesToCheck)
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereExists(function($query) {
                $query->select(DB::raw(1))
                      ->from('payment_tracks')
                      ->whereColumn('payment_tracks.trade_no', 'v2_order.trade_no')
                      ->where('payment_tracks.is_used', 0);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        if ($debug) {
            $this->info("\nFound {$pendingOrders->count()} orders with active tracks");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");
        }

        foreach ($pendingOrders as $order) {
            $stats['checked']++;

            $trackFromDb = PaymentTrack::where('trade_no', $order->trade_no)->first();
            if (!$trackFromDb) {
                $trackFromDb = PaymentTrack::where('order_id', $order->id)
                    ->where('order_id', '>', 0)
                    ->first();
            }
            
            $trackIdFromDb = $trackFromDb ? $trackFromDb->track_id : null;
            $trackIdFromCache = cache()->get("zibal_track_{$order->trade_no}");
            $trackId = $trackIdFromDb ?: $trackIdFromCache;

            if (!$trackId) {
                if ($debug) {
                    $this->line("⏭ Order {$order->trade_no}: No trackId found");
                }
                $stats['skipped']++;
                
                $orderAge = now()->diffInMinutes(\Carbon\Carbon::parse($order->created_at));
                if ($orderAge >= $expireAfter && $order->status == 0) {
                    $this->expireOrder($order);
                    $stats['expired']++;
                }
                
                continue;
            }

            $lastCheckKey = "payment_last_check_{$order->id}";
            $lastCheck = Cache::get($lastCheckKey, 0);
            
            if ($lastCheck && (time() - $lastCheck) < ($checkInterval * 60)) {
                if ($debug) {
                    $remaining = ($checkInterval * 60) - (time() - $lastCheck);
                    $this->line("⏭ Order {$order->trade_no}: Too soon ({$remaining}s)");
                }
                $stats['skipped']++;
                continue;
            }

            Cache::put($lastCheckKey, time(), 3600);
            $orderAge = now()->diffInMinutes(\Carbon\Carbon::parse($order->created_at));

            if ($debug) {
                $this->info("\n📋 Order: {$order->trade_no}");
                $this->line("  ID: {$order->id}");
                $this->line("  TrackID: {$trackId}");
                $this->line("  Status: {$order->status}");
                $this->line("  Age: {$orderAge} min");
                $this->line("  Amount: " . number_format($order->total_amount) . " تومان");
            }

            try {
                $paymentConfig = $this->getPaymentConfig();
                
                if (!$paymentConfig) {
                    if ($debug) {
                        $this->error("  ✗ Payment config not found");
                    }
                    
                    $this->sendTelegram('🚨 ERROR: Config Missing', $order, $trackId, [
                        'error' => 'Payment configuration not found'
                    ]);
                    
                    $stats['failed']++;
                    continue;
                }

                $zibal = new ZibalPayment($paymentConfig);
                $inquiry = $zibal->inquiry($trackId);

                $failCountKey = "inquiry_fail_{$order->id}";
                
                if ($inquiry === false) {
                    $failCount = Cache::get($failCountKey, 0) + 1;
                    Cache::put($failCountKey, $failCount, 3600);
                    
                    if ($debug) {
                        $this->warn("  ⚠ Inquiry failed (attempt {$failCount}/{$maxInquiryFails})");
                    }
                    
                    if ($failCount >= $maxInquiryFails && $orderAge >= $refundAfter) {
                        if ($debug) {
                            $this->warn("  ⚠ Max inquiry fails + old order → forcing refund...");
                        }
                        
                        if ($this->refundToWallet($order, $trackId, 'inquiry_failed_max_retries')) {
                            $this->info("  ✓ Force refunded to wallet");
                            
                            $this->sendTelegram('⚠️ WARNING: Force Refund', $order, $trackId, [
                                'reason' => 'Max inquiry failures',
                                'fail_count' => $failCount,
                                'order_age' => $orderAge . ' min'
                            ]);
                            
                            Cache::forget($failCountKey);
                            $stats['refunded']++;
                        } else {
                            $this->error("  ✗ Force refund failed");
                            
                            $this->sendTelegram('🚨 ERROR: Refund Failed', $order, $trackId, [
                                'error' => 'Force refund failed after max inquiry failures'
                            ]);
                            
                            $stats['failed']++;
                        }
                    } else {
                        $stats['failed']++;
                    }
                    continue;
                }

                Cache::forget($failCountKey);
                $status = $inquiry['status'] ?? null;

                if ($debug) {
                    $this->line("  Zibal Status: {$status}");
                    if (isset($inquiry['paidAt'])) {
                        $this->line("  Paid At: {$inquiry['paidAt']}");
                    }
                }

                if (in_array($status, [1, 2])) {
                    if ($debug) {
                        $this->info("  💰 Payment successful at Zibal!");
                    }

                    if ($order->status != 3) {
                        $verifyResult = $this->attemptVerify($order, $trackId, $zibal);

                        if ($verifyResult) {
                            $this->info("  ✓✓ Verified and order completed!");
                            $stats['verified']++;
                        } else {
                            if ($orderAge >= $refundAfter) {
                                if ($debug) {
                                    $this->warn("  ⚠ Verify failed → refunding to wallet...");
                                }

                                if ($this->refundToWallet($order, $trackId, 'verify_failed')) {
                                    $this->info("  ✓ Refunded to wallet");
                                    
                                    $this->sendTelegram('⚠️ WARNING: Verify Failed', $order, $trackId, [
                                        'reason' => 'Payment successful at Zibal but verify failed',
                                        'zibal_status' => $status,
                                        'order_age' => $orderAge . ' min',
                                        'action' => 'پول به کیف پول برگشت'
                                    ]);
                                    
                                    $stats['refunded']++;
                                } else {
                                    $this->error("  ✗ Refund failed");
                                    
                                    $this->sendTelegram('🚨 ERROR: Critical', $order, $trackId, [
                                        'error' => 'Payment successful but verify and refund both failed'
                                    ]);
                                    
                                    $stats['failed']++;
                                }
                            } else {
                                if ($debug) {
                                    $remaining = $refundAfter - $orderAge;
                                    $this->line("  ⏳ Waiting {$remaining} min before refund");
                                }
                            }
                        }
                    } else {
                        if ($debug) {
                            $this->line("  ✓ Already paid (status=3)");
                        }
                    }
                } 
                else if ($status === -1) {
                    if ($debug) {
                        $this->line("  🚫 Payment not initiated (status: -1)");
                    }

                    if ($orderAge >= $markOldUnused) {
                        $track = PaymentTrack::where('trade_no', $order->trade_no)->first();
                        if ($track && !$track->is_used) {
                            $track->markAsUsed();
                            if ($debug) {
                                $this->line("  ✓ Track marked as used (old + not initiated)");
                            }
                        }

                        if ($checkCancelled && $order->status == 0) {
                            try {
                                $order->status = 2;
                                $order->save();
                                
                                if ($debug) {
                                    $this->info("  ✓ Order marked as cancelled");
                                }
                                
                                $stats['cancelled']++;
                            } catch (\Exception $e) {
                                $this->error("  ✗ Failed to mark as cancelled: " . $e->getMessage());
                                $stats['failed']++;
                            }
                        } else {
                            $stats['skipped']++;
                        }
                    } else {
                        if ($debug) {
                            $remaining = $markOldUnused - $orderAge;
                            $this->line("  ⏳ Waiting {$remaining} min before marking as used");
                        }
                        $stats['skipped']++;
                    }
                } 
                else if ($status === 3) {
                    if ($debug) {
                        $this->line("  🚫 Cancelled by user at gateway (status: 3)");
                    }

                    if ($orderAge >= $markOldUnused) {
                        $track = PaymentTrack::where('trade_no', $order->trade_no)->first();
                        if ($track && !$track->is_used) {
                            $track->markAsUsed();
                            if ($debug) {
                                $this->line("  ✓ Track marked as used (old + cancelled)");
                            }
                        }

                        if ($order->status == 0) {
                            try {
                                $order->status = 2;
                                $order->save();
                                
                                if ($debug) {
                                    $this->info("  ✓ Order marked as cancelled");
                                }
                                
                                $stats['cancelled']++;
                            } catch (\Exception $e) {
                                $this->error("  ✗ Failed to cancel: " . $e->getMessage());
                                $stats['failed']++;
                            }
                        } else {
                            $stats['skipped']++;
                        }
                    } else {
                        if ($debug) {
                            $remaining = $markOldUnused - $orderAge;
                            $this->line("  ⏳ Waiting {$remaining} min before marking as used");
                        }
                        $stats['skipped']++;
                    }
                } 
                else if ($status === 4) {
                    if ($debug) {
                        $this->line("  💳 Payment failed/returned (status: 4)");
                    }

                    if ($orderAge >= $markOldUnused) {
                        $track = PaymentTrack::where('trade_no', $order->trade_no)->first();
                        if ($track && !$track->is_used) {
                            $track->markAsUsed();
                            if ($debug) {
                                $this->line("  ✓ Track marked as used (old + failed)");
                            }
                        }

                        if ($order->status == 0) {
                            try {
                                $order->status = 2;
                                $order->save();
                                
                                if ($debug) {
                                    $this->info("  ✓ Order marked as cancelled");
                                }
                                
                                $stats['cancelled']++;
                            } catch (\Exception $e) {
                                $this->error("  ✗ Failed to cancel: " . $e->getMessage());
                                $stats['failed']++;
                            }
                        } else {
                            $stats['skipped']++;
                        }
                    } else {
                        if ($debug) {
                            $remaining = $markOldUnused - $orderAge;
                            $this->line("  ⏳ Waiting {$remaining} min before marking as used");
                        }
                        $stats['skipped']++;
                    }
                }
                else if ($status === 0) {
                    if ($debug) {
                        $this->line("  ⏳ Payment still pending at Zibal (status: 0)");
                    }

                    if ($orderAge >= $expireAfter && $order->status == 0) {
                        $this->expireOrder($order);
                        $stats['expired']++;
                    }
                } 
                else {
                    if ($debug) {
                        $this->warn("  ⚠ Unknown Zibal status: {$status}");
                    }
                    
                    if ($orderAge >= $refundAfter) {
                        if ($debug) {
                            $this->warn("  ⚠ Unknown status + old order → forcing refund...");
                        }
                        
                        if ($this->refundToWallet($order, $trackId, 'unknown_status')) {
                            $this->info("  ✓ Force refunded to wallet");
                            
                            $this->sendTelegram('⚠️ WARNING: Unknown Status', $order, $trackId, [
                                'reason' => 'Unknown Zibal status',
                                'zibal_status' => $status,
                                'order_age' => $orderAge . ' min',
                                'action' => 'پول به کیف پول برگشت'
                            ]);
                            
                            $stats['refunded']++;
                        } else {
                            $this->error("  ✗ Force refund failed");
                            
                            $this->sendTelegram('🚨 ERROR: Unknown Status', $order, $trackId, [
                                'error' => 'Unknown status and refund failed',
                                'zibal_status' => $status
                            ]);
                            
                            $stats['failed']++;
                        }
                    }
                }

            } catch (\Exception $e) {
                Log::channel('payment')->error('Payment recovery error', [
                    'order_id' => $order->id,
                    'trade_no' => $order->trade_no,
                    'track_id' => $trackId ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                if ($debug) {
                    $this->error("  ✗ Exception: " . $e->getMessage());
                }

                $this->sendTelegram('🚨 ERROR: Exception', $order, $trackId ?? 'N/A', [
                    'error' => $e->getMessage(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]);

                $stats['failed']++;
            }
        }

        if ($debug) {
            $this->info("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📊 Summary:");
            $this->line("  Checked: {$stats['checked']}");
            $this->line("  Verified: {$stats['verified']}");
            $this->line("  Refunded: {$stats['refunded']}");
            $this->line("  Expired: {$stats['expired']}");
            $this->line("  Cancelled: {$stats['cancelled']}");
            $this->line("  Skipped: {$stats['skipped']}");
            $this->line("  Failed: {$stats['failed']}");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        }

        if ($this->notifyAdmin && ($stats['failed'] > 0 || $stats['refunded'] > 0)) {
            $this->sendSummary($stats);
        }

        Cache::put('payment_recovery_last_success', time(), 3600);
        Log::channel('payment')->info('Payment recovery completed', $stats);

        return 0;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Telegram Methods - Using TelegramService like PaymentController
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function sendTelegram(string $title, Order $order, string $trackId, array $details)
    {
        if (!$this->notifyAdmin || !$this->telegramService) {
            return;
        }

        try {
            $user = User::find($order->user_id);
            $email = $user ? $user->email : 'Unknown';

            $message = "{$title}\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "📋 Order: {$order->trade_no}\n";
            $message .= "🆔 Track: {$trackId}\n";
            $message .= "👤 User: {$email}\n";
            $message .= "💰 Amount: " . number_format($order->total_amount) . " تومان\n";
            $message .= "⏰ Age: " . now()->diffInMinutes($order->created_at) . " دقیقه\n";
            
            if (!empty($details)) {
                $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                foreach ($details as $key => $value) {
                    $message .= "• " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
                }
            }
            
            $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "🕐 " . now()->format('Y-m-d H:i:s');

            // Using TelegramService like PaymentController
            $this->telegramService->sendMessageWithAdmin($message);
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('Telegram notification failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function sendSummary(array $stats)
    {
        if (!$this->notifyAdmin || !$this->telegramService) {
            return;
        }

        try {
            $message = "📊 Payment Recovery Summary\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "✅ Verified: {$stats['verified']}\n";
            $message .= "💰 Refunded: {$stats['refunded']}\n";
            $message .= "❌ Failed: {$stats['failed']}\n";
            $message .= "🚫 Cancelled: {$stats['cancelled']}\n";
            $message .= "⏰ Expired: {$stats['expired']}\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "📋 Total: {$stats['checked']}\n";
            $message .= "🕐 " . now()->format('Y-m-d H:i:s');

            // Using TelegramService like PaymentController
            $this->telegramService->sendMessageWithAdmin($message);
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('Telegram summary failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Core Methods - Following PaymentController Pattern
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function attemptVerify(Order $order, string $trackId, ZibalPayment $zibal): bool
    {
        try {
            $verifyResult = $zibal->verify($trackId);

            if ($verifyResult) {
                if ($order->status !== 3) {
                    $order->status = 3;
                    $order->paid_at = time();
                    $order->updated_at = time();
                    $order->save();
                    
                    Log::channel('payment')->info('✓ Order verified in recovery', [
                        'order_id' => $order->id,
                        'trade_no' => $order->trade_no,
                        'track_id' => $trackId,
                    ]);
                }
                
                return true;
            }

            Log::channel('payment')->warning('Verify returned false in recovery', [
                'order_id' => $order->id,
                'track_id' => $trackId,
            ]);

            return false;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Verify attempt failed', [
                'order_id' => $order->id,
                'track_id' => $trackId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function refundToWallet(Order $order, string $trackId, string $reason = 'recovery'): bool
    {
        try {
            DB::beginTransaction();

            $user = User::lockForUpdate()->find($order->user_id);

            if (!$user) {
                throw new \Exception("User not found: {$order->user_id}");
            }

            $oldBalance = $user->balance;
            $user->balance += $order->total_amount;
            $user->save();

            $order->status = 4;
            $order->save();

            $track = PaymentTrack::where('track_id', $trackId)->first();
            if ($track && !$track->is_used) {
                $track->markAsUsed();
            }

            cache()->forget("zibal_track_{$order->trade_no}");

            DB::commit();

            Log::channel('payment')->info('✓ Order refunded to wallet', [
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'track_id' => $trackId,
                'user_id' => $user->id,
                'amount' => $order->total_amount,
                'old_balance' => $oldBalance,
                'new_balance' => $user->balance,
                'reason' => $reason,
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::channel('payment')->error('✗ Refund failed', [
                'order_id' => $order->id,
                'track_id' => $trackId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function expireOrder(Order $order): bool
    {
        try {
            $order->status = 2;
            $order->save();

            $track = PaymentTrack::where('trade_no', $order->trade_no)->first();
            if ($track && !$track->is_used) {
                $track->markAsUsed();
            }

            cache()->forget("zibal_track_{$order->trade_no}");

            Log::channel('payment')->info('Order expired', [
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Expire failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getPaymentConfig(): ?array
    {
        $config = config('v2board.zibal');
        
        if ($config && isset($config['zibal_merchant'])) {
            return $config;
        }

        try {
            $paymentNames = ['ZibalPayment', 'ZibalPay', 'Zibal'];
            
            foreach ($paymentNames as $name) {
                $payment = DB::table('v2_payment')
                    ->where('payment', $name)
                    ->where('enable', 1)
                    ->first();

                if ($payment && $payment->config) {
                    $config = json_decode($payment->config, true);
                    if ($config && isset($config['zibal_merchant'])) {
                        return $config;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to get payment config', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
