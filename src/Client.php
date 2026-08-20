<?php

declare(strict_types=1);

namespace Bgaze\KvStore;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The public API of the store.
 */
class Client
{
    /**
     * The casts an entry may declare.
     *
     * @var list<string>
     */
    private const CASTS = [
        'array',
        'bool',
        'boolean',
        'collection',
        'date',
        'datetime',
        'decimal',
        'double',
        'float',
        'immutable_date',
        'immutable_datetime',
        'int',
        'integer',
        'json',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    /**
     * The casts taking a parameter, as in "decimal:2" or "datetime:Y-m-d".
     *
     * @var list<string>
     */
    private const PARAMETERISED_CASTS = [
        'date',
        'datetime',
        'decimal',
        'immutable_date',
        'immutable_datetime',
    ];

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Create or update an entry.
     *
     * The third argument is three-state: null or omitted keeps the cast the
     * entry already carries, a cast name replaces it, and false removes it.
     *
     * @throws InvalidArgumentException on an unsupported cast name.
     */
    public function set(string $key, mixed $value, string|false|null $type = null): void
    {
        if (is_string($type)) {
            $this->validateCast($type);
        }

        $entry = Entry::firstOrNew(['key' => $key]);

        // The type has to be assigned before the value: assigning the value
        // serialises it through the cast the entry carries at that very moment.
        // Swapping these two statements writes the value through the previous
        // cast instead, silently. TypeSemanticsTest covers the invariant.
        if ($type === false) {
            $entry->type = null;
        } elseif ($type !== null) {
            $entry->type = $type;
        }

        $entry->value = $value;

        $entry->save();

        $this->refresh();
    }

    /**
     * Get the value of an entry, or the given default when the key is absent.
     *
     * A stored null is a value, not an absence: the default only stands in for
     * a missing key. Use has() to tell one from the other.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()->get($key, $default);
    }

    /**
     * Determine whether the store holds an entry under the given key.
     */
    public function has(string $key): bool
    {
        return $this->all()->has($key);
    }

    /**
     * Remove one or several entries.
     *
     * @param  string|list<string>  $keys
     */
    public function remove(string|array $keys): void
    {
        Entry::destroy($keys);

        $this->refresh();
    }

    /**
     * Get the whole store, keyed by entry key, with every cast applied.
     *
     * The store is cached as one single entry, so this costs one query on a
     * cold read and none afterwards.
     *
     * @return Collection<string, mixed>
     */
    public function all(): Collection
    {
        /** @var Collection<string, mixed> */
        return $this->store()->rememberForever(
            $this->cacheKey(),
            fn (): Collection => Entry::all()->pluck('value', 'key')
        );
    }

    /**
     * Drop the cached store, so that the next read hits the database.
     *
     * Only needed for a write the package cannot observe, that is a raw SQL
     * write. Writes going through the facade or the model are covered.
     */
    public function refresh(): void
    {
        $this->store()->forget($this->cacheKey());
    }

    /**
     * Reject a cast the store does not support.
     *
     * @throws InvalidArgumentException
     */
    private function validateCast(string $type): void
    {
        if (str_starts_with($type, 'encrypted')) {
            throw new InvalidArgumentException(
                "The [{$type}] cast is not supported: the store caches values once cast, "
                .'so the cleartext would be written to the cache store. Encrypt the value '
                .'yourself before handing it over instead.'
            );
        }

        [$name, $parameter] = array_pad(explode(':', $type, 2), 2, null);

        if (! in_array($name, self::CASTS, true)) {
            throw new InvalidArgumentException(
                "The [{$type}] cast is not supported. Supported casts are: "
                .implode(', ', self::CASTS).'.'
            );
        }

        if ($parameter !== null && ! in_array($name, self::PARAMETERISED_CASTS, true)) {
            throw new InvalidArgumentException("The [{$name}] cast does not take a parameter.");
        }
    }

    /**
     * Get the cache store holding the store.
     */
    private function store(): CacheRepository
    {
        $store = $this->config->get('kvstore.cache.store');

        return $this->cache->store(is_string($store) ? $store : null);
    }

    /**
     * Get the cache entry name holding the store.
     */
    private function cacheKey(): string
    {
        $key = $this->config->get('kvstore.cache.key');

        return is_string($key) && $key !== '' ? $key : 'kvstore';
    }
}
