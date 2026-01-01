<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreatePlanPricesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_plan_prices')) {
            Schema::create('v2_plan_prices', function (Blueprint $table) {
                $table->id();
                $table->integer('plan_id')->index()->unique();
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
            });

            DB::statement('
                INSERT INTO v2_plan_prices (plan_id, created_at, updated_at)
                SELECT id, NOW(), NOW() FROM v2_plan
            ');
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_plan_prices');
    }
}
