<?php

namespace App\Console\Commands;

use App\Models\PaymentTrack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupPaymentTracks extends Command
{
    protected $signature = 'payment:cleanup-tracks 
                            {--hours=48 : Delete tracks older than N hours (minimum: 24)}
                            {--only-used : Only delete used tracks (RECOMMENDED)}
                            {--force-all : Delete ALL tracks including unused (DANGEROUS)}
                            {--dry-run : Show what would be deleted without deleting}
                            {--show-stuck : Show stuck tracks that need attention}
                            {--force : Skip confirmation prompt}
                            {--debug : Show detailed output}';

    protected $description = 'Cleanup old payment tracks from database (v2.1)';

    public function handle()
    {
        $hours = (int) $this->option('hours');
        $onlyUsed = !$this->option('force-all');
        $dryRun = $this->option('dry-run');
        $showStuck = $this->option('show-stuck');
        $force = $this->option('force');
        $debug = $this->option('debug');

        if ($debug) {
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("🧹 Payment Tracks Cleanup v2.1");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        }

        if ($hours < 24) {
            $this->error("❌ Minimum 24 hours required. Current: {$hours}h");
            return 1;
        }

        if (!$onlyUsed && !$force) {
            $this->error("⚠️  DANGER: --force-all will delete ALL tracks!");
            if (!$this->confirm('Are you ABSOLUTELY SURE?', false)) {
                $this->info("Cancelled - using safe mode");
                $onlyUsed = true;
            }
        }

        $stats = PaymentTrack::getStatistics();
        
        if ($debug) {
            $this->info("\n📊 Statistics:");
            $this->line("  Total: " . number_format($stats['total']));
            $this->line("  Used: " . number_format($stats['used']));
            $this->line("  Unused: " . number_format($stats['unused']));
            if (isset($stats['unused_old_48h']) && $stats['unused_old_48h'] > 0) {
                $this->warn("  ⚠️  Unused >48h: " . $stats['unused_old_48h']);
            }
        }

        if ($showStuck) {
            $stuckTracks = PaymentTrack::getStuckTracks($hours);
            
            if ($stuckTracks->count() > 0) {
                $this->warn("\n⚠️  Found {$stuckTracks->count()} stuck tracks:");
                foreach ($stuckTracks->take(5) as $track) {
                    $this->line("  - Track {$track->track_id} ({$track->created_at->diffForHumans()})");
                }
            } else {
                $this->info("\n✓ No stuck tracks");
            }
            
            if ($dryRun) return 0;
        }

        $query = PaymentTrack::where('created_at', '<', now()->subHours($hours));
        
        if ($onlyUsed) {
            $query->where('is_used', true);
        }
        
        $toDelete = $query->count();

        if ($toDelete == 0) {
            $this->info("✓ No tracks to delete");
            return 0;
        }

        $this->info("\n🔍 Deletion Plan:");
        $this->line("  Older than: {$hours}h");
        $this->line("  Mode: " . ($onlyUsed ? '✅ Only used (SAFE)' : '❌ All tracks (DANGEROUS)'));
        $this->line("  Will delete: " . number_format($toDelete));

        if ($dryRun) {
            $this->warn("\n🔍 DRY RUN - Nothing deleted");
            $samples = $query->limit(3)->get();
            foreach ($samples as $track) {
                $status = $track->is_used ? 'Used' : 'Unused';
                $this->line("  - [{$status}] {$track->track_id}");
            }
            return 0;
        }

        if (!$force && !$this->confirm("\nProceed?", $onlyUsed)) {
            $this->info("Cancelled");
            return 0;
        }

        $this->info("\n🗑️  Cleaning...");
        
        try {
            $deleted = PaymentTrack::cleanup($hours, $onlyUsed);

            $this->info("\n✓ Completed! Deleted: " . number_format($deleted));
            
            if ($debug) {
                $statsAfter = PaymentTrack::getStatistics();
                $this->line("  Remaining: " . number_format($statsAfter['total']));
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("\n✗ Failed: " . $e->getMessage());
            return 1;
        }
    }
}
