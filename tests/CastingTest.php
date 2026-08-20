<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The cast of an entry is driven by its own "type" column, so every supported
 * cast must survive a write followed by a read.
 */
class CastingTest extends TestCase
{
    public function test_a_value_written_without_a_cast_is_read_back_as_a_string(): void
    {
        KvStore::set('key', 'a string');

        $this->assertSame('a string', KvStore::get('key'));
    }

    public function test_a_value_written_without_a_cast_loses_its_php_type(): void
    {
        KvStore::set('key', 42);

        $this->assertSame('42', KvStore::get('key'));
    }

    public function test_the_integer_cast_is_applied_on_read(): void
    {
        KvStore::set('key', '42', 'integer');

        $this->assertSame(42, KvStore::get('key'));
    }

    public function test_the_float_cast_is_applied_on_read(): void
    {
        KvStore::set('key', '1.5', 'float');

        $this->assertSame(1.5, KvStore::get('key'));
    }

    public function test_the_boolean_cast_is_applied_on_read(): void
    {
        KvStore::set('true', 1, 'boolean');
        KvStore::set('false', 0, 'boolean');

        $this->assertTrue(KvStore::get('true'));
        $this->assertFalse(KvStore::get('false'));
    }

    public function test_the_string_cast_is_applied_on_read(): void
    {
        KvStore::set('key', 42, 'string');

        $this->assertSame('42', KvStore::get('key'));
    }

    public function test_the_array_cast_is_applied_on_read(): void
    {
        KvStore::set('key', ['a' => 1, 'b' => [2, 3]], 'array');

        $this->assertSame(['a' => 1, 'b' => [2, 3]], KvStore::get('key'));
    }

    public function test_the_json_cast_is_applied_on_read(): void
    {
        KvStore::set('key', ['a' => 1], 'json');

        $this->assertSame(['a' => 1], KvStore::get('key'));
    }

    public function test_the_object_cast_is_applied_on_read(): void
    {
        KvStore::set('key', ['a' => 1], 'object');

        $value = KvStore::get('key');

        $this->assertInstanceOf(stdClass::class, $value);
        $this->assertSame(1, $value->a);
    }

    public function test_the_collection_cast_is_applied_on_read(): void
    {
        KvStore::set('key', ['a' => 1], 'collection');

        $value = KvStore::get('key');

        $this->assertInstanceOf(Collection::class, $value);
        $this->assertSame(['a' => 1], $value->all());
    }

    public function test_the_date_cast_is_applied_on_read(): void
    {
        KvStore::set('key', '2026-08-20', 'date');

        $value = KvStore::get('key');

        $this->assertInstanceOf(Carbon::class, $value);
        $this->assertSame('2026-08-20 00:00:00', $value->toDateTimeString());
    }

    public function test_the_datetime_cast_is_applied_on_read(): void
    {
        KvStore::set('key', '2026-08-20 10:30:00', 'datetime');

        $value = KvStore::get('key');

        $this->assertInstanceOf(Carbon::class, $value);
        $this->assertSame('2026-08-20 10:30:00', $value->toDateTimeString());
    }
}
