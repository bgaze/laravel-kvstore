<?php

namespace Bgaze\KvStore;

use Illuminate\Support\Facades\Facade as Base;

/**
 * Description of Facade
 *
 * @author bgaze
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
