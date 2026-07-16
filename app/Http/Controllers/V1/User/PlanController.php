<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user['id']);
        if ($request->input('id')) {
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) {
                abort(500, __('Subscription plan does not exist'));
            }
            if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
                abort(500, __('Subscription plan does not exist'));
            }
            
            $plan = $this->addUsdPrices($plan);
            
            return response([
                'data' => $plan
            ]);
        }

        $counts = PlanService::countActiveUsers();
        $plans = Plan::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get();
        
        foreach ($plans as $k => $v) {
            if ($plans[$k]->capacity_limit === NULL) continue;
            if (!isset($counts[$plans[$k]->id])) continue;
            $plans[$k]->capacity_limit = $plans[$k]->capacity_limit - $counts[$plans[$k]->id]->count;
        }
        
        $plans = $this->addUsdPricesToCollection($plans);
        
        return response([
            'data' => $plans
        ]);
    }
    
    /**
     * اضافه کردن قیمت‌های دلاری به یک پلن
     */
    private function addUsdPrices($plan)
    {
        $usdPrices = DB::table('v2_plan_prices')
            ->where('plan_id', $plan->id)
            ->first();
        
        if ($usdPrices) {
            $plan->month_price_usd = $usdPrices->month_price_usd;
            $plan->quarter_price_usd = $usdPrices->quarter_price_usd;
            $plan->half_year_price_usd = $usdPrices->half_year_price_usd;
            $plan->year_price_usd = $usdPrices->year_price_usd;
            $plan->two_year_price_usd = $usdPrices->two_year_price_usd;
            $plan->three_year_price_usd = $usdPrices->three_year_price_usd;
            $plan->onetime_price_usd = $usdPrices->onetime_price_usd;
            $plan->reset_price_usd = $usdPrices->reset_price_usd;
            $plan->last_exchange_rate = $usdPrices->last_exchange_rate;
            
            // تبدیل به ISO format با timezone
            if ($usdPrices->price_updated_at) {
                $plan->price_updated_at = Carbon::parse($usdPrices->price_updated_at)
                    ->timezone(config('app.timezone'))
                    ->toIso8601String();
            }
        }
        
        return $plan;
    }
    
    /**
     * اضافه کردن قیمت‌های دلاری به مجموعه‌ای از پلن‌ها
     */
    private function addUsdPricesToCollection($plans)
    {
        $planIds = $plans->pluck('id')->toArray();
        
        $usdPrices = DB::table('v2_plan_prices')
            ->whereIn('plan_id', $planIds)
            ->get()
            ->keyBy('plan_id');
        
        foreach ($plans as $plan) {
            if (isset($usdPrices[$plan->id])) {
                $prices = $usdPrices[$plan->id];
                $plan->month_price_usd = $prices->month_price_usd;
                $plan->quarter_price_usd = $prices->quarter_price_usd;
                $plan->half_year_price_usd = $prices->half_year_price_usd;
                $plan->year_price_usd = $prices->year_price_usd;
                $plan->two_year_price_usd = $prices->two_year_price_usd;
                $plan->three_year_price_usd = $prices->three_year_price_usd;
                $plan->onetime_price_usd = $prices->onetime_price_usd;
                $plan->reset_price_usd = $prices->reset_price_usd;
                $plan->last_exchange_rate = $prices->last_exchange_rate;
                
                // تبدیل به ISO format با timezone
                if ($prices->price_updated_at) {
                    $plan->price_updated_at = Carbon::parse($prices->price_updated_at)
                        ->timezone(config('app.timezone'))
                        ->toIso8601String();
                }
            }
        }
        
        return $plans;
    }
}
