<?php

declare(strict_types=1);

namespace Bgaze\KvStore;

use Illuminate\Support\Facades\Facade as Base;

/**
 * @method static void set(string $key, mixed $value, string|false|null $type = null)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool has(string $key)
 * @method static void remove(string|list<string> $keys)
 * @method static \Illuminate\Support\Collection<string, mixed> all()
 * @method static void refresh()
 * @method static void flushResolved()
 *
 * @see Client
 */
class Facade extends Base
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
