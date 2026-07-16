<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Plan;
use App\Observers\PlanObserver;

class PlanObserverProvider extends ServiceProvider
{
    public function boot()
    {
        Plan::observe(PlanObserver::class);
    }
}
