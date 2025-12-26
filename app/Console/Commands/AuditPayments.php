<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PaymentTrack;
use Illuminate\Console\Command;

class AuditPayments extends Command
{
    protected $signature = 'payment:audit 
                            {--hours=48 : Check last N hours}
                            {--fix : Attempt automatic resolution}';

    protected $description = 'Audit payment system and identify suspicious orders';

    public function handle()
    {
        $hours = (int) $this->option('hours');
        $fix = $this->option('fix');

        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("🔍 Payment Audit System");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("Checking last {$hours} hours");
        $this->info("Fix mode: " . ($fix ? 'ENABLED' : 'DISABLED'));
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");

        $issues = [];

        // Check 1: Cancelled/expired orders with trackId
        $this->info("1️⃣ Checking cancelled/expired orders with trackId...\n");

        $suspiciousOrders = Order::whereIn('status', [2, 4])
            ->where('created_at', '>=', now()->subHours($hours))
            ->get();

        foreach ($suspiciousOrders as $order) {
            $trackId = cache()->get("zibal_track_{$order->trade_no}");
            $trackFromDb = PaymentTrack::where('order_id', $order->id)->first();

            if ($trackId || $trackFromDb) {
                $this->warn("⚠️ Suspicious Order: {$order->trade_no}");
                $this->line("   Order ID: {$order->id}");
                $this->line("   User ID: {$order->user_id}");
                $this->line("   Amount: " . number_format($order->total_amount));
                $this->line("   Status: {$order->status}");
                $this->line("   TrackId: " . ($trackFromDb ? $trackFromDb->track_id : $trackId));
                $this->line("");

                $issues[] = [
                    'type' => 'suspicious_cancelled',
                    'order_id' => $order->id,
                    'track_id' => $trackFromDb ? $trackFromDb->track_id : $trackId,
                ];
            }
        }

        // Check 2: Unused tracks
        $this->info("\n2️⃣ Checking unused tracks in payment_tracks...\n");

        $unusedTracks = PaymentTrack::where('is_used', false)
            ->where('created_at', '>=', now()->subHours($hours))
            ->get();

        foreach ($unusedTracks as $track) {
            $order = Order::find($track->order_id);

            if ($order) {
                $orderAge = now()->diffInMinutes(\Carbon\Carbon::parse($track->created_at));

                if ($orderAge > 30) {
                    $this->warn("⚠️ Unused TrackId: {$track->track_id}");
                    $this->line("   Order ID: {$order->id}");
                    $this->line("   Order Status: {$order->status}");
                    $this->line("   Amount: " . number_format($track->amount));
                    $this->line("   Age: {$orderAge} minutes");
                    $this->line("");

                    $issues[] = [
                        'type' => 'unused_track',
                        'track_id' => $track->track_id,
                        'order_id' => $order->id,
                    ];
                }
            }
        }

        // Check 3: Pending orders without trackId
        $this->info("\n3️⃣ Checking pending orders without trackId...\n");

        $pendingWithoutTrack = Order::where('status', 0)
            ->where('created_at', '>=', now()->subHours($hours))
            ->where('created_at', '<=', now()->subMinutes(30))
            ->get();

        foreach ($pendingWithoutTrack as $order) {
            $trackId = cache()->get("zibal_track_{$order->trade_no}");
            $trackFromDb = PaymentTrack::where('order_id', $order->id)->first();

            if (!$trackId && !$trackFromDb) {
                $this->warn("⚠️ Pending without TrackId: {$order->trade_no}");
                $this->line("   Order ID: {$order->id}");
                $this->line("   Age: " . now()->diffInMinutes(\Carbon\Carbon::parse($order->created_at)) . " minutes");
                $this->line("");

                $issues[] = [
                    'type' => 'pending_no_track',
                    'order_id' => $order->id,
                ];
            }
        }

        // Summary
        $this->info("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("📊 Summary:");
        $this->line("  Total Issues: " . count($issues));

        $byType = [];
        foreach ($issues as $issue) {
            $type = $issue['type'];
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        foreach ($byType as $type => $count) {
            $this->line("  {$type}: {$count}");
        }

        if ($fix && count($issues) > 0) {
            $this->info("\n🔧 Running payment:check-pending to fix issues...");
            $this->call('payment:check-pending', [
                '--check-cancelled' => true,
                '--check-expired' => true,
                '--refund-after' => 0,
                '--hours' => $hours,
                '--debug' => true,
            ]);
        } else if (count($issues) > 0) {
            $this->warn("\n💡 Run with --fix to attempt automatic resolution");
            $this->line("   php artisan payment:audit --fix");
        }

        $this->info("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return 0;
    }
}
