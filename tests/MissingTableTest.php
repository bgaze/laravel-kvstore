<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Bgaze\KvStore\Facade as KvStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;

/**
 * Reading the store before its migration has run is a real trap: an application
 * that reads a setting while booting cannot start at all, so the very command
 * that would create the table fails too. The store reads as empty instead, and
 * says so in the log.
 */
class MissingTableTest extends TestCase
{
    public function test_the_store_reads_as_empty_when_the_table_is_missing(): void
    {
        Schema::drop('kvstore');

        $this->assertTrue(KvStore::all()->isEmpty());
        $this->assertNull(KvStore::get('key'));
        $this->assertSame('default', KvStore::get('key', 'default'));
        $this->assertFalse(KvStore::has('key'));
    }

    /**
     * Caching the emptiness would outlive the migration that fixes it.
     */
    public function test_the_emptiness_is_not_cached(): void
    {
        Schema::drop('kvstore');

        KvStore::all();

        $this->assertFalse(Cache::has('kvstore'));
    }

    public function test_the_store_recovers_once_the_table_exists(): void
    {
        Schema::drop('kvstore');

        $this->assertTrue(KvStore::all()->isEmpty());

        $migration = require __DIR__.'/../src/migrations/2019_09_29_000000_create_kvstore_table.php';
        $this->assertInstanceOf(Migration::class, $migration);
        (new ReflectionMethod($migration, 'up'))->invoke($migration);

        KvStore::flushResolved();
        KvStore::set('key', '42', 'integer');

        $this->assertSame(42, KvStore::get('key'));
    }

    /**
     * The guard is narrow on purpose: a database that cannot be reached is not
     * a missing table, and hiding it would turn an outage into an empty store.
     */
    public function test_a_failure_other_than_a_missing_table_is_not_swallowed(): void
    {
        $default = config('database.default');

        config([
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent-directory/kvstore.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'database.default' => 'unreachable',
        ]);
        Schema::clearResolvedInstances();

        try {
            KvStore::all();

            $this->fail('Expected the unreachable database to surface as a query exception.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('/nonexistent-directory/kvstore.sqlite', $e->getMessage());
        } finally {
            config(['database.default' => $default]);
            Schema::clearResolvedInstances();
            DB::purge('unreachable');
        }
    }
}
