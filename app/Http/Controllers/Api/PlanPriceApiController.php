<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanPriceApiController extends Controller
{
    private array $periods = ['month', 'quarter', 'half_year', 'year', 'two_year', 'three_year', 'onetime', 'reset'];

    public function index()
    {
        $plans = Plan::all()->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'month_price_usd' => (float) $p->month_price_usd,
            'quarter_price_usd' => (float) $p->quarter_price_usd,
            'half_year_price_usd' => (float) $p->half_year_price_usd,
            'year_price_usd' => (float) $p->year_price_usd,
            'month_price' => (int) $p->month_price,
            'last_exchange_rate' => (int) $p->last_exchange_rate,
        ]);
        return response()->json(['success' => true, 'data' => $plans, 'rate' => ExchangeRateService::getUsdSellPrice()]);
    }

    public function update(Request $request, $id)
    {
        $plan = Plan::find($id);
        if (!$plan) return response()->json(['success' => false], 404);

        $changes = [];
        foreach ($this->periods as $p) {
            if ($request->has($p)) $changes["{$p}_price_usd"] = (float) $request->input($p);
        }
        if ($changes) DB::table('v2_plan')->where('id', $id)->update($changes);
        return response()->json(['success' => true]);
    }

    public function calculate()
    {
        $rate = ExchangeRateService::getUsdSellPriceFresh();
        if (!$rate) return response()->json(['success' => false], 500);

        $updated = 0;
        foreach (Plan::all() as $plan) {
            $changes = [];
            foreach ($this->periods as $p) {
                $usd = (float) $plan->{"{$p}_price_usd"};
                if ($usd > 0) $changes["{$p}_price"] = (int) round($usd * $rate / 10);
            }
            if ($changes) {
                $changes['last_exchange_rate'] = $rate;
                $changes['price_updated_at'] = now();
                DB::table('v2_plan')->where('id', $plan->id)->update($changes);
                $updated++;
            }
        }
        return response()->json(['success' => true, 'rate' => $rate, 'updated' => $updated]);
    }

    public function exchangeRate()
    {
        return response()->json(['rate' => ExchangeRateService::getUsdSellPrice()]);
    }
}
