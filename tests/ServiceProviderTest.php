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
     * The binding is declared while registering, so a provider booting earlier
     * can already resolve the store from its own register phase.
     */
    public function test_the_binding_is_available_during_registration(): void
    {
        $this->assertTrue(BindingProbeProvider::$boundDuringRegistration);
    }

    /**
     * One store per application: the binding is a singleton.
     */
    public function test_resolving_the_store_twice_yields_the_same_instance(): void
    {
        $this->assertSame($this->container()->make('kvstore.client'), $this->container()->make('kvstore.client'));
    }

    /**
     * The container key of the previous major is kept as an alias, so code
     * resolving it keeps working and gets the same instance.
     */
    public function test_the_container_key_is_an_alias_of_the_class_name(): void
    {
        $this->assertSame($this->container()->make(Client::class), $this->container()->make('kvstore.client'));
    }

    /**
     * The config and the migration are publishable on their own, and together
     * under the umbrella tag of the package.
     */
    public function test_the_publishable_groups(): void
    {
        $groups = array_values(array_filter(
            ServiceProvider::publishableGroups(),
            fn (string $group): bool => str_starts_with($group, 'kvstore')
        ));

        sort($groups);

        $this->assertSame(['kvstore', 'kvstore-config', 'kvstore-migrations'], $groups);

        $this->assertSame(['kvstore.php'], $this->publishedSources('kvstore-config'));
        $this->assertSame(['migrations'], $this->publishedSources('kvstore-migrations'));

        $umbrella = $this->publishedSources('kvstore');
        sort($umbrella);

        $this->assertSame(['kvstore.php', 'migrations'], $umbrella);
    }

    /**
     * @return list<string>
     */
    private function publishedSources(string $group): array
    {
        return array_map(
            fn (int|string $path): string => basename((string) $path),
            array_keys(ServiceProvider::pathsToPublish(KvStoreServiceProvider::class, $group))
        );
    }

    private function container(): Application
    {
        return app();
    }
}
