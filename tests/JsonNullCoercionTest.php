<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A null written on an entry carrying a JSON-backed cast is coerced to an empty
 * array rather than stored as null. Deliberate: it keeps a JSON entry readable
 * as a collection type instead of handing back a null the caller has to guard.
 */
class JsonNullCoercionTest extends TestCase
{
    public function test_a_null_written_on_an_array_cast_becomes_an_empty_array(): void
    {
        KvStore::set('key', ['a' => 1], 'array');
        KvStore::set('key', null);

        $this->assertSame([], KvStore::get('key'));
        $this->assertSame('[]', $this->storedValue('key'));
    }

    public function test_a_null_written_on_a_json_cast_becomes_an_empty_array(): void
    {
        KvStore::set('key', ['a' => 1], 'json');
        KvStore::set('key', null);

        $this->assertSame([], KvStore::get('key'));
    }

    public function test_a_null_written_on_a_collection_cast_becomes_an_empty_collection(): void
    {
        KvStore::set('key', ['a' => 1], 'collection');
        KvStore::set('key', null);

        $value = KvStore::get('key');

        $this->assertInstanceOf(Collection::class, $value);
        $this->assertTrue($value->isEmpty());
    }

    /**
     * The coercion writes an empty JSON array, which the object cast decodes as
     * an empty PHP array rather than as an empty object.
     */
    public function test_a_null_written_on_an_object_cast_becomes_an_empty_array(): void
    {
        KvStore::set('key', ['a' => 1], 'object');
        KvStore::set('key', null);

        $this->assertSame([], KvStore::get('key'));
    }

    public function test_a_null_written_on_a_scalar_cast_stays_null(): void
    {
        KvStore::set('key', '42', 'integer');
        KvStore::set('key', null);

        $this->assertNull(KvStore::get('key'));
        $this->assertNull($this->storedValue('key'));
    }

    public function test_a_null_written_without_a_cast_stays_null(): void
    {
        KvStore::set('key', null);

        $this->assertNull(KvStore::get('key'));
        $this->assertNull($this->storedValue('key'));
    }

    private function storedValue(string $key): ?string
    {
        $value = DB::table('kvstore')->where('key', $key)->value('value');

        return is_string($value) ? $value : null;
    }
}
