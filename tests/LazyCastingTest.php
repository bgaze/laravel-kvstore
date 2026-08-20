<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Database\Eloquent\InvalidCastException;
use Illuminate\Support\Facades\DB;

/**
 * A cast is applied to the key being read, not to the whole store. It matters
 * on upgrade: this package validates the cast on write, but the previous major
 * did not, so a table filled by it may hold cast names the framework refuses.
 * Such an entry must not make the rest of the store unreadable.
 */
class LazyCastingTest extends TestCase
{
    public function test_an_entry_the_framework_cannot_cast_only_breaks_its_own_key(): void
    {
        KvStore::set('sound', '42', 'integer');
        $this->insertRaw('broken', 'a value', 'not-a-cast');

        $this->assertSame(42, KvStore::get('sound'));
        $this->assertSame('fallback', KvStore::get('missing', 'fallback'));
    }

    public function test_reading_the_entry_that_carries_the_bad_cast_still_raises(): void
    {
        $this->insertRaw('broken', 'a value', 'not-a-cast');

        $this->expectException(InvalidCastException::class);

        KvStore::get('broken');
    }

    public function test_has_casts_nothing(): void
    {
        $this->insertRaw('broken', 'a value', 'not-a-cast');

        $this->assertTrue(KvStore::has('broken'));
        $this->assertFalse(KvStore::has('missing'));
    }

    /**
     * Casting the whole store is what all() is for, so it cannot survive an
     * entry the framework refuses to cast. That is the documented limit.
     */
    public function test_reading_the_whole_store_still_raises(): void
    {
        KvStore::set('sound', '42', 'integer');
        $this->insertRaw('broken', 'a value', 'not-a-cast');

        $this->expectException(InvalidCastException::class);

        KvStore::all();
    }

    /**
     * The cast value is held per key, so reading twice hands back the very same
     * instance rather than casting again.
     */
    public function test_a_value_is_cast_once_per_key(): void
    {
        KvStore::set('key', '2026-08-20', 'date');

        $this->assertSame(KvStore::get('key'), KvStore::get('key'));
    }

    public function test_a_stored_null_is_not_cast_again_on_every_read(): void
    {
        KvStore::set('key', null);

        $this->assertNull(KvStore::get('key'));
        $this->assertNull(KvStore::get('key', 'fallback'));
        $this->assertTrue(KvStore::has('key'));
    }

    public function test_flushing_the_resolved_store_casts_again(): void
    {
        KvStore::set('key', '2026-08-20', 'date');

        $first = KvStore::get('key');

        KvStore::flushResolved();

        $second = KvStore::get('key');

        $this->assertNotSame($first, $second);
        $this->assertEquals($first, $second);
    }

    /**
     * Whatever get() already cast is reused when the whole store is read.
     */
    public function test_reading_the_whole_store_reuses_what_was_already_cast(): void
    {
        KvStore::set('key', '2026-08-20', 'date');

        $fromGet = KvStore::get('key');

        $this->assertSame($fromGet, KvStore::all()->get('key'));
    }

    /**
     * A default given as a closure is resolved, and only when it is needed.
     */
    public function test_a_closure_default_is_resolved_for_a_missing_key_only(): void
    {
        KvStore::set('key', 'a value');

        $this->assertSame('computed', KvStore::get('missing', fn (): string => 'computed'));
        $this->assertSame('a value', KvStore::get('key', fn (): string => 'computed'));
    }

    private function insertRaw(string $key, string $value, ?string $type): void
    {
        DB::table('kvstore')->insert(['key' => $key, 'value' => $value, 'type' => $type]);

        KvStore::refresh();
    }
}
