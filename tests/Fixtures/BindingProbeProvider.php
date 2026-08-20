<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Records whether the store binding is already available while providers are
 * still registering. Registered after the package provider, so it observes
 * whatever the package declared in its own register phase.
 */
class BindingProbeProvider extends ServiceProvider
{
    public static ?bool $boundDuringRegistration = null;

    public function register(): void
    {
        static::$boundDuringRegistration = $this->app->bound('kvstore.client');
    }
}
