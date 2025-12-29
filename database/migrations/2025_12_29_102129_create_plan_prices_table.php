<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->integer('plan_id')->unique();
            $table->decimal('month_price_usd', 10, 2)->nullable()->comment('قیمت ماهانه به دلار');
            $table->decimal('quarter_price_usd', 10, 2)->nullable()->comment('قیمت سه ماهه به دلار');
            $table->decimal('half_year_price_usd', 10, 2)->nullable()->comment('قیمت شش ماهه به دلار');
            $table->decimal('year_price_usd', 10, 2)->nullable()->comment('قیمت سالانه به دلار');
            $table->decimal('two_year_price_usd', 10, 2)->nullable()->comment('قیمت دو ساله به دلار');
            $table->decimal('three_year_price_usd', 10, 2)->nullable()->comment('قیمت سه ساله به دلار');
            $table->decimal('onetime_price_usd', 10, 2)->nullable()->comment('قیمت یکبار به دلار');
            $table->decimal('reset_price_usd', 10, 2)->nullable()->comment('قیمت ریست به دلار');
            $table->integer('last_exchange_rate')->nullable()->comment('نرخ دلار زمان محاسبه قیمت');
            $table->timestamp('price_updated_at')->nullable()->comment('زمان آخرین بروزرسانی قیمت');
            $table->timestamps();
            
            $table->index('plan_id');
        });

        // انتقال قیمت‌های دلاری فعلی از v2_plan (فقط فیلدهای موجود)
        $columns = Schema::getColumnListing('v2_plan');
        
        if (in_array('month_price_usd', $columns)) {
            DB::statement("
                INSERT INTO v2_plan_prices (plan_id, month_price_usd, quarter_price_usd, half_year_price_usd, year_price_usd, two_year_price_usd, three_year_price_usd, onetime_price_usd, reset_price_usd, created_at, updated_at)
                SELECT id, month_price_usd, quarter_price_usd, half_year_price_usd, year_price_usd, two_year_price_usd, three_year_price_usd, onetime_price_usd, reset_price_usd, NOW(), NOW()
                FROM v2_plan
            ");
        } else {
            // اگر فیلدهای USD در v2_plan نیست، فقط plan_id را کپی کن
            DB::statement("
                INSERT INTO v2_plan_prices (plan_id, created_at, updated_at)
                SELECT id, NOW(), NOW()
                FROM v2_plan
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_plan_prices');
    }
};
