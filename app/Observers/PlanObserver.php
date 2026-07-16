<?php

namespace App\Observers;

use App\Models\Plan;
use Illuminate\Support\Facades\DB;

class PlanObserver
{
    /**
     * پلن جدید ساخته شد - ردیف خالی در جدول قیمت اضافه کن
     */
    public function created(Plan $plan)
    {
        $exists = DB::table('v2_plan_prices')->where('plan_id', $plan->id)->exists();
        
        if (!$exists) {
            DB::table('v2_plan_prices')->insert([
                'plan_id' => $plan->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    /**
     * پلن حذف شد - ردیف مربوطه را حذف کن
     */
    public function deleted(Plan $plan)
    {
        DB::table('v2_plan_prices')->where('plan_id', $plan->id)->delete();
    }
}
