<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates payment_tracks table for tracking payment gateway transactions
     * and preventing payment loss due to callback failures.
     */
    public function up(): void
    {
        Schema::create('payment_tracks', function (Blueprint $table) {
            $table->id();
            
            // Payment identifiers
            $table->string('track_id', 100)
                  ->unique()
                  ->comment('Unique payment gateway tracking ID');
            
            $table->string('trade_no', 100)
                  ->nullable()
                  ->comment('Order trade number for reference');
            
            // Order & User relations
            $table->unsignedBigInteger('order_id')
                  ->nullable()
                  ->comment('Related order ID');
            
            $table->unsignedBigInteger('user_id')
                  ->nullable()
                  ->comment('User ID who made the payment');
            
            // Payment details
            $table->integer('amount')
                  ->default(0)
                  ->comment('Payment amount in Toman');
            
            $table->string('payment_method', 50)
                  ->default('zibal')
                  ->comment('Payment gateway method');
            
            // Usage tracking
            $table->boolean('is_used')
                  ->default(false)
                  ->comment('Whether trackId has been used (prevents double-spending)');
            
            $table->timestamp('used_at')
                  ->nullable()
                  ->comment('Timestamp when trackId was marked as used');
            
            $table->timestamps();
            
            // Indexes for query optimization
            $table->index('trade_no', 'payment_tracks_trade_no_index');
            $table->index('order_id', 'payment_tracks_order_id_index');
            $table->index('user_id', 'payment_tracks_user_id_index');
            $table->index('is_used', 'payment_tracks_is_used_index');
            $table->index('created_at', 'payment_tracks_created_at_index');
            
            // Composite index for common queries
            $table->index(['is_used', 'created_at'], 'payment_tracks_is_used_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_tracks');
    }
};
