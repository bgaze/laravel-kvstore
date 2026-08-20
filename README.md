# bgaze/laravel-kvstore — 1.x

A simple and easy to use key-value database store for Laravel 5.5+.

All values are stored in the database and managed using cache to avoid unnecessary queries.
Casting is supported to manage value types.

> **This branch holds the 1.x line and is no longer maintained.**
> The current version requires PHP 8.2+ and Laravel 12 or 13, is documented at
> [packages.bgaze.fr/laravel-kvstore](https://packages.bgaze.fr/laravel-kvstore), and introduces
> breaking changes — its *Upgrading from v1* section lists them.
> This file is the complete documentation of the 1.x line.

## Installation

Install the package, pinned to the 1.x line:

```
composer require bgaze/laravel-kvstore:^1.1
```

Publish the required migration:

```
php artisan vendor:publish --tag=kvstore
```

Then create the table:

```
php artisan migrate
```

The service provider and the `KvStore` alias are registered by package discovery.

## Usage

- The `KvStore` facade offers static methods to manage the store content.
- All the store values are stored in the database but managed using cache to avoid unnecessary
  queries.
- When the stored content is modified, or when the application cache is cleared, the store cache is
  automatically regenerated.

### Add / edit entry

To create or update an entry, use the `set` function:

```php
/**
 * @param string $key
 * @param mixed $value
 * @param string|null $type
 * @return void
 */
public static function set($key, $value, $type = null)
```

The `$type` argument allows you to automatically cast the entry value.

Supported cast types are: `integer`, `real`, `float`, `double`, `decimal:<digits>`,
`string`, `boolean`, `object`, `array`, `collection`, `date`, `datetime`, `timestamp`.
When casting to decimal, you must define the number of digits (`decimal:2`).

This feature uses in the background Eloquent Model's Attribute Casting, please see
[documentation](https://laravel.com/docs/master/eloquent-mutators#attribute-casting) for more
details. The cast name is passed to the framework as it is, and is not validated here.

**Examples:**

```php
// Insert some values:
KvStore::set('value1', 'a string');
KvStore::set('value2', '11111', 'integer');
KvStore::set('value3', ['test' => true], 'array');
KvStore::set('value4', now(), 'datetime');

// Update value keeping cast type:
KvStore::set('value3', ['test' => false]);

// Update value changing cast type:
KvStore::set('value3', 22222, 'integer');

// Update value removing cast type.
KvStore::set('value3', 22222, false);
```

### Retrieve entries

Use the `get` function to retrieve entries from the store.
If the key doesn't exist, `null` will be returned unless a default value is specified.

```php
/**
 * @param  string  $key
 * @param  mixed  $default
 * @return mixed
 */
public static function get($key, $default = null)
```

You can also get all the store content as a collection using the `all` function:

```php
/**
 * @return \Illuminate\Support\Collection
 */
public static function all()
```

**Examples:**

```php
// Get value by key:
$value1 = KvStore::get('value1');

// Get value by key, passing a default value:
$value2 = KvStore::get('value2', 'default value');

// Get all the store content and convert it to array:
$store = KvStore::all()->toArray();
```

### Remove entries

Entries can be removed from the store using `remove` function:

```php
/**
 * @param  string|array  $keys
 * @return void
 */
public static function remove($keys)
```

**Examples:**

```php
// Remove an entry by key:
KvStore::remove('value1');

// Remove multiple entries by key:
KvStore::remove(['value1', 'value2']);
```

### Force cache refresh

Use the `refresh` function to force a cache refresh on next `get` or `all` call.

```php
/**
 * @return void
 */
public static function refresh()
```

## How it works

- The store lives in the `kvstore` table: a string primary key `key`, a nullable `type` column
  holding the cast name, and a nullable `text` column holding the value.
- `Bgaze\KvStore\Entry` is the Eloquent model over that table. It has no timestamps, and its
  `getCasts()` is overridden so the row's own `type` column drives the cast at runtime.
- `all()` reads the whole store and caches it forever under the `settings-store-cache` key, so
  `get()` resolves from that single cached collection.
- `set()` and `remove()` forget that cache entry; the next read rebuilds it.
- Writing through the `Entry` model directly, or through raw SQL, does **not** invalidate the cache.
  Call `KvStore::refresh()` yourself after such a write.

## Contributing

The 1.x line only receives what keeps it installable. Issues and pull requests are welcome on the
current version, on the `master` branch.

## Licence

MIT. See [LICENSE](LICENSE).
