<?php

declare(strict_types=1);

namespace Bgaze\KvStore;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider as Base;

/**
 * The package service provider.
 */
class ServiceProvider extends Base
{
    /**
     * Register the package services.
     *
     * The store is bound here rather than while booting, so that a provider
     * booting earlier can already resolve it.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kvstore.php', 'kvstore');

        $this->app->singleton(Client::class);
        $this->app->alias(Client::class, 'kvstore.client');
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/kvstore.php' => config_path('kvstore.php'),
        ], ['kvstore', 'kvstore-config']);

        $this->publishes([
            __DIR__.'/migrations' => database_path('migrations'),
        ], ['kvstore', 'kvstore-migrations']);

        $this->registerCacheInvalidation();
    }

    /**
     * Drop the cached store whenever an entry is written through the model.
     *
     * This covers the writes the facade cannot see -- a seeder, or a direct
     * model write. A raw SQL write remains invisible: refresh() is the remedy.
     */
    private function registerCacheInvalidation(): void
    {
        if (! $this->app->make(ConfigRepository::class)->get('kvstore.cache.auto_invalidate', true)) {
            return;
        }

        $refresh = function (): void {
            $this->app->make(Client::class)->refresh();
        };

        Entry::saved($refresh);
        Entry::deleted($refresh);
    }
}
