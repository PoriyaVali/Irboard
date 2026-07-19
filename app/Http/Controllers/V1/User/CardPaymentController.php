<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\CardPayment;
use App\Models\Order;
use App\Models\User;
use App\Services\CardPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CardPaymentController extends Controller
{
    /**
     * دریافت اطلاعات پرداخت کارت به کارت
     */
    public function getInfo(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        
        if (empty($tradeNo)) {
            abort(400, 'شماره سفارش الزامی است');
        }

        $cardPayment = CardPayment::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();

        if (!$cardPayment) {
            abort(404, 'پرداخت یافت نشد');
        }

        // بررسی انقضا
        if ($cardPayment->isExpired() && $cardPayment->status === CardPayment::STATUS_PENDING) {
            $cardPayment->status = CardPayment::STATUS_EXPIRED;
            $cardPayment->updated_at = time();
            $cardPayment->save();
        }

        return response([
            'data' => [
                'id' => $cardPayment->id,
                'trade_no' => $cardPayment->trade_no,
                'card_number' => $this->formatCardNumber($cardPayment->card_number),
                'card_holder' => $cardPayment->card_holder,
                'amount' => $cardPayment->expected_amount,
                'amount_toman' => $cardPayment->expected_amount,
                'status' => $cardPayment->status,
                'status_label' => $cardPayment->getStatusLabel(),
                'expires_at' => $cardPayment->expires_at,
                'remaining_seconds' => $cardPayment->getRemainingSeconds(),
                'claimed_at' => $cardPayment->claimed_at,
                'tracking_number' => $cardPayment->tracking_number,
                'reject_reason' => $cardPayment->reject_reason,
                'can_claim' => $cardPayment->canBeClaimed(),
            ]
        ]);
    }

    /**
     * ثبت ادعای واریز توسط کاربر
     */
    public function claim(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $trackingNumber = $request->input('tracking_number');

        // اعتبارسنجی
        if (empty($tradeNo)) {
            abort(400, 'شماره سفارش الزامی است');
        }

        if (empty($trackingNumber)) {
            abort(400, 'شماره پیگیری الزامی است');
        }

        $trackingNumber = trim($trackingNumber);
        
        // بررسی طول شماره پیگیری
        if (strlen($trackingNumber) < 4 || strlen($trackingNumber) > 30) {
            abort(400, 'شماره پیگیری نامعتبر است');
        }

        // فقط عدد مجاز است
        if (!preg_match('/^\d+$/', $trackingNumber)) {
            abort(400, 'شماره پیگیری باید فقط شامل اعداد باشد');
        }

        $cardPayment = CardPayment::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();

        if (!$cardPayment) {
            abort(404, 'پرداخت یافت نشد');
        }

        // بررسی وضعیت
        if (!$cardPayment->canBeClaimed()) {
            if ($cardPayment->isExpired()) {
                abort(400, 'مهلت پرداخت به پایان رسیده است');
            }
            abort(400, 'این پرداخت قابل ثبت نیست');
        }

        // بررسی شماره پیگیری تکراری
        if (CardPayment::isTrackingNumberUsed($trackingNumber, $cardPayment->id)) {
            Log::channel('payment')->warning('Duplicate tracking number attempted', [
                'tracking_number' => $trackingNumber,
                'payment_id' => $cardPayment->id,
                'user_id' => $request->user['id']
            ]);
            abort(400, 'این شماره پیگیری قبلاً استفاده شده است');
        }

        // بررسی واریز مشابه (هشدار تقلب)
        $duplicateWarning = CardPayment::checkDuplicateAmount(
            $cardPayment->expected_amount, 
            $cardPayment->id
        );

        $now = time();

        // بروزرسانی رکورد
        $cardPayment->status = CardPayment::STATUS_CLAIMED;
        $cardPayment->tracking_number = $trackingNumber;
        $cardPayment->tracking_fingerprint = CardPayment::generateTrackingFingerprint($trackingNumber);
        $cardPayment->claimed_at = $now;
        $cardPayment->claim_ip = $request->ip();
        $cardPayment->claim_user_agent = $request->header('User-Agent');
        $cardPayment->duplicate_warning = $duplicateWarning;
        $cardPayment->updated_at = $now;
        $cardPayment->save();

        Log::channel('payment')->info('Card payment claimed', [
            'payment_id' => $cardPayment->id,
            'trade_no' => $tradeNo,
            'tracking_number' => $trackingNumber,
            'user_id' => $request->user['id'],
            'duplicate_warning' => $duplicateWarning
        ]);

        // ارسال نوتیفیکیشن به ادمین
        try {
            $service = new CardPaymentService();
            $service->sendAdminNotification($cardPayment);
        } catch (\Exception $e) {
            Log::channel('payment')->error('Failed to send admin notification', [
                'payment_id' => $cardPayment->id,
                'error' => $e->getMessage()
            ]);
        }

        return response([
            'data' => [
                'success' => true,
                'message' => 'درخواست شما ثبت شد. پس از تأیید ادمین، سفارش فعال می‌شود.',
                'status' => $cardPayment->status,
                'status_label' => $cardPayment->getStatusLabel()
            ]
        ]);
    }

    /**
     * لغو پرداخت توسط کاربر
     */
    public function cancel(Request $request)
    {
        $tradeNo = $request->input('trade_no');

        if (empty($tradeNo)) {
            abort(400, 'شماره سفارش الزامی است');
        }

        $cardPayment = CardPayment::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();

        if (!$cardPayment) {
            abort(404, 'پرداخت یافت نشد');
        }

        // فقط در وضعیت pending می‌توان لغو کرد
        if ($cardPayment->status !== CardPayment::STATUS_PENDING) {
            abort(400, 'این پرداخت قابل لغو نیست');
        }

        $cardPayment->status = CardPayment::STATUS_CANCELLED;
        $cardPayment->updated_at = time();
        $cardPayment->save();

        // لغو سفارش
        $order = Order::find($cardPayment->order_id);
        if ($order && $order->status === 0) {
            $order->status = 2; // cancelled
            $order->updated_at = time();
            $order->save();
        }

        Log::channel('payment')->info('Card payment cancelled by user', [
            'payment_id' => $cardPayment->id,
            'trade_no' => $tradeNo,
            'user_id' => $request->user['id']
        ]);

        return response([
            'data' => [
                'success' => true,
                'message' => 'پرداخت لغو شد'
            ]
        ]);
    }

    /**
     * فرمت کردن شماره کارت
     */
    private function formatCardNumber(string $cardNumber): string
    {
        $clean = preg_replace('/\D/', '', $cardNumber);
        return implode('-', str_split($clean, 4));
    }
}
