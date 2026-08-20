<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use stdClass;

/**
 * Laravel can be told which classes a cache store is allowed to unserialize.
 * With that hardening on, any object left in a cached payload comes back as an
 * incomplete class, silently, so the store must not cache objects at all.
 */
class HardenedCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.stores.hardened', ['driver' => 'array', 'serialize' => true]);
        $app['config']->set('cache.serializable_classes', false);
        $app['config']->set('kvstore.cache.store', 'hardened');
    }

    public function test_the_cached_payload_survives_the_round_trip(): void
    {
        KvStore::set('scalar', '42', 'integer');
        KvStore::set('date', '2026-08-20', 'date');

        $this->simulateNewRequest();

        $this->assertSame(
            [],
            $this->objectPaths(Cache::store('hardened')->get('kvstore')),
            'A hardened cache cannot rebuild an object left in the cached payload.'
        );
    }

    /**
     * A scalar has no object of its own, so it can only break through the
     * container the store caches it in.
     */
    public function test_a_scalar_is_read_back_from_a_hardened_cache(): void
    {
        KvStore::set('key', '42', 'integer');

        $this->simulateNewRequest();

        $this->assertSame(42, KvStore::get('key'));
    }

    public function test_a_date_is_read_back_from_a_hardened_cache(): void
    {
        KvStore::set('key', '2026-08-20', 'date');

        $this->simulateNewRequest();

        $value = KvStore::get('key');

        $this->assertInstanceOf(Carbon::class, $value);
        $this->assertSame('2026-08-20 00:00:00', $value->toDateTimeString());
    }

    public function test_a_collection_is_read_back_from_a_hardened_cache(): void
    {
        KvStore::set('key', ['a' => 1], 'collection');

        $this->simulateNewRequest();

        $value = KvStore::get('key');

        $this->assertInstanceOf(Collection::class, $value);
        $this->assertSame(['a' => 1], $value->all());
    }

    public function test_an_object_is_read_back_from_a_hardened_cache(): void
    {
        KvStore::set('key', ['a' => 1], 'object');

        $this->simulateNewRequest();

        $value = KvStore::get('key');

        $this->assertInstanceOf(stdClass::class, $value);
        $this->assertSame(1, $value->a);
    }

    public function test_an_array_is_read_back_from_a_hardened_cache(): void
    {
        KvStore::set('key', ['a' => 1], 'array');

        $this->simulateNewRequest();

        $this->assertSame(['a' => 1], KvStore::get('key'));
    }

    /**
     * Warm the cache, then drop the store resolved in memory so that the next
     * read has to go through the cache -- which is where the hardening bites.
     */
    private function simulateNewRequest(): void
    {
        KvStore::all();

        KvStore::flushResolved();
    }

    /**
     * List where the payload still holds something a hardened store could not
     * rebuild, so a failure names the offending path rather than just failing.
     *
     * @return list<string>
     */
    private function objectPaths(mixed $value, string $path = 'payload'): array
    {
        if (is_object($value)) {
            return [sprintf('%s (%s)', $path, get_debug_type($value))];
        }

        if (! is_array($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $key => $item) {
            $paths = [...$paths, ...$this->objectPaths($item, sprintf('%s[%s]', $path, $key))];
        }

        return $paths;
    }
}
