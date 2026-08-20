# bgaze/laravel-kvstore

Database-backed key-value store for Laravel, cached to avoid repeat queries, with per-entry type casting.
Composer **package** (library, not an app), exposing a `KvStore` facade. Public open-source
(GitHub / Packagist), MIT.

> **This file is public.** It lives in a public repository and ships inside every consumer's `vendor/`
> directory. Keep it self-contained: repo-relative paths and public URLs only — no absolute or machine-local
> paths, no personal tooling, no internal process references. Anything a reader outside this repository
> cannot reach does not belong here.

## Status

Current major is **v2**, on `master`. v1 is on the `v1` maintenance branch.

**The suite is the only oracle.** A library has no application to click: a behaviour that is not expressed
as an expectation is not specified, it is a coincidence the next refactor is free to break. Tests ship in
the **same commit** as the code they cover, and so does `docs/llm/index.md` — a divergence there is a
defect, on the same footing as a failing test.

Run the gate before any commit:

```
vendor/bin/phpunit
vendor/bin/pint
vendor/bin/phpstan analyse
```

## Stack

| Item      | Value                                                                     |
|-----------|---------------------------------------------------------------------------|
| Language  | PHP 8.2+, `declare(strict_types=1)`, native types                         |
| Framework | `illuminate/support` + `illuminate/database` + `illuminate/cache`, `^12 \|\| ^13` |
| Autoload  | PSR-4 `Bgaze\KvStore\` → `src`, `Bgaze\KvStore\Tests\` → `tests`          |
| Discovery | Laravel package auto-discovery (`extra.laravel` in `composer.json`)        |
| Tests     | PHPUnit + Orchestra Testbench, in-memory SQLite, `array` cache store       |
| Style     | Pint, `laravel` preset                                                    |
| Analysis  | PHPStan level 8 via Larastan, on `src` and `tests`                        |
| CI        | `.github/workflows/tests.yml` — PHP 8.2-8.4 × Testbench 10/11, plus static |
| Released  | `v1.0.0`, `v1.1.0`, `v2.0.0`                                              |

## Architecture

Five source files plus a config file, no abstraction layer:

- `src/Client.php` — the whole public API: `set()`, `get()`, `has()`, `remove()`, `all()`, `refresh()`,
  `flushResolved()`. Reads go through `raw()` (cached payload) then `castOne()` (one entry). An injectable service taking the cache factory, the config repository and a logger;
  bound as a singleton on `Client::class`, with `kvstore.client` kept as an alias.
- `src/Entry.php` — the Eloquent model over the store table. String primary key `key`, non-incrementing,
  no timestamps. `getTable()` reads `kvstore.table`. `getCasts()` is **overridden** so the row's own `type`
  column drives the cast at runtime; `setAttribute()` coerces `null` to `[]` for JSON-castable values.
- `src/Facade.php` — the `KvStore` facade, resolving `Client::class`.
- `src/ServiceProvider.php` — binds and aliases the client in `register()`, merges the config, publishes
  config and migration under their own tags, and registers the cache invalidation listeners.
- `src/migrations/2019_09_29_000000_create_kvstore_table.php` — anonymous migration for the table named by
  `kvstore.table`: `key` (string 191, primary), `type` (string, nullable), `value` (text, nullable).
- `config/kvstore.php` — `table`, `cache.store`, `cache.key`, `cache.auto_invalidate`. Merged by the
  provider, so the defaults hold without publishing.

**Caching model.** The store is cached as **one single entry** (key `kvstore.cache.key`) holding every key,
its **raw** value and its cast name — `['k' => ['value' => '3', 'type' => 'integer']]`. **Never a value
already cast**: that would put `Carbon` / `Collection` / `stdClass` in the cache, which a store configured
with `cache.serializable_classes` cannot rebuild — it returns `__PHP_Incomplete_Class` and every read blows
up. Casts are applied on read. Every write drops the entry wholesale, so the next read repopulates the whole
store in one query. Deliberate for a settings-sized store; it is not a per-key cache.

A payload in an older shape is **rebuilt rather than read** (`Client::isRawStore()`), so upgrading needs no
cache flush.

**Memoisation — three fields, one place that clears them.** `Client::$rawStore` holds the cached payload
(the memo that matters: the cost is the cache round-trip, not the casting), `$castValues` holds cast values
**per key**, `$resolved` holds the whole cast store for `all()`. `refresh()` delegates to `flushResolved()`
so there is exactly one place that clears all three — do not clear them field by field anywhere else.
The client is a **singleton**, so that state outlives the request on an event-driven server: the provider
wires `flushResolved()` to Octane's `RequestReceived`. Removing that listener reintroduces cross-request
stale reads.

**Lazy casting.** `get()` and `has()` cast only the key being read; `all()` casts the whole store, by
contract, and reuses whatever `get()` already cast. So an entry carrying a cast the framework refuses breaks
**its own key only** — which matters on upgrade, since 1.x never validated `$type`. `all()` remains the one
read that cannot survive such an entry. Making `all()` lazy would mean changing its return contract, so it
would be a major, not a refinement. In `get()`, the per-key memo is probed with `array_key_exists`, never
`??=`: a legitimately null value would otherwise be cast again on every read.

**Invalidation.** `set()` and `remove()` drop both the cache and the memo. So do the `saved` and `deleted`
model events on `Entry`, which covers a seeder or a direct model write; switch that off with
`kvstore.cache.auto_invalidate`. A raw SQL write cannot be observed at all — `refresh()` is the only remedy
and stays public for it.

**Missing table.** Reading the store before its migration has run reads as **empty** with a logged warning,
and the emptiness is **not cached** so it heals after `migrate`. The guard is narrow: it rethrows anything it
cannot positively identify as a missing table, so an unreachable database never turns into an empty store.

**The `$type` argument of `set()` is three-state**, and this is the part callers get wrong:

| `set($key, $value, $type)` | Effect                                    |
|----------------------------|-------------------------------------------|
| omitted / `null`           | keep the entry's existing cast            |
| a cast name (`'integer'`)  | set that cast                             |
| `false`                    | **remove** the cast                       |

Anything else raises `InvalidArgumentException` and nothing is written. `encrypted:*` is refused on purpose:
`all()` casts **every** entry, so one value that cannot be decrypted — an `APP_KEY` rotated without
`APP_PREVIOUS_KEYS` — takes the whole store down rather than its own key. Encrypting by hand keeps the
ciphertext opaque to the package and the decryption lazy and local, which fails better.

Two weaker arguments have been retired and should not come back: the cleartext leaking into the cache store
(true before the raw-value cache of v2.1, false since) and the cost of decrypting unused entries (real but
negligible). Lifting the restriction is defensible the day `all()` stops casting eagerly — which would be a
major, since its return type is the public contract.

## Pitfalls

- **Casting rides on a Laravel internal.** `Entry::getCasts()` overrides a framework method, and
  `setAttribute()` relies on `isJsonCastable()`. A Laravel change to either contract breaks casting — which
  is why `tests/CastingTest.php` covers every supported cast end to end, on both supported majors.
- **`Entry::setAttribute()` cannot take `string $key`.** The parent leaves the parameter untyped and PHP
  forbids restricting an inherited parameter type, so `mixed` is the only admissible annotation. Narrowing
  it is a fatal error, not a style improvement.
- **Removing a cast while writing a value that needs one degrades the value.** `set($key, ['a'], false)`
  leaves the array unserialised: on an update the driver grammar serialises it and the value reads back as
  the raw JSON string, on an insert the write fails. Frozen by `tests/EdgeCasesTest.php` and documented as a
  limitation; changing it would be a decision of its own.
- **`key` is bounded to 191 characters** so a primary key on it stays within the index size limit of older
  MySQL/InnoDB running `utf8mb4`. Do not widen it without that in mind.
- **Rehydrating an entry from the cache sets both raw attributes in one call.** `setRawAttributes()`
  *replaces* the attribute array, so hydrating the value and the type in two steps lets the second wipe the
  first — leaving `getCasts()` blind and the value returned raw. `Client::cast()` passes both at once.
- **A fluent `unique()` next to a fluent `primary()` does nothing.** Only the first fluent index of a column
  is registered and `primary` is tested first. `tests/MigrationTest.php` pins the compiled DDL.

## Documentation

- `README.md` (English) — the shop window: value proposition, badges, quick start, casts, cache model,
  upgrade notes from 1.x.
- `docs/llm/index.md` + `llms.txt` — the reference for the public behaviour, written for language models,
  closing with a table mapping each claim to the file that backs it. **Update it in the same commit as any
  behaviour it describes.**
- `https://packages.bgaze.fr/laravel-kvstore` — the public documentation page, maintained separately and
  **downstream**: it is never edited from this repository. When a change here implies a page update,
  **report it** instead of letting the two drift silently.

## Conventions

- All code, comments, commit messages, documentation and README in **English**.
- Insert real Unicode characters directly in source and strings — never escape sequences.
- Everything committed or published must be **self-contained**: no local working directory, no absolute
  machine path, no personal tooling dotfile.
- **Published tags are immutable.** Cut a new patch rather than moving one, and tag only on a green gate.
- **Contributions** are welcome as pull requests on GitHub, alongside issues and feedback.
