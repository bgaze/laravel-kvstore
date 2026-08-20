<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade;
use Bgaze\KvStore\ServiceProvider;
use Orchestra\Testbench\TestCase as Base;

abstract class TestCase extends Base
{
    /**
     * Register the package service provider.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    /**
     * Register the package facade alias.
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['KvStore' => Facade::class];
    }

    /**
     * Run the package migration against the in-memory test database.
     *
     * The migration is shipped to be published, so it is not auto-loaded by
     * the service provider: the suite loads it explicitly instead.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../src/migrations');
    }

    /**
     * Use an in-memory database and a cache store the suite can observe.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
    }
}
