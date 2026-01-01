<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentTracksTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('payment_tracks')) {
            Schema::create('payment_tracks', function (Blueprint $table) {
                $table->id();
                $table->string('track_id', 100)->unique()->comment('Unique payment gateway tracking ID');
                $table->string('trade_no', 100)->nullable()->index()->comment('Order trade number for reference');
                $table->unsignedBigInteger('order_id')->nullable()->index()->comment('Related order ID');
                $table->unsignedBigInteger('user_id')->nullable()->index()->comment('User ID who made the payment');
                $table->integer('amount')->default(0)->comment('Payment amount in Toman');
                $table->string('payment_method', 50)->default('zibal')->comment('Payment gateway method');
                $table->boolean('is_used')->default(false)->index()->comment('Whether trackId has been used');
                $table->timestamp('used_at')->nullable()->comment('Timestamp when trackId was marked as used');
                $table->timestamps();
                $table->index(['is_used', 'created_at']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('payment_tracks');
    }
}
