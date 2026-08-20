# bgaze/laravel-kvstore

[![tests](https://img.shields.io/github/actions/workflow/status/bgaze/laravel-kvstore/tests.yml?branch=master&label=tests)](https://github.com/bgaze/laravel-kvstore/actions/workflows/tests.yml)
[![latest version](https://img.shields.io/packagist/v/bgaze/laravel-kvstore)](https://packagist.org/packages/bgaze/laravel-kvstore)
[![downloads](https://img.shields.io/packagist/dt/bgaze/laravel-kvstore)](https://packagist.org/packages/bgaze/laravel-kvstore)
[![licence](https://img.shields.io/packagist/l/bgaze/laravel-kvstore)](LICENSE)

A key-value store for Laravel that keeps your settings in the database, serves
them from cache in a single query, and remembers the type of each value.

```php
KvStore::set('maintenance', false, 'boolean');
KvStore::set('features', ['beta' => true], 'array');

if (KvStore::get('maintenance')) {
    // ...
}
```

Requires PHP 8.2+ and Laravel 12 or 13.

## Documentation

Full documentation is available at
[https://packages.bgaze.fr/laravel-kvstore](https://packages.bgaze.fr/laravel-kvstore).

A reference guide written for language models ships with the package, in
[`docs/llm/index.md`](docs/llm/index.md).

## Quick start

Install the package:

```
composer require bgaze/laravel-kvstore
```

Publish the migration and run it:

```
php artisan vendor:publish --tag=kvstore-migrations
php artisan migrate
```

The `KvStore` facade is then ready to use:

```php
// Write a value, with the cast to apply to it:
KvStore::set('name', 'a string');
KvStore::set('retries', '3', 'integer');
KvStore::set('features', ['beta' => true], 'array');

// Read it back, cast:
$retries = KvStore::get('retries');            // int 3
$default = KvStore::get('missing', 'fallback');

// Absent key, or a stored null?
KvStore::has('name');

// Read the whole store in one query:
$all = KvStore::all();

// Remove one or several entries:
KvStore::remove('name');
KvStore::remove(['retries', 'features']);
```

The same methods are available on the `Bgaze\KvStore\Client` service, which you
can inject rather than reaching for the facade.

## Casts

The cast is stored on the entry itself, so a value always reads back as what it
was written as. Supported: `array`, `bool`, `boolean`, `collection`, `date`,
`datetime`, `decimal`, `double`, `float`, `immutable_date`,
`immutable_datetime`, `int`, `integer`, `json`, `object`, `real`, `string`,
`timestamp`. Some take a parameter, as in `decimal:2` or `datetime:Y-m-d`.

The third argument of `set()` says what to do with the entry's cast — not what
the value is:

```php
KvStore::set('retries', '3', 'integer');   // set the cast
KvStore::set('retries', '5');              // keep it: still an integer
KvStore::set('retries', '5', 'string');    // replace it
KvStore::set('retries', '5', false);       // remove it
```

Anything else raises an exception, and nothing is written. Encrypted casts are
refused: the store caches values once cast, so an `encrypted:*` cast would write
the cleartext into your cache store.

## Cache

The whole store is cached as **one single entry**, values already cast. A cold
read costs one query for every key at once; every read afterwards costs none.
This suits a store read on most requests, and it means any write invalidates
every key together.

Writes through the facade invalidate the cache. So do writes through the
`Bgaze\KvStore\Entry` model, which covers seeders and direct model writes. A raw
SQL write cannot be seen — call `KvStore::refresh()` yourself after one.

Publish the config file to choose the table, the cache store, the cache entry
name, or to manage invalidation yourself:

```
php artisan vendor:publish --tag=kvstore-config
```

## Upgrading from 1.x

**There is no migration to run.** The migration is published, so your copy is
already in place.

| Change | What to do |
|---|---|
| Laravel 12 or 13 and PHP 8.2+ are now required | 1.x resolved against any Laravel version, which is what this closes |
| `Client` methods are no longer static | Use the `KvStore` facade, or inject `Bgaze\KvStore\Client`. The `kvstore.client` container key still works |
| The cast passed to `set()` is now validated | A free-form cast name used to reach the framework and fail there; it is now refused up front, by name |
| `encrypted:*` casts are refused | Encrypt the value yourself before storing it |
| Writes through the `Entry` model now invalidate the cache | Nothing, unless you relied on the stale read. Turn it off with `kvstore.cache.auto_invalidate` |
| The default cache entry name is now `kvstore` | Nothing. The old `settings-store-cache` entry is simply orphaned |
| The publish tag is now split | `--tag=kvstore-migrations`, `--tag=kvstore-config`, or `--tag=kvstore` for both |

1.x remains available on the `v1` branch.

## Contributing

Issues, feedback and pull requests are welcome. The suite and the static
analysis are the only oracle a change has here, so please keep them green:

```
composer update
vendor/bin/phpunit
vendor/bin/pint
vendor/bin/phpstan analyse
```

## Licence

MIT. See [LICENSE](LICENSE).
