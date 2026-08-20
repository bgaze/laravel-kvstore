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

        $cached = Cache::get('kvstore');

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
        $this->assertTrue(Cache::has('kvstore'));

        KvStore::set('key', 'a value');

        $this->assertFalse(Cache::has('kvstore'));
    }

    public function test_remove_drops_the_cache(): void
    {
        KvStore::set('key', 'a value');
        KvStore::all();
        $this->assertTrue(Cache::has('kvstore'));

        KvStore::remove('key');

        $this->assertFalse(Cache::has('kvstore'));
    }

    public function test_refresh_drops_the_cache(): void
    {
        KvStore::all();
        $this->assertTrue(Cache::has('kvstore'));

        KvStore::refresh();

        $this->assertFalse(Cache::has('kvstore'));
    }

    /**
     * A write going through the model is observed: saving an entry drops the
     * cache on its own, so no refresh() is needed to read the new value.
     */
    public function test_a_write_through_the_model_drops_the_cache(): void
    {
        KvStore::set('key', 'first');
        KvStore::all();

        $entry = Entry::query()->findOrFail('key');
        $entry->value = 'second';
        $entry->save();

        $this->assertFalse(Cache::has('kvstore'));
        $this->assertSame('second', KvStore::get('key'));
    }

    public function test_a_delete_through_the_model_drops_the_cache(): void
    {
        KvStore::set('key', 'first');
        KvStore::all();

        Entry::query()->findOrFail('key')->delete();

        $this->assertFalse(Cache::has('kvstore'));
        $this->assertFalse(KvStore::has('key'));
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
