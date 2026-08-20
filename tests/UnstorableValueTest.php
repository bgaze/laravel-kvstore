<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * A value goes into a text column, so it has to be a string by the time it gets
 * there. A cast that serialises it -- array, json, object, collection -- does
 * that on assignment; a scalar cast does not, and neither does no cast at all.
 *
 * Left alone, such a write either failed the insert or, on an update, was
 * serialised by the driver grammar and read back as a raw JSON string. It is
 * refused at the call site instead.
 */
class UnstorableValueTest extends TestCase
{
    public function test_an_array_written_without_a_cast_is_refused(): void
    {
        $thrown = null;

        try {
            KvStore::set('key', ['a' => 1]);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        $this->assertStringContainsString('needs a cast that serialises it', $thrown->getMessage());
        $this->assertStringContainsString('[array]', $thrown->getMessage());
        $this->assertStringContainsString('[key]', $thrown->getMessage());
        $this->assertSame(0, DB::table('kvstore')->count());
    }

    /**
     * The case that used to degrade silently: the cast is removed in the very
     * write that hands over a value needing one.
     */
    public function test_an_array_written_while_removing_the_cast_is_refused(): void
    {
        KvStore::set('key', ['a' => 1], 'array');

        $this->expectException(InvalidArgumentException::class);

        try {
            KvStore::set('key', ['b' => 2], false);
        } finally {
            // The entry is left exactly as it was.
            $this->assertSame(['a' => 1], KvStore::get('key'));
            $this->assertSame('array', DB::table('kvstore')->where('key', 'key')->value('type'));
        }
    }

    /**
     * A scalar cast serialises nothing either, so it is the same defect reached
     * a different way.
     */
    public function test_an_array_written_under_a_scalar_cast_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            KvStore::set('key', ['a' => 1], 'integer');
        } finally {
            $this->assertSame(0, DB::table('kvstore')->count());
        }
    }

    public function test_an_object_that_cannot_be_written_as_text_is_refused(): void
    {
        $thrown = null;

        try {
            KvStore::set('key', new stdClass);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        $this->assertStringContainsString('[stdClass]', $thrown->getMessage());
        $this->assertSame(0, DB::table('kvstore')->count());
    }

    /**
     * A collection is stringable -- it renders as its own JSON -- so writing one
     * without a cast is a complete write, not a degraded one. It reads back as
     * that JSON string; ask for the collection cast to get a collection back.
     */
    public function test_a_stringable_collection_is_accepted_without_a_cast(): void
    {
        KvStore::set('json', new Collection(['a' => 1]));
        KvStore::set('cast', new Collection(['a' => 1]), 'collection');

        $this->assertSame('{"a":1}', KvStore::get('json'));
        $this->assertInstanceOf(Collection::class, KvStore::get('cast'));
    }

    /**
     * The escape hatch the message points at.
     */
    public function test_the_same_value_is_accepted_under_a_cast_that_serialises_it(): void
    {
        KvStore::set('key', ['a' => 1], 'array');

        $this->assertSame(['a' => 1], KvStore::get('key'));
    }

    public function test_serialising_the_value_yourself_is_accepted(): void
    {
        KvStore::set('key', json_encode(['a' => 1], JSON_THROW_ON_ERROR), false);

        $this->assertSame('{"a":1}', KvStore::get('key'));
    }

    /**
     * The guard must not narrow what already worked: everything a text column
     * takes as it is stays acceptable with no cast.
     */
    public function test_values_a_text_column_takes_as_they_are_stay_accepted(): void
    {
        KvStore::set('string', 'a string');
        KvStore::set('int', 42);
        KvStore::set('float', 1.5);
        KvStore::set('true', true);
        KvStore::set('false', false);
        KvStore::set('null', null);
        KvStore::set('date', new DateTimeImmutable('2026-08-20 10:30:00'));
        KvStore::set('stringable', new class
        {
            public function __toString(): string
            {
                return 'from __toString';
            }
        });

        $this->assertSame('a string', KvStore::get('string'));
        $this->assertSame('42', KvStore::get('int'));
        $this->assertSame('1.5', KvStore::get('float'));
        $this->assertSame('1', KvStore::get('true'));
        $this->assertNull(KvStore::get('null'));
        $this->assertSame('from __toString', KvStore::get('stringable'));
        $this->assertStringStartsWith('2026-08-20 10:30:00', (string) KvStore::get('date'));
        $this->assertTrue(KvStore::has('false'));
    }
}
