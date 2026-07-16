<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Http\Requests\Admin\PlanSort;
use App\Http\Requests\Admin\PlanUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\BotSetting;
use App\Services\ExchangeRateService;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $counts = PlanService::countActiveUsers();
        $plans = Plan::orderBy('sort', 'ASC')->get();
        foreach ($plans as $k => $v) {
            $plans[$k]->count = 0;
            foreach ($counts as $kk => $vv) {
                if ($plans[$k]->id === $counts[$kk]->plan_id) $plans[$k]->count = $counts[$kk]->count;
            }
        }
        return response([
            'data' => $plans
        ]);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        $params = $this->calculateTomanPrices($params);
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                abort(500, '该订阅不存在');
            }
            DB::beginTransaction();
            // update user group id and transfer
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'device_limit' => $params['device_limit'],
                        'speed_limit' => $params['speed_limit']
                    ]);
                }
                $plan->update($params);

                $usdPriceFields = array_filter([
                    'month_price_usd' => $params['month_price_usd'] ?? null,
                    'quarter_price_usd' => $params['quarter_price_usd'] ?? null,
                    'half_year_price_usd' => $params['half_year_price_usd'] ?? null,
                    'year_price_usd' => $params['year_price_usd'] ?? null,
                    'two_year_price_usd' => $params['two_year_price_usd'] ?? null,
                    'three_year_price_usd' => $params['three_year_price_usd'] ?? null,
                    'onetime_price_usd' => $params['onetime_price_usd'] ?? null,
                    'reset_price_usd' => $params['reset_price_usd'] ?? null,
                ], fn($v) => $v !== null);

                if (!empty($usdPriceFields)) {
                    $usdPriceFields['updated_at'] = now();
                    DB::table('v2_plan_prices')->updateOrInsert(
                        ['plan_id' => $plan->id],
                        $usdPriceFields
                    );
                }
            } catch (\Exception $e) {
                DB::rollBack();
                abort(500, '保存失败');
            }
            DB::commit();
            return response([
                'data' => true
            ]);
        }
        if (!Plan::create($params)) {
            abort(500, '创建失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            abort(500, '该订阅下存在订单无法删除');
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            abort(500, '该订阅下存在用户无法删除');
        }
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                abort(500, '该订阅ID不存在');
            }
        }
        return response([
            'data' => $plan->delete()
        ]);
    }

    public function update(PlanUpdate $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'carry_over_days'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            abort(500, '该订阅不存在');
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function sort(PlanSort $request)
    {
        DB::beginTransaction();
        foreach ($request->input('plan_ids') as $k => $v) {
            if (!Plan::find($v)->update(['sort' => $k + 1])) {
                DB::rollBack();
                abort(500, '保存失败');
            }
        }
        DB::commit();
        return response([
            'data' => true
        ]);
    }

    /**
     * محاسبه قیمت‌های تومانی از دلاری
     */
    private function calculateTomanPrices(array $params): array
    {
        if (BotSetting::get('usd_pricing_enabled', '0') !== '1') {
            return $params;
        }

        $rate = ExchangeRateService::getUsdSellPriceFresh();
        if (!$rate) {
            return $params;
        }

        $usdFields = [
            'month_price_usd' => 'month_price',
            'quarter_price_usd' => 'quarter_price',
            'half_year_price_usd' => 'half_year_price',
            'year_price_usd' => 'year_price',
            'two_year_price_usd' => 'two_year_price',
            'three_year_price_usd' => 'three_year_price',
            'onetime_price_usd' => 'onetime_price',
            'reset_price_usd' => 'reset_price',
        ];

        foreach ($usdFields as $usdField => $tomanField) {
            if (isset($params[$usdField]) && $params[$usdField] !== null && $params[$usdField] !== '') {
                $rawPrice = floatval($params[$usdField]) * $rate;
                $params[$tomanField] = (int) floor($rawPrice / 1000) * 1000;
            }
        }

        return $params;
    }
}