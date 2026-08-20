# bgaze/laravel-kvstore

Database-backed key-value store for Laravel, cached to avoid repeat queries, with per-entry type casting.
Composer **package** (library, not an app), exposing a `KvStore` facade. Public open-source
(GitHub / Packagist), MIT.

> **This file is public.** It lives in a public repository and ships inside every consumer's `vendor/`
> directory. Keep it self-contained: repo-relative paths and public URLs only — no absolute or machine-local
> paths, no personal tooling, no internal process references. Anything a reader outside this repository
> cannot reach does not belong here.

## Status — legacy, maintenance mode

This package predates the current standard and has **no test suite and no CI**. The consequence is the thing
to keep in mind on every change: **there is no automatic oracle**. A modification is verified by hand against
a real Laravel application, and nothing catches a regression afterwards.

Bringing the package up to the current standard — a test suite and a CI workflow **first**, then the rest —
is a deliberate decision, not a side effect of a bug fix. Until that happens, keep changes minimal and
surgical.

The code also predates the current code conventions: no `declare(strict_types=1)`, no native parameter or
return types, and its own brace style. **Do not modernize style opportunistically inside a functional
change** — a style sweep is its own change, and mixing the two makes an unreviewable diff on a package
that has no suite to prove equivalence.

## Stack

| Item      | Value                                                                    |
|-----------|--------------------------------------------------------------------------|
| Language  | PHP (legacy style — no strict types, no native types)                    |
| Framework | Laravel, `laravel/framework: ">=5.5.0"`                                  |
| Autoload  | PSR-4 `Bgaze\KvStore\` → `src/`                                          |
| Discovery | Laravel package auto-discovery (`extra.laravel` in `composer.json`)       |
| Tests     | **none**                                                                 |
| CI        | **none**                                                                 |
| Released  | `v1.0.0`, `v1.1.0` on branch `master`                                     |

## Architecture

Five files, no abstraction layer:

- `src/Client.php` — the whole public API, as static methods: `set()`, `get()`, `remove()`, `all()`,
  `refresh()`. Bound in the container as `kvstore.client`.
- `src/Entry.php` — the Eloquent model over table `kvstore`. String primary key `key`, non-incrementing,
  no timestamps. `getCasts()` is **overridden** so the row's own `type` column drives the cast at runtime;
  `setAttribute()` coerces `null` to `[]` for JSON-castable values.
- `src/Facade.php` — the `KvStore` facade, resolving `kvstore.client`.
- `src/ServiceProvider.php` — binds `kvstore.client` and publishes the migration under the `kvstore` tag.
- `src/migrations/2019_09_29_000000_create_kvstore_table.php` — table `kvstore`: `key` (string, unique +
  primary), `type` (string, nullable), `value` (text, nullable).

**Caching model.** The store is cached as **one single entry** (`Cache::rememberForever`, key
`settings-store-cache`) holding the whole `key => value` collection, values already cast. Every write
(`set`, `remove`) forgets that entry wholesale, so the next read repopulates the entire store. Fine for a
settings-sized store; it is not a per-key cache and should not be mistaken for one.

**The `$type` argument of `set()` is three-state**, and this is the part callers get wrong:

| `set($key, $value, $type)` | Effect                                    |
|----------------------------|-------------------------------------------|
| omitted / `null`           | keep the entry's existing cast            |
| a cast name (`'integer'`)  | set that cast                             |
| `false`                    | **remove** the cast                       |

## Pitfalls

- **Casting rides on a Laravel internal.** `Entry::getCasts()` overrides a framework method, and
  `setAttribute()` relies on `isJsonCastable()`. A Laravel upgrade that changes either contract breaks
  casting silently — with no suite to catch it.
- **The Composer constraint is unbounded upward** (`">=5.5.0"`), so a consumer on any future major resolves
  this package. Bounding it, or requiring the concrete `illuminate/*` components actually used, is the fix
  direction.
- **`key` is a 255-char string used as primary key.** On older MySQL/InnoDB with `utf8mb4`, that combination
  can exceed the index size limit — the classic `Schema::defaultStringLength(191)` case. Modern MySQL is
  unaffected.
- **`refresh()` is part of the public API** and must be called after any write that bypasses `Client` (a
  direct `Entry` write, a seeder, a manual SQL update), otherwise reads serve stale cached values.

## Documentation

- `README.md` (English) — the human entry point: install, publish the migration, migrate, usage.
- `https://packages.bgaze.fr/laravel-kvstore` — the public documentation page, maintained separately and
  **downstream**: it is never edited from this repository. When a change here implies a page update,
  **report it** instead of letting the two drift silently.
- An LLM-oriented usage guide shipped with the package (`docs/llm/` + a root `llms.txt`) is the target, and
  does not exist yet.

## Conventions

- All code, comments, commit messages, documentation and README in **English**.
- Insert real Unicode characters directly in source and strings — never escape sequences.
- Everything committed or published must be **self-contained**: no local working directory, no absolute
  machine path, no personal tooling dotfile.
- **Contributions** are welcome as pull requests on GitHub, alongside issues and feedback.
