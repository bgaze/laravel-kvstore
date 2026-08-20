# bgaze/laravel-kvstore — usage guide

A database-backed key-value store for Laravel, cached as a whole and with a
per-entry cast. Intended for settings-sized data: tens or hundreds of entries
read on most requests, written rarely.

This file is the reference for the package's public behaviour. It is versioned
with the code and updated in the same commit as the behaviour it describes.

- Package: `bgaze/laravel-kvstore` (Packagist), MIT.
- Requires PHP 8.2+ and Laravel 12 or 13.
- Facade: `KvStore` (`Bgaze\KvStore\Facade`). Service: `Bgaze\KvStore\Client`.

## Install

```
composer require bgaze/laravel-kvstore
php artisan vendor:publish --tag=kvstore-migrations
php artisan migrate
```

The service provider and the `KvStore` alias are registered by package
discovery. Publishing the config file is optional:

```
php artisan vendor:publish --tag=kvstore-config
```

`--tag=kvstore` publishes both the migration and the config.

## Public API

Every method is available on the facade and on the injectable service. The
service is a singleton bound on `Bgaze\KvStore\Client`, with `kvstore.client`
kept as an alias.

| Method | Signature | Returns |
|---|---|---|
| `set` | `set(string $key, mixed $value, string\|false\|null $type = null): void` | — |
| `get` | `get(string $key, mixed $default = null): mixed` | the cast value, or `$default` when the key is absent |
| `has` | `has(string $key): bool` | whether the key exists |
| `remove` | `remove(string\|array $keys): void` | — |
| `all` | `all(): Collection<string, mixed>` | the whole store, keyed by key, values cast |
| `refresh` | `refresh(): void` | — |
| `flushResolved` | `flushResolved(): void` | — |

```php
use Bgaze\KvStore\Client;

class Settings
{
    public function __construct(private readonly Client $store) {}
}
```

## The third argument of set() is three-state

This is the part callers get wrong. `$type` does not describe the value being
written; it describes what to do with the cast stored on the entry.

| `set($key, $value, $type)` | Effect on the entry's cast |
|---|---|
| omitted, or `null` | keep the cast the entry already carries |
| a cast name, e.g. `'integer'` | replace the cast with that one |
| `false` | remove the cast |

```php
KvStore::set('retries', '3', 'integer');   // cast set
KvStore::set('retries', '5');              // still an integer
KvStore::set('retries', '5', 'string');    // now a string
KvStore::set('retries', '5', false);       // no cast at all
```

Anything else — `''`, `'0'`, an unknown name — raises
`InvalidArgumentException`. Nothing is written when it does.

## Casts

The cast is stored per entry, in the row's own `type` column, and applied when
the value is read. Supported names:

`array`, `bool`, `boolean`, `collection`, `date`, `datetime`, `decimal`,
`double`, `float`, `immutable_date`, `immutable_datetime`, `int`, `integer`,
`json`, `object`, `real`, `string`, `timestamp`.

`date`, `datetime`, `decimal`, `immutable_date` and `immutable_datetime` accept
a parameter: `decimal:2`, `datetime:Y-m-d H:i`.

**Encrypted casts are refused.** The store is read as a whole, so every
encrypted entry would be decrypted on every request whether it is used or not.
Encrypt the value yourself before handing it over if you need that.

A `null` written on a JSON-backed cast (`array`, `json`, `object`,
`collection`) is stored as an empty array, so such an entry always reads back as
the collection type it declares rather than as a `null` the caller has to guard.
On any other cast a `null` stays `null`.

`get()` returns `$default` only when the key is **absent**. A stored `null`
comes back as `null`, whatever the default. Use `has()` to tell them apart.

## Cache model

The store is cached as **one single entry** holding every key, its **raw** value
and its cast name:

```php
['retries' => ['value' => '3', 'type' => 'integer'], ...]
```

Nothing but strings and nulls goes into the cache — never a value already cast.
That matters: caching cast values would put `Carbon`, `Collection` and
`stdClass` instances in there, and a cache store told to restrict
unserialization (`cache.serializable_classes`) cannot rebuild them. Casts are
applied when the store is read.

A cold read costs **one query for the whole store**. The result is then held for
the rest of the request, so repeated reads cost neither a query nor a cache
round-trip.

This is not a per-key cache and must not be mistaken for one. The trade-off is
deliberate: it suits a store read almost entirely on every request, and it means
any write drops the cache for every key at once.

- `set()` and `remove()` drop the cached store and the one held in memory.
- Saving or deleting an `Entry` through the model drops them too, so a seeder or
  a direct model write does not leave stale reads behind. Turn this off with
  `kvstore.cache.auto_invalidate` if you want to manage invalidation yourself.
- A **raw SQL write cannot be observed at all**. Call `KvStore::refresh()`
  yourself after one.
- `flushResolved()` drops only the store held in memory, leaving the shared
  cache alone. On Octane it is called at the start of every request, so a worker
  never serves one request the store it resolved for another. You rarely need it
  by hand.

**A payload written by an earlier version is rebuilt, not read.** Upgrading needs
no cache flush.

## Reading the store before its table exists

An application that reads a setting while booting cannot start at all before the
migration has run — which would also break the very command that creates the
table. So when the table is missing, the store reads as **empty** and logs a
warning, and that emptiness is **not cached**: the next read tries again, so it
heals by itself once the migration has run.

Any other database failure is raised as usual. The guard is narrow on purpose:
hiding an unreachable database would turn an outage into a silently empty
store.

## Configuration

Defaults are merged by the service provider, so they hold whether or not the
file is published.

```php
// config/kvstore.php
return [
    'table' => 'kvstore',
    'cache' => [
        'store' => null,            // null = the application default store
        'key' => 'kvstore',
        'auto_invalidate' => true,
    ],
];
```

The migration reads `kvstore.table` too, so renaming the table before running it
is enough.

## The model

`Bgaze\KvStore\Entry` is a plain Eloquent model over the store table: string
primary key `key`, non-incrementing, no timestamps, no soft deletes. Writing
through it is supported and invalidates the cache, as described above.

Its `getCasts()` is overridden so the row's own `type` column drives the cast at
runtime. This rides on a framework method, which is why the suite covers every
supported cast end to end: a Laravel change to that contract would otherwise
break casting silently.

## Known limitations

- **Removing a cast while writing a value that needs one degrades the value.**
  `set($key, ['a'], false)` on an entry that carried a JSON cast leaves the
  value unserialised; on an update the database driver serialises it on its own
  and the value reads back as the raw JSON string `'["a"]'`, on an insert the
  write fails. Remove the cast and write a scalar, in that order, or keep the
  cast.
- **The whole store is one cache entry**, so a write invalidates every key. This
  is the intended design, not an oversight.
- **`value` is a `text` column**, so roughly 64 KB per entry. Change the column
  in the published migration if you need more.

## Upgrading from 2.0

Nothing to do. The cached payload changed shape and is rebuilt on first read.
`flushResolved()` is new, and the store is now held in memory for the duration of
a request.

## Upgrading from 1.x

- Laravel 12 or 13 and PHP 8.2+ are now required. 1.x resolved against any
  Laravel version, which is what this closes.
- `Client` methods are no longer static. Calling `Client::set()` directly breaks;
  the facade, and the `kvstore.client` container key, are unchanged.
- `$type` is now validated, and `encrypted:*` is refused. A call that passed a
  free-form cast name used to reach the framework and fail there.
- The default cache entry name moved from `settings-store-cache` to `kvstore`.
  The old entry is simply orphaned; nothing else changes.
- **No migration to run.** The migration is published, so the consumer's copy is
  already in place.

## Sources

Every claim above is checkable against these files.

| Claim | Source |
|---|---|
| public API, three-state `$type`, cast whitelist, refusals | `src/Client.php` |
| runtime casting, `null` coercion, table from config | `src/Entry.php` |
| facade accessor and method map | `src/Facade.php` |
| binding, aliasing, publish tags, automatic invalidation | `src/ServiceProvider.php` |
| configuration keys and defaults | `config/kvstore.php` |
| shipped schema | `src/migrations/2019_09_29_000000_create_kvstore_table.php` |
| every behaviour above, as executable expectations | `tests/` |
