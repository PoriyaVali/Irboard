<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2ReservedPlansTable extends Migration
{
    public function up()
    {
        Schema::create('v2_reserved_plans', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->index();
            $table->integer('order_id');
            $table->integer('plan_id');
            $table->string('period', 50);
            $table->tinyInteger('status')->default(0)->comment('0=رزرو، 1=فعال شده، 2=لغو شده');
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->integer('activated_at')->nullable()->comment('زمان فعال‌سازی');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_reserved_plans');
    }
}
