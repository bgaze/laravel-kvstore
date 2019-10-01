<?php

namespace Bgaze\KvStore;

use Illuminate\Support\Facades\Facade as Base;

/**
 * @method static void set($key, $value, $type = null)
 * @method static mixed get($key, $default = null)
 * @method static void remove($keys)
 * @method static \Illuminate\Support\Collection all()
 * @method static void refresh()
 *
 * @see \Bgaze\KvStore\Client
 */
class Facade extends Base {

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor() {
        return 'kvstore.client';
    }

}
