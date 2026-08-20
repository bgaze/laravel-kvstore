<?php

declare(strict_types=1);

namespace Bgaze\KvStore;

use Illuminate\Support\ServiceProvider as Base;

/**
 * The package service provider.
 */
class ServiceProvider extends Base
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish migrations.
        $this->publishes([__DIR__.'/migrations' => database_path('migrations')], 'kvstore');

        // Register kvstore service.
        $this->app->bind('kvstore.client', Client::class);
    }
}
