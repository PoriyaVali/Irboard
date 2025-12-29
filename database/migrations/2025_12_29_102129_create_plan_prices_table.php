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
            $table->decimal('month_price_usd', 10, 2)->nullable();
            $table->decimal('quarter_price_usd', 10, 2)->nullable();
            $table->decimal('half_year_price_usd', 10, 2)->nullable();
            $table->decimal('year_price_usd', 10, 2)->nullable();
            $table->decimal('two_year_price_usd', 10, 2)->nullable();
            $table->decimal('three_year_price_usd', 10, 2)->nullable();
            $table->decimal('onetime_price_usd', 10, 2)->nullable();
            $table->decimal('reset_price_usd', 10, 2)->nullable();
            $table->integer('last_exchange_rate')->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->timestamps();
            
            $table->index('plan_id');
        });

        // انتقال قیمت‌های دلاری فعلی از v2_plan
        DB::statement("
            INSERT INTO v2_plan_prices (plan_id, month_price_usd, quarter_price_usd, half_year_price_usd, year_price_usd, two_year_price_usd, three_year_price_usd, onetime_price_usd, reset_price_usd, last_exchange_rate, price_updated_at, created_at, updated_at)
            SELECT id, month_price_usd, quarter_price_usd, half_year_price_usd, year_price_usd, two_year_price_usd, three_year_price_usd, onetime_price_usd, reset_price_usd, last_exchange_rate, price_updated_at, NOW(), NOW()
            FROM v2_plan
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_plan_prices');
    }
};
