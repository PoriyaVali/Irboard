<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CardPayment;
use App\Services\CardPaymentService;
use Illuminate\Http\Request;

class CardPaymentController extends Controller
{
    /**
     * لیست پرداخت‌های کارت به کارت
     */
    public function list(Request $request)
    {
        $query = CardPayment::orderBy('created_at', 'DESC');

        // فیلتر وضعیت
        if ($request->input('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->whereNotIn('status', [
                CardPayment::STATUS_EXPIRED,
                CardPayment::STATUS_CANCELLED,
            ]);
        }

        // فیلتر کاربر
        if ($request->input('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // جستجو
        if ($request->input('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('trade_no', 'like', "%{$search}%")
                  ->orWhere('tracking_number', 'like', "%{$search}%");
            });
        }

        $payments = $query->paginate(20);

        // اضافه کردن اطلاعات اضافی
        $payments->getCollection()->transform(function ($payment) {
            $payment->status_label = $payment->getStatusLabel();
            $payment->amount_toman = $payment->expected_amount;
            $payment->actual_amount_toman = $payment->actual_amount ? $payment->actual_amount : null;
            return $payment;
        });

        return response([
            'data' => $payments
        ]);
    }

    /**
     * جزئیات یک پرداخت
     */
    public function detail(Request $request)
    {
        $id = $request->input('id');
        
        $payment = CardPayment::with(['user', 'order'])->find($id);
        
        if (!$payment) {
            abort(404, 'پرداخت یافت نشد');
        }

        $payment->status_label = $payment->getStatusLabel();
        $payment->amount_toman = $payment->expected_amount;

        return response([
            'data' => $payment
        ]);
    }

    /**
     * تأیید کامل
     */
    public function verifyFull(Request $request)
    {
        $id = $request->input('id');
        $payment = CardPayment::find($id);

        if (!$payment) {
            abort(404, 'پرداخت یافت نشد');
        }

        $service = new CardPaymentService();
        $result = $service->verifyFull($payment, $request->user['id']);

        if (!$result['success']) {
            abort(500, $result['message']);
        }

        return response([
            'data' => true,
            'message' => $result['message']
        ]);
    }

    /**
     * تأیید با مبلغ متفاوت
     */
    public function verifyDifferent(Request $request)
    {
        $id = $request->input('id');
        $actualAmount = $request->input('actual_amount'); // به ریال

        if (!$actualAmount || $actualAmount <= 0) {
            abort(400, 'مبلغ نامعتبر است');
        }

        $payment = CardPayment::find($id);

        if (!$payment) {
            abort(404, 'پرداخت یافت نشد');
        }

        $service = new CardPaymentService();
        $result = $service->verifyWithDifferentAmount($payment, $actualAmount, $request->user['id']);

        if (!$result['success']) {
            abort(500, $result['message']);
        }

        return response([
            'data' => true,
            'message' => $result['message']
        ]);
    }

    /**
     * رد پرداخت
     */
    public function reject(Request $request)
    {
        $id = $request->input('id');
        $reason = $request->input('reason', '');

        $payment = CardPayment::find($id);

        if (!$payment) {
            abort(404, 'پرداخت یافت نشد');
        }

        $service = new CardPaymentService();
        $result = $service->reject($payment, $request->user['id'], $reason);

        if (!$result['success']) {
            abort(500, $result['message']);
        }

        return response([
            'data' => true,
            'message' => $result['message']
        ]);
    }

    /**
     * آمار کلی
     */
    public function stats()
    {
        $stats = [
            'pending' => CardPayment::where('status', CardPayment::STATUS_PENDING)->count(),
            'claimed' => CardPayment::where('status', CardPayment::STATUS_CLAIMED)->count(),
            'verified' => CardPayment::whereIn('status', [
                CardPayment::STATUS_VERIFIED_FULL,
                CardPayment::STATUS_VERIFIED_PARTIAL,
                CardPayment::STATUS_VERIFIED_EXCESS
            ])->count(),
            'rejected' => CardPayment::where('status', CardPayment::STATUS_REJECTED)->count(),
            'expired' => CardPayment::where('status', CardPayment::STATUS_EXPIRED)->count(),
            'total_verified_amount' => CardPayment::whereIn('status', [
                CardPayment::STATUS_VERIFIED_FULL,
                CardPayment::STATUS_VERIFIED_PARTIAL,
                CardPayment::STATUS_VERIFIED_EXCESS
            ])->sum('actual_amount'),
        ];

        $stats['total_verified_amount_toman'] = $stats['total_verified_amount'];

        return response([
            'data' => $stats
        ]);
    }

    /**
     * رسیدِ عکسیِ ارسال‌شده از رباتِ تلگرام را برمی‌گرداند (base64) تا اپِ ادمین
     * بتواند نمایشش دهد. عکس روی سرورِ تلگرام است؛ اینجا با getFile آن را می‌گیریم
     * و پروکسی می‌کنیم تا توکنِ ربات به کلاینت لو نرود.
     */
    public function receipt(Request $request)
    {
        $payment = CardPayment::find($request->input('id'));
        if (!$payment) {
            abort(404, 'پرداخت یافت نشد');
        }
        if (empty($payment->receipt_file_id)) {
            return response(['data' => null]); // این پرداخت رسیدِ عکسی ندارد
        }
        $token = config('v2board.telegram_bot_token');
        if (!$token) {
            abort(500, 'توکن ربات تلگرام تنظیم نشده است');
        }
        try {
            $getFile = \Illuminate\Support\Facades\Http::timeout(15)
                ->get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $payment->receipt_file_id]);
            $filePath = $getFile->json('result.file_path');
            if (!$filePath) {
                abort(500, 'دریافتِ رسید از تلگرام ناموفق بود (شاید منقضی شده)');
            }
            $bin = \Illuminate\Support\Facades\Http::timeout(30)
                ->get("https://api.telegram.org/file/bot{$token}/{$filePath}");
            if (!$bin->successful()) {
                abort(500, 'دانلودِ رسید ناموفق بود');
            }
            $mime = $bin->header('Content-Type') ?: 'image/jpeg';
            return response([
                'data' => [
                    'mime' => $mime,
                    'image' => base64_encode($bin->body()),
                ]
            ]);
        } catch (\Throwable $e) {
            abort(500, 'خطا در دریافتِ رسید: ' . $e->getMessage());
        }
    }
}
