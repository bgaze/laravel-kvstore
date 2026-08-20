<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A cast is checked against what the store supports before anything is written,
 * so a mistyped cast name fails where it was made rather than on a later read.
 */
class CastValidationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function supportedCasts(): array
    {
        $casts = [
            'array', 'bool', 'boolean', 'collection', 'date', 'datetime',
            'double', 'float', 'int', 'integer', 'immutable_date',
            'immutable_datetime', 'json', 'object', 'real', 'string',
            'timestamp', 'decimal:2', 'date:Y-m-d', 'datetime:Y-m-d H:i',
        ];

        return array_combine($casts, array_map(fn (string $cast): array => [$cast], $casts));
    }

    #[DataProvider('supportedCasts')]
    public function test_a_supported_cast_is_accepted(string $cast): void
    {
        KvStore::set('key', 1, $cast);

        $this->assertSame($cast, DB::table('kvstore')->where('key', 'key')->value('type'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedCasts(): array
    {
        return [
            'unknown name' => ['not-a-cast'],
            'empty name' => [''],
            'encrypted' => ['encrypted'],
            'encrypted array' => ['encrypted:array'],
            'encrypted collection' => ['encrypted:collection'],
            'hashed' => ['hashed'],
            'parameter on a cast taking none' => ['integer:2'],
        ];
    }

    #[DataProvider('refusedCasts')]
    public function test_a_refused_cast_never_reaches_the_store(string $cast): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            KvStore::set('key', 'a value', $cast);
        } finally {
            $this->assertSame(0, DB::table('kvstore')->count());
        }
    }

    public function test_the_refusal_of_an_encrypted_cast_explains_itself(): void
    {
        $this->expectExceptionMessage('decrypted on every request');

        KvStore::set('key', 'a value', 'encrypted');
    }

    public function test_the_refusal_of_an_unknown_cast_lists_the_supported_ones(): void
    {
        $this->expectExceptionMessage('Supported casts are: array, bool, boolean');

        KvStore::set('key', 'a value', 'nope');
    }

    public function test_a_parameterised_cast_is_honoured_on_read(): void
    {
        KvStore::set('key', '1.5', 'decimal:2');

        $this->assertSame('1.50', KvStore::get('key'));
    }
}
