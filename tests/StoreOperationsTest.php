<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StoreOperationsTest extends TestCase
{
    public function test_all_returns_the_whole_store_keyed_by_key_with_casts_applied(): void
    {
        KvStore::set('a', 'a string');
        KvStore::set('b', '42', 'integer');
        KvStore::set('c', ['x' => 1], 'array');

        $all = KvStore::all();

        $this->assertInstanceOf(Collection::class, $all);
        $this->assertSame([
            'a' => 'a string',
            'b' => 42,
            'c' => ['x' => 1],
        ], $all->all());
    }

    public function test_all_returns_an_empty_collection_on_an_empty_store(): void
    {
        $this->assertTrue(KvStore::all()->isEmpty());
    }

    public function test_get_returns_the_default_when_the_key_is_absent(): void
    {
        $this->assertSame('default', KvStore::get('missing', 'default'));
        $this->assertNull(KvStore::get('missing'));
    }

    /**
     * A stored null is a value, not an absence: the default is only used when
     * the key itself is missing from the store.
     */
    public function test_get_returns_null_rather_than_the_default_for_a_stored_null(): void
    {
        KvStore::set('key', null);

        $this->assertNull(KvStore::get('key', 'default'));
    }

    public function test_has_tells_a_stored_null_from_a_missing_key(): void
    {
        KvStore::set('key', null);

        $this->assertTrue(KvStore::has('key'));
        $this->assertFalse(KvStore::has('missing'));
    }

    public function test_has_reads_from_the_cache_like_the_other_reads(): void
    {
        KvStore::set('key', 'a value');

        KvStore::all();

        $this->assertSame(0, $this->countQueries(fn () => KvStore::has('key')));
    }

    public function test_remove_deletes_a_single_entry(): void
    {
        KvStore::set('a', 'first');
        KvStore::set('b', 'second');

        KvStore::remove('a');

        $this->assertNull(KvStore::get('a'));
        $this->assertSame('second', KvStore::get('b'));
        $this->assertSame(1, DB::table('kvstore')->count());
    }

    public function test_remove_deletes_several_entries_at_once(): void
    {
        KvStore::set('a', 'first');
        KvStore::set('b', 'second');
        KvStore::set('c', 'third');

        KvStore::remove(['a', 'c']);

        $this->assertSame(['b' => 'second'], KvStore::all()->all());
    }

    public function test_remove_ignores_a_key_that_does_not_exist(): void
    {
        KvStore::set('a', 'first');

        KvStore::remove('missing');

        $this->assertSame(['a' => 'first'], KvStore::all()->all());
    }

    public function test_set_creates_then_updates_a_single_row_for_a_given_key(): void
    {
        KvStore::set('key', 'first');
        KvStore::set('key', 'second');

        $this->assertSame(1, DB::table('kvstore')->count());
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
