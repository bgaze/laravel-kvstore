<?php

declare(strict_types=1);

namespace Bgaze\KvStore;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

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

    /**
     * The store resolved for the current request.
     *
     * Reading the whole store means fetching one cache entry and applying a
     * cast per value; memoising that spares every read after the first. The
     * client is a singleton, so on an event-driven server this outlives the
     * request: the service provider wires flushResolved() to Octane for that.
     *
     * @var Collection<string, mixed>|null
     */
    private ?Collection $resolved = null;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
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
     * cold read and none afterwards, and one cache read per request.
     *
     * @return Collection<string, mixed>
     */
    public function all(): Collection
    {
        return $this->resolved ??= $this->cast($this->raw());
    }

    /**
     * Drop the cached store, so that the next read hits the database.
     *
     * Only needed for a write the package cannot observe, that is a raw SQL
     * write. Writes going through the facade or the model are covered.
     */
    public function refresh(): void
    {
        $this->resolved = null;

        $this->store()->forget($this->cacheKey());
    }

    /**
     * Drop the store resolved for the current request, leaving the shared cache
     * alone.
     *
     * Wired to Octane by the service provider so a worker does not serve one
     * request the store it resolved for another. Rarely needed by hand.
     */
    public function flushResolved(): void
    {
        $this->resolved = null;
    }

    /**
     * Get the store as it is cached: raw values and their cast name, no objects.
     *
     * Nothing but strings and nulls goes into the cache. Caching values once
     * cast would put Carbon, Collection and stdClass instances in there, which
     * a store told to restrict unserialization cannot rebuild.
     *
     * @return array<string, array{value: string|null, type: string|null}>
     */
    private function raw(): array
    {
        $store = $this->store();
        $key = $this->cacheKey();

        $cached = $store->get($key);

        if ($this->isRawStore($cached)) {
            return $cached;
        }

        try {
            $loaded = $this->load();
        } catch (QueryException $e) {
            if (! $this->tableIsMissing()) {
                throw $e;
            }

            $this->logger->warning(
                'The kvstore table does not exist yet, so the store reads as empty. '
                .'This happens when the store is read before its migration has run.'
            );

            // Not cached: the next read tries again, so this heals by itself
            // once the migration has run.
            return [];
        }

        $store->forever($key, $loaded);

        return $loaded;
    }

    /**
     * Read every entry from the database, without casting anything.
     *
     * @return array<string, array{value: string|null, type: string|null}>
     */
    private function load(): array
    {
        return Entry::all()
            ->mapWithKeys(fn (Entry $entry): array => [
                $entry->key => [
                    'value' => $entry->getRawOriginal('value'),
                    'type' => $entry->type,
                ],
            ])
            ->all();
    }

    /**
     * Apply each entry's own cast to its raw value.
     *
     * @param  array<string, array{value: string|null, type: string|null}>  $raw
     * @return Collection<string, mixed>
     */
    private function cast(array $raw): Collection
    {
        return new Collection(array_map(
            function (array $entry): mixed {
                $type = $entry['type'];

                if (! is_string($type) || $type === '') {
                    return $entry['value'];
                }

                // Both raw attributes go in with one call on purpose: the cast
                // is read from the entry's own type, and hydrating them in two
                // steps lets the second call wipe what the first one set.
                return (new Entry)
                    ->setRawAttributes(['value' => $entry['value'], 'type' => $type], true)
                    ->value;
            },
            $raw
        ));
    }

    /**
     * Determine whether a cached payload is in the shape this version writes.
     *
     * The previous major cached a collection of values already cast. Rejecting
     * anything else rebuilds the entry instead of failing on it, so upgrading
     * needs no cache flush.
     *
     * @phpstan-assert-if-true array<string, array{value: string|null, type: string|null}> $payload
     */
    private function isRawStore(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach ($payload as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)) {
                return false;
            }

            foreach (['value', 'type'] as $attribute) {
                if (! array_key_exists($attribute, $entry)) {
                    return false;
                }

                if ($entry[$attribute] !== null && ! is_string($entry[$attribute])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine whether the store table is simply not there yet.
     *
     * Answering "no" when it cannot be told is deliberate: the caller then
     * rethrows the original failure rather than hiding it.
     */
    private function tableIsMissing(): bool
    {
        try {
            $entry = new Entry;

            return ! $entry->getConnection()->getSchemaBuilder()->hasTable($entry->getTable());
        } catch (Throwable) {
            return false;
        }
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
                "The [{$type}] cast is not supported: the store is read as a whole, so "
                .'every encrypted entry would be decrypted on every request whether it is '
                .'used or not. Encrypt the value yourself before handing it over instead.'
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
