<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
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
     * An encrypted cast is refused, with the reason spelled out: all() casts
     * every entry, so one value that cannot be decrypted would take the whole
     * store down with it rather than just its own key.
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
        $this->assertStringContainsString('would make the whole store unreadable', $thrown->getMessage());
        $this->assertSame(0, DB::table('kvstore')->count());
        $this->assertNull(Cache::get('kvstore'));
    }
}
