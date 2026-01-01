<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExchangeRateToOrdersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('v2_order', 'exchange_rate')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->integer('exchange_rate')->nullable()->after('balance_amount');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('v2_order', 'exchange_rate')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->dropColumn('exchange_rate');
            });
        }
    }
}
