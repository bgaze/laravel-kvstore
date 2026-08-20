<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Facades\DB;

/**
 * The third argument of set() is three-state: omitted or null keeps the cast
 * already stored on the entry, a cast name replaces it, and false removes it.
 */
class TypeSemanticsTest extends TestCase
{
    public function test_an_omitted_type_keeps_the_cast_already_stored(): void
    {
        KvStore::set('key', '42', 'integer');
        KvStore::set('key', '43');

        $this->assertSame(43, KvStore::get('key'));
        $this->assertSame('integer', $this->storedType('key'));
    }

    public function test_a_null_type_keeps_the_cast_already_stored(): void
    {
        KvStore::set('key', '42', 'integer');
        KvStore::set('key', '43', null);

        $this->assertSame(43, KvStore::get('key'));
        $this->assertSame('integer', $this->storedType('key'));
    }

    public function test_a_cast_name_replaces_the_cast_already_stored(): void
    {
        KvStore::set('key', '42', 'integer');
        KvStore::set('key', '1.5', 'float');

        $this->assertSame(1.5, KvStore::get('key'));
        $this->assertSame('float', $this->storedType('key'));
    }

    public function test_a_false_type_removes_the_cast(): void
    {
        KvStore::set('key', '42', 'integer');
        KvStore::set('key', '43', false);

        $this->assertSame('43', KvStore::get('key'));
        $this->assertNull($this->storedType('key'));
    }

    /**
     * The three-state test is a loose emptiness check, so every falsy value
     * other than false is silently understood as "keep the current cast".
     */
    public function test_a_falsy_type_other_than_false_keeps_the_cast(): void
    {
        foreach (['', 0, '0'] as $index => $falsy) {
            $key = 'key'.$index;

            KvStore::set($key, '42', 'integer');
            KvStore::set($key, '43', $falsy);

            $this->assertSame(43, KvStore::get($key));
            $this->assertSame('integer', $this->storedType($key));
        }
    }

    /**
     * The type has to be assigned before the value: the cast used to serialise
     * the value is read from the entry's own type at assignment time. Writing
     * the value first would serialise it through the previous cast.
     */
    public function test_a_new_cast_drives_the_write_of_the_value_it_comes_with(): void
    {
        KvStore::set('key', 'a string');
        KvStore::set('key', ['a' => 1], 'array');

        $this->assertSame(['a' => 1], KvStore::get('key'));
    }

    public function test_replacing_a_json_cast_by_a_scalar_one_uses_the_new_cast(): void
    {
        KvStore::set('key', ['a' => 1], 'array');
        KvStore::set('key', '42', 'integer');

        $this->assertSame(42, KvStore::get('key'));
    }

    private function storedType(string $key): ?string
    {
        $type = DB::table('kvstore')->where('key', $key)->value('type');

        return is_string($type) ? $type : null;
    }
}
