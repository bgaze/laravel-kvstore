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
    /**
     * Raw values and their cast name go into the cache, never a value already
     * cast: an object in there is something a hardened cache cannot rebuild.
     */
    public function test_the_whole_store_is_cached_under_one_single_entry(): void
    {
        KvStore::set('a', '42', 'integer');
        KvStore::set('b', 'a string');

        KvStore::all();

        $this->assertSame([
            'a' => ['value' => '42', 'type' => 'integer'],
            'b' => ['value' => 'a string', 'type' => null],
        ], Cache::get('kvstore'));
    }

    /**
     * The store is read from the cache and cast once, then held for the rest of
     * the request.
     */
    public function test_the_store_is_resolved_once_per_request(): void
    {
        KvStore::set('key', 'first');
        KvStore::all();

        Cache::forever('kvstore', ['key' => ['value' => 'second', 'type' => null]]);

        $this->assertSame('first', KvStore::get('key'));

        KvStore::flushResolved();

        $this->assertSame('second', KvStore::get('key'));
    }

    public function test_flushing_the_resolved_store_leaves_the_cache_alone(): void
    {
        KvStore::set('key', 'a value');
        KvStore::all();

        KvStore::flushResolved();

        $this->assertTrue(Cache::has('kvstore'));
    }

    /**
     * The previous major cached values already cast, under the same key. Such a
     * payload is rebuilt rather than read, so upgrading needs no cache flush.
     */
    public function test_a_payload_from_the_previous_major_is_rebuilt(): void
    {
        KvStore::set('key', '42', 'integer');

        Cache::forever('kvstore', new Collection(['key' => 42]));
        KvStore::flushResolved();

        $this->assertSame(42, KvStore::get('key'));
        $this->assertSame(['key' => ['value' => '42', 'type' => 'integer']], Cache::get('kvstore'));
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
