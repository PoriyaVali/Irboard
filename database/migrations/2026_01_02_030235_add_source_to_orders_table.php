<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSourceToOrdersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('v2_order', 'source')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->string('source', 20)->default('web')->after('status')->comment('منبع سفارش: web, telegram');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('v2_order', 'source')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
}
