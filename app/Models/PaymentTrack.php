<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class PaymentTrack extends Model
{
    protected $table = 'payment_tracks';

    protected $fillable = [
        'track_id',
        'trade_no',
        'order_id',
        'user_id',
        'amount',
        'payment_method',
        'is_used',
        'used_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'order_id' => 'integer',
        'user_id' => 'integer',
        'is_used' => 'boolean',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function store(
        string $trackId, 
        int $orderId, 
        int $userId, 
        int $amount, 
        string $method = 'zibal',
        ?string $tradeNo = null
    ): self
    {
        try {
            $track = self::create([
                'track_id' => $trackId,
                'trade_no' => $tradeNo,
                'order_id' => $orderId,
                'user_id' => $userId,
                'amount' => $amount,
                'payment_method' => $method,
                'is_used' => false,
            ]);

            Log::channel('payment')->info('Payment track stored', [
                'track_id' => $trackId,
                'trade_no' => $tradeNo,
                'order_id' => $orderId,
                'user_id' => $userId,
                'amount' => $amount,
            ]);

            return $track;
        } catch (\Exception $e) {
            Log::channel('payment')->error('Failed to store payment track', [
                'track_id' => $trackId,
                'trade_no' => $tradeNo,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markAsUsed(): bool
    {
        try {
            return $this->update([
                'is_used' => true,
                'used_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::channel('payment')->error('Failed to mark track as used', [
                'track_id' => $this->track_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Expire old unused payment tracks (mark as used without deleting)
     * Used to prevent old tracks from being checked repeatedly in recovery
     * 
     * @param int $hoursOld Tracks older than this will be expired
     * @return int Number of expired tracks
     */
    public static function expireOld(int $hoursOld = 48): int
    {
        try {
            $cutoffTime = now()->subHours($hoursOld);
            
            $expired = self::where('is_used', false)
                ->where('created_at', '<', $cutoffTime)
                ->update([
                    'is_used' => true,
                    'used_at' => now(),
                ]);
            
            if ($expired > 0) {
                Log::channel('payment')->info('✓ Old payment tracks expired', [
                    'count' => $expired,
                    'hours_old' => $hoursOld,
                    'cutoff_time' => $cutoffTime->format('Y-m-d H:i:s'),
                ]);
            }
            
            return $expired;
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('✗ Expire old tracks failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 0;
        }
    }

    public static function isValid(string $trackId): bool
    {
        return self::where('track_id', $trackId)
            ->where('is_used', false)
            ->exists();
    }

    public static function getByTrackId(string $trackId): ?self
    {
        return self::where('track_id', $trackId)->first();
    }

    public static function getByTradeNo(string $tradeNo): ?self
    {
        return self::where('trade_no', $tradeNo)->first();
    }

    public static function isUsed(string $trackId): bool
    {
        return self::where('track_id', $trackId)
            ->where('is_used', true)
            ->exists();
    }

    public static function cleanup(int $hoursOld = 48, bool $onlyUsed = true): int
    {
        try {
            $cutoffTime = now()->subHours($hoursOld);
            
            $query = self::where('created_at', '<', $cutoffTime);
            
            if ($onlyUsed) {
                $query->where('is_used', true);
            }
            
            $count = $query->count();
            
            if ($count > 0) {
                $deleted = $query->delete();
                
                Log::channel('payment')->info('✓ Payment tracks cleanup completed', [
                    'deleted' => $deleted,
                    'hours_old' => $hoursOld,
                    'only_used' => $onlyUsed,
                    'cutoff_time' => $cutoffTime->format('Y-m-d H:i:s'),
                ]);
                
                return $deleted;
            }
            
            return 0;
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('✗ Cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 0;
        }
    }

    public static function cleanupUsedTracks(int $hoursOld = 48): int
    {
        return self::cleanup($hoursOld, true);
    }

    public static function forceCleanupAll(int $hoursOld = 48): int
    {
        Log::channel('payment')->warning('⚠️ FORCE CLEANUP initiated', [
            'hours_old' => $hoursOld,
        ]);
        
        return self::cleanup($hoursOld, false);
    }

    public static function getStatistics(): array
    {
        $stats = [
            'total' => self::count(),
            'used' => self::where('is_used', true)->count(),
            'unused' => self::where('is_used', false)->count(),
            'today' => self::whereDate('created_at', today())->count(),
            'last_24h' => self::where('created_at', '>=', now()->subHours(24))->count(),
        ];
        
        $stats['unused_old_48h'] = self::where('is_used', false)
            ->where('created_at', '<', now()->subHours(48))
            ->count();
        
        $stats['unused_old_72h'] = self::where('is_used', false)
            ->where('created_at', '<', now()->subHours(72))
            ->count();
            
        return $stats;
    }

    public static function getStuckTracks(int $hoursOld = 48): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('is_used', false)
            ->where('created_at', '<', now()->subHours($hoursOld))
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public static function countByTimeRange(int $hours = 24): array
    {
        $cutoff = now()->subHours($hours);
        
        return [
            'total' => self::where('created_at', '>=', $cutoff)->count(),
            'used' => self::where('created_at', '>=', $cutoff)
                ->where('is_used', true)
                ->count(),
            'unused' => self::where('created_at', '>=', $cutoff)
                ->where('is_used', false)
                ->count(),
        ];
    }

    public static function getTracksNeedingAttention(int $hoursOld = 48, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('is_used', false)
            ->where('created_at', '<', now()->subHours($hoursOld))
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get(['id', 'track_id', 'trade_no', 'user_id', 'amount', 'created_at']);
    }
}
