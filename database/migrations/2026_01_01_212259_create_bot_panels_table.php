<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBotPanelsTable extends Migration
{
    public function up()
    {
        // جدول پنل‌های VPN
        if (!Schema::hasTable('v2_bot_panels')) {
            Schema::create('v2_bot_panels', function (Blueprint $table) {
                $table->id();
                $table->string('name', 200)->comment('نام پنل');
                $table->string('type', 50)->default('marzban')->comment('نوع: marzban, x-ui, s-ui, marzneshin, mikrotik, wg');
                $table->string('url', 500)->comment('آدرس پنل');
                $table->string('username', 200)->nullable();
                $table->string('password', 200)->nullable();
                $table->text('token')->nullable()->comment('توکن دسترسی');
                $table->timestamp('token_expires_at')->nullable();
                $table->string('sub_link', 500)->nullable()->comment('لینک اشتراک');
                $table->text('inbounds')->nullable()->comment('اینباندها JSON');
                $table->text('proxies')->nullable()->comment('پراکسی‌ها JSON');
                $table->boolean('status')->default(true)->comment('فعال/غیرفعال');
                $table->boolean('test_enabled')->default(true)->comment('اکانت تست فعال');
                $table->boolean('on_hold_enabled')->default(false);
                $table->string('username_method', 100)->default('random')->comment('روش ساخت یوزرنیم');
                $table->timestamps();
            });
        }

        // جدول کانال‌های اجباری
        if (!Schema::hasTable('v2_bot_channels')) {
            Schema::create('v2_bot_channels', function (Blueprint $table) {
                $table->id();
                $table->string('channel_id', 100)->comment('آیدی یا یوزرنیم کانال');
                $table->string('title', 200)->nullable();
                $table->string('invite_link', 300)->nullable();
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        // جدول متن‌های ربات
        if (!Schema::hasTable('v2_bot_texts')) {
            Schema::create('v2_bot_texts', function (Blueprint $table) {
                $table->string('key', 100)->primary();
                $table->text('value')->comment('متن');
                $table->timestamps();
            });
        }

        // جدول تنظیمات ربات
        if (!Schema::hasTable('v2_bot_settings')) {
            Schema::create('v2_bot_settings', function (Blueprint $table) {
                $table->string('key', 100)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        // اضافه کردن فیلدهای ربات به v2_user
        if (!Schema::hasColumn('v2_user', 'bot_step')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('bot_step', 100)->nullable()->after('telegram_id')->comment('مرحله فعلی در ربات');
                $table->text('bot_data')->nullable()->after('bot_step')->comment('داده موقت ربات JSON');
                $table->integer('bot_test_count')->default(0)->after('bot_data')->comment('تعداد تست گرفته');
                $table->string('bot_ref_code', 32)->nullable()->unique()->after('bot_test_count')->comment('کد معرف');
                $table->unsignedBigInteger('bot_referrer_id')->nullable()->after('bot_ref_code')->comment('معرف کاربر');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_bot_panels');
        Schema::dropIfExists('v2_bot_channels');
        Schema::dropIfExists('v2_bot_texts');
        Schema::dropIfExists('v2_bot_settings');
        
        if (Schema::hasColumn('v2_user', 'bot_step')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn(['bot_step', 'bot_data', 'bot_test_count', 'bot_ref_code', 'bot_referrer_id']);
            });
        }
    }
}
