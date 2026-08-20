<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Entry;
use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Facades\Cache;

/**
 * Automatic invalidation is a configuration switch. Turned off, the store is
 * only invalidated by its own writes, which brings back the stale reads a write
 * bypassing it leaves behind.
 */
class CacheInvalidationDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kvstore.cache.auto_invalidate', false);
    }

    public function test_a_write_through_the_model_leaves_the_cache_untouched(): void
    {
        KvStore::set('key', 'first');
        KvStore::all();

        $entry = Entry::query()->findOrFail('key');
        $entry->value = 'second';
        $entry->save();

        $this->assertTrue(Cache::has('kvstore'));
        $this->assertSame('first', KvStore::get('key'));

        KvStore::refresh();

        $this->assertSame('second', KvStore::get('key'));
    }

    public function test_the_store_still_invalidates_its_own_writes(): void
    {
        KvStore::all();

        KvStore::set('key', 'a value');

        $this->assertFalse(Cache::has('kvstore'));
    }
}
