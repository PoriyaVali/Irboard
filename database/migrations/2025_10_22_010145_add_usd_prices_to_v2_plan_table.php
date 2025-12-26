<?php
// database/migrations/2025_xx_xx_xxxxxx_add_usd_prices_to_v2_plan_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUsdPricesToV2PlanTable extends Migration
{
    public function up()
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->decimal('month_price_usd', 10, 2)->nullable()->after('month_price');
            $table->decimal('quarter_price_usd', 10, 2)->nullable()->after('quarter_price');
            $table->decimal('half_year_price_usd', 10, 2)->nullable()->after('half_year_price');
            $table->decimal('year_price_usd', 10, 2)->nullable()->after('year_price');
            $table->decimal('two_year_price_usd', 10, 2)->nullable()->after('two_year_price');
            $table->decimal('three_year_price_usd', 10, 2)->nullable()->after('three_year_price');
            $table->decimal('onetime_price_usd', 10, 2)->nullable()->after('onetime_price');
            $table->decimal('reset_price_usd', 10, 2)->nullable()->after('reset_price');
            
            $table->timestamp('price_updated_at')->nullable();
            $table->integer('last_exchange_rate')->nullable();
        });
    }

    public function down()
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn([
                'month_price_usd', 'quarter_price_usd', 'half_year_price_usd',
                'year_price_usd', 'two_year_price_usd', 'three_year_price_usd',
                'onetime_price_usd', 'reset_price_usd',
                'price_updated_at', 'last_exchange_rate'
            ]);
        });
    }
}