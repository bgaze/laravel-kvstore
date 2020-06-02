# bgaze/laravel-kvstore

A simple and easy to use key-value database store for Laravel 5.5+

All values are stored into database and managed using cache to avoid unecessary queries.

Casting is supported to manage values type.

## Documentation

Full documentation is available at [https://packages.bgaze.fr/laravel-kvstore](https://packages.bgaze.fr/laravel-kvstore)

## Quick start

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

The `KvStore` facade offers static methods to manage the store content.  

```php
// Insert some values:
KvStore::set('value1', 'a string');
KvStore::set('value2', '11111', 'integer');
KvStore::set('value3', ['test' => true], 'array');

// Update value keeping cast type:
KvStore::set('value3', ['test' => false]);

// Update value changing cast type:
KvStore::set('value3', 22222, 'integer');

// Update value removing cast type.
KvStore::set('value3', 22222, false);

// Get value by key:
$value1 = KvStore::set('value1');

// Get value by key, passing a default value:
$value2 = KvStore::set('value2', 'default value');

// Remove an entry by key:
KvStore::remove('value1');

// Remove multiple entries by key:
KvStore::remove(['value1', 'value2']);
```