<?php

declare(strict_types=1);

namespace Bgaze\KvStore;

use DateTimeInterface;
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
     * The store as it is cached, held for the current request.
     *
     * What a read costs is the cache round-trip, not the casting, so this is
     * the memo that matters. The client is a singleton, so on an event-driven
     * server it outlives the request: the service provider wires
     * flushResolved() to Octane for that.
     *
     * @var array<string, array{value: string|null, type: string|null}>|null
     */
    private ?array $rawStore = null;

    /**
     * Values already cast, per key.
     *
     * get() and has() cast the key being read rather than the whole store, so
     * an entry the framework cannot cast only breaks the key that carries it.
     *
     * @var array<string, mixed>
     */
    private array $castValues = [];

    /**
     * The whole store, cast, held for the current request.
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

        $this->refuseAnUnstorableValue($key, $value, $entry);

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
        $raw = $this->raw();

        if (! array_key_exists($key, $raw)) {
            return value($default);
        }

        // array_key_exists rather than ??=: a value that is legitimately null
        // is cached too, instead of being cast again on every read.
        if (! array_key_exists($key, $this->castValues)) {
            $this->castValues[$key] = $this->castOne($raw[$key]);
        }

        return $this->castValues[$key];
    }

    /**
     * Determine whether the store holds an entry under the given key.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->raw());
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
     * Casting the whole store is what this method is for, so unlike get() it
     * cannot survive an entry the framework refuses to cast.
     *
     * @return Collection<string, mixed>
     */
    public function all(): Collection
    {
        if ($this->resolved instanceof Collection) {
            return $this->resolved;
        }

        $values = [];

        foreach ($this->raw() as $key => $entry) {
            if (! array_key_exists($key, $this->castValues)) {
                $this->castValues[$key] = $this->castOne($entry);
            }

            $values[$key] = $this->castValues[$key];
        }

        return $this->resolved = new Collection($values);
    }

    /**
     * Drop the cached store, so that the next read hits the database.
     *
     * Only needed for a write the package cannot observe, that is a raw SQL
     * write. Writes going through the facade or the model are covered.
     */
    public function refresh(): void
    {
        $this->flushResolved();

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
        $this->rawStore = null;
        $this->castValues = [];
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
        if ($this->rawStore !== null) {
            return $this->rawStore;
        }

        $store = $this->store();
        $key = $this->cacheKey();

        $cached = $store->get($key);

        if ($this->isRawStore($cached)) {
            return $this->rawStore = $cached;
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

            // Not cached: the next request tries again, so this heals by
            // itself once the migration has run.
            return $this->rawStore = [];
        }

        $store->forever($key, $loaded);

        return $this->rawStore = $loaded;
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
     * Apply an entry's own cast to its raw value.
     *
     * @param  array{value: string|null, type: string|null}  $entry
     */
    private function castOne(array $entry): mixed
    {
        $type = $entry['type'];

        if (! is_string($type) || $type === '') {
            return $entry['value'];
        }

        // Both raw attributes go in with one call on purpose: the cast is read
        // from the entry's own type, and hydrating them in two steps lets the
        // second call wipe what the first one set.
        return (new Entry)
            ->setRawAttributes(['value' => $entry['value'], 'type' => $type], true)
            ->value;
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
     * Refuse a value the store cannot write, before it reaches the database.
     *
     * A cast that serialises the value turns it into a string as it is
     * assigned. Without one, the value reaches a text column as it is: an array
     * either fails the insert, or -- on an update -- gets serialised by the
     * driver grammar and read back as a raw JSON string, silently degraded. Both
     * are worse than an error at the call site.
     *
     * The check reads the attribute *after* assignment rather than reasoning
     * about which casts serialise, so it holds whatever the framework does: what
     * sits there is exactly what would be bound to the query.
     *
     * @throws InvalidArgumentException
     */
    private function refuseAnUnstorableValue(string $key, mixed $value, Entry $entry): void
    {
        if ($this->isStorableAsText($entry->getAttributes()['value'] ?? null)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Storing a value of type [%s] under [%s] needs a cast that serialises it, '
            .'such as [array] or [json]: as it stands the value would reach a text '
            .'column unserialised. Pass such a cast, or serialise the value yourself.',
            get_debug_type($value),
            $key
        ));
    }

    /**
     * Determine whether a value can go into a text column as it is.
     *
     * Dates and stringable objects are converted on their way to the query, so
     * they need no cast of their own.
     */
    private function isStorableAsText(mixed $value): bool
    {
        return $value === null
            || is_scalar($value)
            || $value instanceof DateTimeInterface
            || (is_object($value) && method_exists($value, '__toString'));
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
                "The [{$type}] cast is not supported: all() casts every entry, so a value "
                .'that cannot be decrypted -- after an APP_KEY rotation, say -- would make '
                .'the whole store unreadable instead of only its own key. Encrypt the value '
                .'yourself before handing it over, and decrypt it where you control it.'
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
