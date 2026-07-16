<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class AddCarryOverDaysToV2PlanTable extends Migration
{
    public function up()
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->boolean('carry_over_days')->default(0)->after('renew')->comment('انتقال روزهای باقیمانده در تمدید');
        });
    }

    public function down()
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn('carry_over_days');
        });
    }
}
