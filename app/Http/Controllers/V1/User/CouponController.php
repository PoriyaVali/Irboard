<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function check(Request $request)
    {
        if (empty($request->input('code'))) {
            abort(500, __('Coupon cannot be empty'));
        }
        $couponService = new CouponService($request->input('code'));
        $couponService->setPlanId($request->input('plan_id'));
        $couponService->setUserId($request->user['id']);
        $couponService->setPeriod($request->input('period'));
        $couponService->check();
        return response([
            'data' => $couponService->getCoupon(),
            'is_gift' => $couponService->isPurchasedGift()
        ]);
    }
}
