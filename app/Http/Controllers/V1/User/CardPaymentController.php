<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\CardPayment;
use App\Models\Order;
use App\Models\User;
use App\Services\CardPaymentService;
use App\Services\CardReceiptStore;
use App\Services\OrderService;
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
                'has_receipt' => !empty($cardPayment->receipt_file_id),
            ]
        ]);
    }

    /**
     * ثبت ادعای واریز توسط کاربر
     */
    public function claim(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $trackingNumber = trim((string)$request->input('tracking_number', ''));
        $receiptData = $request->input('receipt');

        // اعتبارسنجی
        if (empty($tradeNo)) {
            abort(400, 'شماره سفارش الزامی است');
        }

        // A photo of the receipt stands in for the tracking number: many
        // banking apps put the number only on the receipt image, and the
        // admin reads it from there. One of the two is required.
        $receipt = ($receiptData !== null && $receiptData !== '') ? CardReceiptStore::decode($receiptData) : null;
        if ($trackingNumber === '' && !$receipt) {
            abort(400, 'شماره پیگیری یا تصویر رسید را وارد کنید');
        }

        if ($trackingNumber !== '') {
            // بررسی طول شماره پیگیری
            if (strlen($trackingNumber) < 4 || strlen($trackingNumber) > 30) {
                abort(400, 'شماره پیگیری نامعتبر است');
            }

            // فقط عدد مجاز است
            if (!preg_match('/^\d+$/', $trackingNumber)) {
                abort(400, 'شماره پیگیری باید فقط شامل اعداد باشد');
            }
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
        if ($trackingNumber !== '' && CardPayment::isTrackingNumberUsed($trackingNumber, $cardPayment->id)) {
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

        // The order must still be waiting for this payment. A pending card
        // record could outlive its order (cancelled, or replaced by a new one),
        // and a claim on it then paid for an order whose wallet part had
        // already been given back.
        $order = Order::find($cardPayment->order_id);
        if (!$order || (int)$order->status !== 0) {
            abort(400, 'سفارش این پرداخت دیگر در انتظار پرداخت نیست');
        }

        $now = time();

        // The file first, so the claim names it; removed again if the claim
        // loses the race below.
        $receiptId = $receipt ? CardReceiptStore::save($cardPayment, $receipt[0], $receipt[1]) : null;

        // بروزرسانی رکورد
        // Only a record that is still pending turns into a claim, decided in
        // the UPDATE itself: a cancel or a second claim racing this one cannot
        // both succeed.
        $claimed = CardPayment::where('id', $cardPayment->id)
            ->where('status', CardPayment::STATUS_PENDING)
            ->update([
                'status' => CardPayment::STATUS_CLAIMED,
                'tracking_number' => $trackingNumber !== '' ? $trackingNumber : null,
                'tracking_fingerprint' => $trackingNumber !== '' ? CardPayment::generateTrackingFingerprint($trackingNumber) : null,
                'receipt_file_id' => $receiptId ?? $cardPayment->receipt_file_id,
                'claimed_at' => $now,
                'claim_ip' => $request->ip(),
                'claim_user_agent' => $request->header('User-Agent'),
                'duplicate_warning' => $duplicateWarning ? 1 : 0,
                'updated_at' => $now,
            ]);
        if (!$claimed) {
            CardReceiptStore::delete($receiptId);
            abort(400, 'این پرداخت قابل ثبت نیست');
        }
        if ($receiptId !== null && $receiptId !== $cardPayment->receipt_file_id) {
            CardReceiptStore::delete($cardPayment->receipt_file_id);
        }
        $cardPayment = CardPayment::find($cardPayment->id);

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
                'status_label' => $cardPayment->getStatusLabel(),
                'receipt' => !empty($cardPayment->receipt_file_id),
            ]
        ]);
    }

    /**
     * A receipt image for a payment that is not settled yet: before the claim
     * (it is then sent with the claim) or after it, while the admin has not
     * decided. A new image replaces the earlier one. Once claimed, the admins
     * get it on Telegram as well.
     */
    public function attachReceipt(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        if (empty($tradeNo)) {
            abort(400, 'شماره سفارش الزامی است');
        }
        [$bytes, $ext] = CardReceiptStore::decode($request->input('receipt'));

        $cardPayment = CardPayment::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$cardPayment) {
            abort(404, 'پرداخت یافت نشد');
        }
        $open = $cardPayment->status === CardPayment::STATUS_CLAIMED
            || ($cardPayment->status === CardPayment::STATUS_PENDING && !$cardPayment->isExpired());
        if (!$open) {
            abort(400, 'برای این پرداخت دیگر نمی‌توان رسید فرستاد');
        }

        $previous = $cardPayment->receipt_file_id;
        $receiptId = CardReceiptStore::save($cardPayment, $bytes, $ext);
        // Only while still unsettled, decided in the UPDATE itself, so an
        // admin's decision in between is not followed by a stray receipt.
        $updated = CardPayment::where('id', $cardPayment->id)
            ->whereIn('status', [CardPayment::STATUS_PENDING, CardPayment::STATUS_CLAIMED])
            ->update(['receipt_file_id' => $receiptId, 'updated_at' => time()]);
        if (!$updated) {
            CardReceiptStore::delete($receiptId);
            abort(400, 'برای این پرداخت دیگر نمی‌توان رسید فرستاد');
        }
        if (CardReceiptStore::isLocal($previous)) {
            CardReceiptStore::delete($previous);
        }
        $cardPayment = CardPayment::find($cardPayment->id);

        if ($cardPayment->status === CardPayment::STATUS_CLAIMED) {
            try {
                (new CardPaymentService())->sendReceiptToAdmins($cardPayment);
            } catch (\Throwable $e) {
                Log::channel('payment')->error('Failed to send the receipt to admins', [
                    'payment_id' => $cardPayment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response(['data' => ['receipt' => true]]);
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

        // Claim-or-cancel is decided on the locked row, so a claim sent at the
        // same moment cannot be overwritten by this cancel (or the reverse).
        $cancelled = \Illuminate\Support\Facades\DB::transaction(function () use ($cardPayment) {
            $locked = CardPayment::lockForUpdate()->find($cardPayment->id);
            if (!$locked || $locked->status !== CardPayment::STATUS_PENDING) {
                return false;
            }
            $locked->status = CardPayment::STATUS_CANCELLED;
            $locked->updated_at = time();
            $locked->save();
            return true;
        });
        if (!$cancelled) {
            abort(400, 'این پرداخت قابل لغو نیست');
        }

        // لغو سفارش
        // Through OrderService, which gives back what the order took from the
        // wallet - the bare status write kept it.
        $order = Order::find($cardPayment->order_id);
        if ($order && $order->status === 0) {
            (new OrderService($order))->cancel();
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
