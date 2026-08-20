<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;

/**
 * The migration is shipped to be published, so its shape is part of the public
 * contract. The suite runs on SQLite, which cannot show everything a consumer
 * gets: the MySQL DDL is compiled without a server to cover the rest.
 */
class MigrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.mysql_ddl', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'ddl',
            'username' => 'ddl',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }

    public function test_the_table_carries_three_columns(): void
    {
        $columns = [];

        foreach (Schema::getColumns('kvstore') as $column) {
            $columns[$column['name']] = [$column['type'], $column['nullable']];
        }

        $this->assertSame([
            'key' => ['varchar', false],
            'type' => ['varchar', true],
            'value' => ['text', true],
        ], $columns);
    }

    public function test_the_key_column_is_the_only_index(): void
    {
        $indexes = array_values(array_map(
            fn (array $index): array => [$index['columns'], $index['unique'], $index['primary']],
            Schema::getIndexes('kvstore')
        ));

        $this->assertSame([[['key'], true, true]], $indexes);
    }

    /**
     * The key column is bounded so that a primary key on it stays within the
     * index size limit of older MySQL versions running utf8mb4.
     */
    public function test_the_shipped_definition_compiles_to_a_single_mysql_statement(): void
    {
        $this->assertSame([
            'create table `kvstore` ('
                .'`key` varchar(191) not null, '
                .'`type` varchar(255) null, '
                .'`value` text null, '
                .'primary key (`key`)'
                .") default character set utf8mb4 collate 'utf8mb4_unicode_ci'",
        ], $this->shippedMysqlDdl());
    }

    public function test_the_migration_is_anonymous(): void
    {
        $migration = $this->shippedMigration();

        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue((new ReflectionClass($migration))->isAnonymous());
    }

    /**
     * Compile the DDL of the shipped migration through the MySQL grammar, by
     * making the probe connection the default one and running the migration
     * file itself rather than a copy of its definition.
     *
     * @return array<int, string>
     */
    private function shippedMysqlDdl(): array
    {
        $migration = $this->shippedMigration();

        $default = config('database.default');

        try {
            config(['database.default' => 'mysql_ddl']);
            Schema::clearResolvedInstances();

            $up = new ReflectionMethod($migration, 'up');

            $queries = DB::connection('mysql_ddl')->pretend(function () use ($up, $migration): void {
                $up->invoke($migration);
            });
        } finally {
            config(['database.default' => $default]);
            Schema::clearResolvedInstances();
            DB::purge('mysql_ddl');
        }

        return array_map(fn (array $query): string => (string) $query['query'], $queries);
    }

    private function shippedMigration(): Migration
    {
        $migration = require __DIR__.'/../src/migrations/2019_09_29_000000_create_kvstore_table.php';

        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
