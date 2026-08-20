<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The table, the cache store and the cache entry name all come from the
 * configuration, whose defaults exist without publishing anything.
 */
class ConfigurationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kvstore.table', 'options');
        $app['config']->set('kvstore.cache.key', 'options-store');
        $app['config']->set('kvstore.cache.store', 'kvstore');
        $app['config']->set('cache.stores.kvstore', ['driver' => 'array']);
    }

    public function test_the_migration_creates_the_configured_table(): void
    {
        $this->assertTrue(Schema::hasTable('options'));
        $this->assertFalse(Schema::hasTable('kvstore'));
    }

    public function test_the_model_reads_and_writes_the_configured_table(): void
    {
        KvStore::set('key', '42', 'integer');

        $this->assertSame(42, KvStore::get('key'));
        $this->assertSame(1, DB::table('options')->count());
    }

    public function test_the_store_is_cached_under_the_configured_key_in_the_configured_store(): void
    {
        KvStore::set('key', 'a value');

        KvStore::all();

        $this->assertTrue(Cache::store('kvstore')->has('options-store'));
        $this->assertFalse(Cache::store('array')->has('options-store'));
    }
}
