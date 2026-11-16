<?php

namespace App\Providers;

use App\Protocols\Mihomo\ProtocolRegistry;
use App\Services\LocalNodePorts;
use Illuminate\Support\ServiceProvider;

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

        // A node saved or deleted may open or close a port on this server: the
        // server-config agent reconciles every minute, and at once when asked.
        $models = LocalNodePorts::NODE_MODELS;
        foreach (ProtocolRegistry::types() as $type) {
            $models[] = ProtocolRegistry::model($type);
        }
        foreach (array_unique($models) as $model) {
            if (!class_exists($model)) continue;
            $model::saved(function () { LocalNodePorts::markDirty(); });
            $model::deleted(function () { LocalNodePorts::markDirty(); });
        }
    }
}
