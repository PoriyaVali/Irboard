<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * تنظیمات صفحه ترانزیت و پروکسی پرداخت
     */
    public function up(): void
    {
        $settings = [
            // تنظیمات ترانزیت (redirect کاربر به درگاه)
            'payment_transit_enable' => '0',
            'payment_transit_url' => '',
            // تنظیمات پروکسی (مخفی کردن IP سرور بک‌اند)
            'payment_proxy_enable' => '0',
            'payment_proxy_url' => '',
            'payment_proxy_secret' => '',
        ];

        foreach ($settings as $key => $value) {
            DB::table('v2_bot_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('v2_bot_settings')->whereIn('key', [
            'payment_transit_enable',
            'payment_transit_url',
            'payment_proxy_enable',
            'payment_proxy_url',
            'payment_proxy_secret',
        ])->delete();
    }
};