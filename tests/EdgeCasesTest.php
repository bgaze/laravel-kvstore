<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class EdgeCasesTest extends TestCase
{
    /**
     * The encrypted cast needs an application key; generate one for the run
     * rather than shipping a fixed value.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    /**
     * Writing a value that needs a cast while asking for no cast leaves the
     * value unserialised. On an insert the driver refuses it outright.
     */
    public function test_writing_an_array_with_no_cast_fails_on_a_new_entry(): void
    {
        $thrown = null;

        try {
            KvStore::set('key', ['a'], false);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(QueryException::class, $thrown);
        $this->assertStringContainsString('Array to string conversion', $thrown->getMessage());
        $this->assertSame(0, DB::table('kvstore')->count());
    }

    /**
     * On an existing entry the same write goes through an update, where the
     * driver grammar serialises the array on its own. The cast is gone, so the
     * value comes back as the raw JSON string it was stored as.
     */
    public function test_removing_the_cast_of_an_array_entry_degrades_it_to_a_json_string(): void
    {
        KvStore::set('key', ['a' => 1], 'array');

        KvStore::set('key', ['a'], false);

        $this->assertSame('["a"]', KvStore::get('key'));
        $this->assertSame('["a"]', DB::table('kvstore')->where('key', 'key')->value('value'));
    }

    /**
     * An unknown cast is refused up front, before the store is touched at all.
     */
    public function test_an_unknown_cast_is_refused_before_the_store_is_touched(): void
    {
        $thrown = null;

        try {
            KvStore::set('key', 'a value', 'not-a-cast');
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        $this->assertStringContainsString('not-a-cast', $thrown->getMessage());
        $this->assertSame(0, DB::table('kvstore')->count());
    }

    /**
     * An encrypted cast is refused, with the reason spelled out: the store is
     * read as a whole, so every encrypted entry would be decrypted on every
     * request whether it is used or not.
     */
    public function test_an_encrypted_cast_is_refused(): void
    {
        $thrown = null;

        try {
            KvStore::set('key', ['secret' => 'value'], 'encrypted:array');
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        $this->assertStringContainsString('decrypted on every request', $thrown->getMessage());
        $this->assertSame(0, DB::table('kvstore')->count());
        $this->assertNull(Cache::get('kvstore'));
    }
}
