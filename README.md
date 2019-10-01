# bgaze/laravel-kvstore

A simple and easy to use key-value database store for Laravel 5.5+

All values are stored into database and managed using cache to avoid unecessary queries.

Casting is supported to manage values type.

## Installation

Install the package using composer:

```
composer install bgaze/laravel-kvstore
```

Publish the required migration:

```
php artisan vendor:publish --tag=kvstore
```

Then create the table:

```
php artisan migrate
```

## Usage

The `KvStore` facade offers static methods to manage the store content.  

To add or modify a value, use the `set` function:

```php
// Insert some values:
KvStore::set('value1', 'a string');
KvStore::set('value2', '11111', 'integer');
KvStore::set('value3', ['test' => true], 'array');
KvStore::set('value4', \Carbon\Carbon::now(), 'datetime');

// Update value keeping cast type:
KvStore::set('value3', ['test' => false]);

// Update value changing cast type:
KvStore::set('value3', 22222, 'integer');

// Update value removing cast type.
KvStore::set('value3', 22222, false);
```

Use the `get` function to retrieve entries from the store.  
If the key doesn't exists, `null` will be returned unless a default value is passed to the function.

```php
// Get value by key:
$value1 = KvStore::set('value1');

// Get value by key, passing a default value:
$value2 = KvStore::set('value2', 'default value');
```

You can also get all the store content as a collection using the `all` function:

```php
// Get the store content and convert it to array:
$store = KvStore::all()->toArray();
```

Finally, you can remove entries from the store using `remove` function:

```php
// Remove an entry by key:
KvStore::remove('value1');

// Remove multiple entries by key:
KvStore::remove(['value1', 'value2']);
```

## Values casting

Supported cast types are: `integer`, `real`, `float`, `double`, `decimal:<digits>`,
`string`, `boolean`, `object`, `array`, `collection`, `date`, `datetime`, `and timestamp`.  
When casting to decimal, you must define the number of digits (decimal:2).

Values casting uses in background Eloquent Model's Attribute Casting, please see
[documentation](https://laravel.com/docs/master/eloquent-mutators#attribute-casting) for more details.

## Cache managment.

All the store values are stored into database but managed using cache to avoid unecessary queries.

When the stored content is modified, or when Laravel application cache is cleared, the store cache is automatically regenerated.

## Available functions

**set:**

Create or update a setting value.

```php
/**
 * @param string $key
 * @param mixed $value
 * @param string|null $type
 * @return $this
 */
public static function set($key, $value, $type = null)
```

**get:**

Get a setting value by key.

```php
/**
 * @param  string  $key
 * @param  mixed  $default
 * @return mixed
 */
public static function get($key, $default = null)
```

**remove:**

Delete a setting by key.

```php
/**
 * @param  string|array  $keys
 * @return $this
 */
public static function remove($keys)
```

**all:**

Get all store content as a key-value collection.

```php
/**
 * @return \Illuminate\Support\Collection
 */
public static function all()
```

**refresh:**

Purge cache to force a refresh on next `get` or `all` call.

```php
/**
 * @return $this
 */
public static function refresh()
```


