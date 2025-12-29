<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Plan;
use App\Observers\PlanObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['view']->addNamespace('theme', public_path() . '/theme');
        
        // ثبت Observer برای همگام‌سازی جدول قیمت‌ها
        Plan::observe(PlanObserver::class);
    }
}
