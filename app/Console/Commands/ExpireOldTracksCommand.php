<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireOldTracksCommand extends Command
{
    protected $signature = 'payment:expire-old-tracks {--hours=48 : Hours after which to expire tracks}';
    protected $description = 'Expire old payment tracks';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        
        try {
            if (!class_exists('\App\Models\PaymentTrack')) {
                Log::warning('⚠️ PaymentTrack model not found');
                $this->warn('PaymentTrack model not found');
                return self::FAILURE;
            }
            
            $expired = \App\Models\PaymentTrack::expireOld($hours);
            Log::info('✓ Old tracks expired', ['count' => $expired ?? 0]);
            $this->info("Expired: {$expired}");
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error('✗ Expire old tracks failed', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
