<?php

namespace Bgaze\KvStore;

use Illuminate\Support\Facades\Cache;
use Bgaze\KvStore\Entry as Store;

/**
 * Description of Settings
 *
 * @author bgaze
 */
class Client {

    /**
     * The cache key to use for the setting store.
     */
    const CACHE = 'settings-store-cache';

    /**
     * Create or update a setting value and type.
     * 
     * @param string $key
     * @param mixed $value
     * @param string|null $type
     * @return $this
     */
    public static function set($key, $value, $type = null) {
        $entry = Store::firstOrNew(['key' => $key]);
        
        if ($type === false) {
            $entry->type = null;
        } elseif (!empty($type)) {
            $entry->type = $type;
        }

        $entry->value = $value;
        
        $entry->save();

        self::refresh();
    }

    /**
     * Get a setting value by key.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function get($key, $default = null) {
        return self::all()->get($key, $default);
    }

    /**
     * Remove a setting by key.
     *
     * @param  string|array  $keys
     * @return $this
     */
    public static function remove($keys) {
        Store::destroy($keys);
        self::refresh();
    }

    /**
     * Load store content from cache, creating it if needed.
     * 
     * @return \Illuminate\Support\Collection
     */
    public static function all() {
        return Cache::rememberForever(self::CACHE, function () {
                    return Store::all()->pluck('value', 'key');
                });
    }

    /**
     * Force cache refresh.
     * 
     * @return $this
     */
    public static function refresh() {
        Cache::forget(self::CACHE);
    }

}
