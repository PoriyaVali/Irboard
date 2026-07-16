<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardPayment extends Model
{
    protected $table = 'v2_card_payments';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'claimed_at' => 'timestamp',
        'verified_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'duplicate_warning' => 'boolean',
    ];

    // وضعیت‌ها
    const STATUS_PENDING = 'pending';
    const STATUS_CLAIMED = 'claimed';
    const STATUS_VERIFIED_FULL = 'verified_full';
    const STATUS_VERIFIED_PARTIAL = 'verified_partial';
    const STATUS_VERIFIED_EXCESS = 'verified_excess';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * رابطه با کاربر
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * رابطه با سفارش
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * آیا منقضی شده؟
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_PENDING && time() > $this->expires_at;
    }

    /**
     * آیا قابل ادعا است؟
     */
    public function canBeClaimed(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }

    /**
     * آیا منتظر تأیید ادمین است؟
     */
    public function isPendingVerification(): bool
    {
        return $this->status === self::STATUS_CLAIMED;
    }

    /**
     * تولید fingerprint برای جلوگیری از تقلب
     */
    public static function generateAmountFingerprint(int $amount): string
    {
        // هر 10 دقیقه یک window
        $timeWindow = floor(time() / 600);
        return hash('sha256', $amount . '_' . $timeWindow);
    }

    /**
     * تولید fingerprint شماره پیگیری
     */
    public static function generateTrackingFingerprint(string $trackingNumber): string
    {
        return hash('sha256', trim($trackingNumber));
    }

    /**
     * بررسی تکراری بودن شماره پیگیری
     */
    public static function isTrackingNumberUsed(string $trackingNumber, ?int $excludeId = null): bool
    {
        $fingerprint = self::generateTrackingFingerprint($trackingNumber);
        
        $query = self::where('tracking_fingerprint', $fingerprint)
            ->whereIn('status', [
                self::STATUS_CLAIMED,
                self::STATUS_VERIFIED_FULL,
                self::STATUS_VERIFIED_PARTIAL,
                self::STATUS_VERIFIED_EXCESS
            ]);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }

    /**
     * بررسی واریزهای مشابه (احتمال تقلب)
     */
    public static function checkDuplicateAmount(int $amount, ?int $excludeId = null): bool
    {
        $fingerprint = self::generateAmountFingerprint($amount);
        
        $query = self::where('amount_fingerprint', $fingerprint)
            ->whereIn('status', [self::STATUS_CLAIMED, self::STATUS_VERIFIED_FULL]);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }

    /**
     * زمان باقیمانده (ثانیه)
     */
    public function getRemainingSeconds(): int
    {
        if ($this->status !== self::STATUS_PENDING) {
            return 0;
        }
        return max(0, $this->expires_at - time());
    }

    /**
     * وضعیت به فارسی
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'در انتظار واریز',
            self::STATUS_CLAIMED => 'در انتظار تأیید',
            self::STATUS_VERIFIED_FULL => 'تأیید شده',
            self::STATUS_VERIFIED_PARTIAL => 'واریز ناقص',
            self::STATUS_VERIFIED_EXCESS => 'تأیید شده (مبلغ اضافی)',
            self::STATUS_REJECTED => 'رد شده',
            self::STATUS_EXPIRED => 'منقضی شده',
            self::STATUS_CANCELLED => 'لغو شده',
            default => 'نامشخص'
        };
    }
}
