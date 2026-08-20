<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Client;
use Bgaze\KvStore\Facade as KvStore;
use Bgaze\KvStore\ServiceProvider as KvStoreServiceProvider;
use Bgaze\KvStore\Tests\Fixtures\BindingProbeProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class ServiceProviderTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [KvStoreServiceProvider::class, BindingProbeProvider::class];
    }

    public function test_the_store_is_bound_under_its_container_key(): void
    {
        $this->assertTrue($this->container()->bound('kvstore.client'));
        $this->assertInstanceOf(Client::class, $this->container()->make('kvstore.client'));
    }

    public function test_the_facade_resolves_the_bound_store(): void
    {
        $this->assertInstanceOf(Client::class, KvStore::getFacadeRoot());
    }

    /**
     * The binding is declared while booting rather than while registering, so a
     * provider that resolves the store from its own register phase cannot.
     */
    public function test_the_binding_is_not_available_during_registration(): void
    {
        $this->assertFalse(BindingProbeProvider::$boundDuringRegistration);
    }

    /**
     * The binding hands out a new instance on every resolution.
     */
    public function test_resolving_the_store_twice_yields_two_instances(): void
    {
        $this->assertNotSame($this->container()->make('kvstore.client'), $this->container()->make('kvstore.client'));
    }

    public function test_the_class_name_is_not_an_alias_of_the_container_key(): void
    {
        $this->assertNotSame($this->container()->make(Client::class), $this->container()->make('kvstore.client'));
    }

    /**
     * Only the migration is publishable, under one single umbrella tag.
     */
    public function test_the_publishable_groups(): void
    {
        $groups = array_values(array_filter(
            ServiceProvider::publishableGroups(),
            fn (string $group): bool => str_starts_with($group, 'kvstore')
        ));

        $this->assertSame(['kvstore'], $groups);

        $sources = array_map(
            fn (int|string $path): string => basename((string) $path),
            array_keys(ServiceProvider::pathsToPublish(KvStoreServiceProvider::class, 'kvstore'))
        );

        $this->assertSame(['migrations'], $sources);
    }

    private function container(): Application
    {
        return app();
    }
}
