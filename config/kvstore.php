<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    |
    | The database table holding the store entries. Changing it after the
    | migration has run requires renaming the table yourself.
    |
    */

    'table' => 'kvstore',

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | The whole store is cached as one single entry holding every key, its raw
    | value and its cast name, so a cold read costs one query for the whole
    | store. Casts are applied when the store is read, never before it is
    | cached: nothing but strings and nulls goes in there.
    |
    | "store" is the cache store to use, or null for the application default.
    | "key" is the cache entry name. "auto_invalidate" drops that entry as soon
    | as an entry is saved or deleted through the model, which covers writes
    | that bypass the facade -- a seeder, or a direct model write. A raw SQL
    | write cannot be observed: call KvStore::refresh() yourself after one.
    |
    */

    'cache' => [

        'store' => null,

        'key' => 'kvstore',

        'auto_invalidate' => true,

    ],

];
