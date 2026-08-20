<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Entry;
use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The store is cached as one single entry holding the whole key/value
 * collection, values already cast. Every write drops that entry wholesale, so
 * the next read repopulates the store in a single query. This is not a per-key
 * cache and must not be mistaken for one.
 */
class CacheBehaviorTest extends TestCase
{
    public function test_the_whole_store_is_cached_under_one_single_entry(): void
    {
        KvStore::set('a', '42', 'integer');
        KvStore::set('b', 'a string');

        KvStore::all();

        $cached = Cache::get('settings-store-cache');

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertSame(['a' => 42, 'b' => 'a string'], $cached->all());
    }

    public function test_a_cold_read_costs_one_query_and_the_next_ones_cost_none(): void
    {
        KvStore::set('a', 'first');
        KvStore::set('b', 'second');

        $this->assertSame(1, $this->countQueries(fn () => KvStore::all()));
        $this->assertSame(0, $this->countQueries(fn () => KvStore::all()));
        $this->assertSame(0, $this->countQueries(fn () => KvStore::get('a')));
    }

    public function test_set_drops_the_cache(): void
    {
        KvStore::all();
        $this->assertTrue(Cache::has('settings-store-cache'));

        KvStore::set('key', 'a value');

        $this->assertFalse(Cache::has('settings-store-cache'));
    }

    public function test_remove_drops_the_cache(): void
    {
        KvStore::set('key', 'a value');
        KvStore::all();
        $this->assertTrue(Cache::has('settings-store-cache'));

        KvStore::remove('key');

        $this->assertFalse(Cache::has('settings-store-cache'));
    }

    public function test_refresh_drops_the_cache(): void
    {
        KvStore::all();
        $this->assertTrue(Cache::has('settings-store-cache'));

        KvStore::refresh();

        $this->assertFalse(Cache::has('settings-store-cache'));
    }

    /**
     * A write going through the model instead of the store leaves the cache
     * untouched, so reads keep serving the previous value until refresh().
     */
    public function test_a_write_bypassing_the_store_serves_a_stale_read(): void
    {
        KvStore::set('key', 'first');
        KvStore::all();

        $entry = Entry::query()->findOrFail('key');
        $entry->value = 'second';
        $entry->save();

        $this->assertSame('first', KvStore::get('key'));

        KvStore::refresh();

        $this->assertSame('second', KvStore::get('key'));
    }

    /**
     * A raw SQL write cannot be observed by the package at all: refresh() is
     * the only remedy.
     */
    public function test_a_raw_sql_write_serves_a_stale_read(): void
    {
        KvStore::set('key', 'first');
        KvStore::all();

        DB::table('kvstore')->where('key', 'key')->update(['value' => 'second']);

        $this->assertSame('first', KvStore::get('key'));

        KvStore::refresh();

        $this->assertSame('second', KvStore::get('key'));
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();
        } finally {
            DB::disableQueryLog();
        }

        return count(DB::getQueryLog());
    }
}
