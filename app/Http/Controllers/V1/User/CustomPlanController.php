<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\CustomPlanService;
use Illuminate\Http\Request;

class CustomPlanController extends Controller
{
    public function catalogue()
    {
        return response(['data' => CustomPlanService::catalogue()]);
    }

    public function quote(Request $request)
    {
        $request->validate(['volume_gb' => 'required|integer|min:1|max:1024',
            'duration_days' => 'required|integer|min:1|max:365', 'pricing_revision' => 'required|string|size:64',
            'coupon_code' => 'nullable|string|max:255']);
        if ($request->boolean('is_gift')) abort(422, __('Tailored gifts are not available'));
        return response(['data' => CustomPlanService::quote((int)$request->user['id'], (int)$request->input('volume_gb'),
            (int)$request->input('duration_days'), $request->input('pricing_revision'), $request->input('coupon_code'))]);
    }

    public function save(Request $request)
    {
        $request->validate(['quote_token' => 'required|string|max:20000', 'use_wallet' => 'sometimes|boolean',
            'coupon_code' => 'nullable|string|max:255']);
        if ($request->boolean('is_gift')) abort(422, __('Tailored gifts are not available'));
        $order = CustomPlanService::save((int)$request->user['id'], $request->input('quote_token'),
            $request->boolean('use_wallet', true), $request->input('coupon_code'));
        return response(['data' => $order->trade_no]);
    }
}
