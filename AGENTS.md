# AGENTS.md — yii3-ab-testing-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

Database-backed experiment provider for Yii3 A/B testing. Implements
`ExperimentProvider` from `rasuvaeff/yii3-ab-testing` core. Reads all experiments
from a DB table in one query via the yiisoft/db `Query` builder (`SELECT *`), and
maps each row to an `Experiment` through the `@internal ExperimentRowMapper`.
Also provides `CachedExperimentProvider` — a PSR-16 decorator with TTL-based
caching. A migration for `yiisoft/db-migration` ships in `migrations/`.
Namespace: `Rasuvaeff\Yii3AbTestingDb`.

Public API: `DbExperimentProvider`, `CachedExperimentProvider`,
`Exception\InvalidExperimentRowException`. `ExperimentRowMapper` is `@internal`
(row → `Experiment` mapping, unit-tested directly).

DI: `config/di.php` binds `ExperimentProvider` (the experiment **source**), NOT
the `AbTesting` facade. The core binds `AbTesting`; this backend owns the
`ExperimentProvider` key. Binding `ExperimentProvider` from two sources (this
backend plus an app-level `ConfigExperimentProvider`, or two backends) triggers a
`yiisoft/config` `Duplicate key` error — by design. `config/di.php` is not covered
by cs/psalm/phpunit; verify changes with a real `yiisoft/config` merge harness,
not the build gate.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Invalid row = exception.** Never silently skip or default an invalid DB row.
   Throw `InvalidExperimentRowException` with a descriptive message. Core
   `Experiment` validation errors (bad name, unknown fallback, zero total weight)
   are caught and wrapped, never leaked raw.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library). `make test-coverage` and `make mutation`
bootstrap `pcov` inside the `composer:2` container.

## Invariants & gotchas

- DB adapter is only a configuration source — assignment hashing, fallback,
  forced/disabled handling all remain in core.
- `getExperiments()` returns the entire set eagerly; one query
  (`Query->from()->all()`) per call. Without `CachedExperimentProvider` that is a
  DB hit per registry build (per request). Enable caching in production.
- `variants` column is a JSON object `{"variant":weight}`; weights are
  non-negative integers. `fallback_variant` must be one of the variant keys.
- Empty `salt` falls back to the experiment `name` (the column default is `''`).
- Row → `Experiment` mapping lives in `ExperimentRowMapper` (pure, unit-tested).
  The provider is covered by the SQLite integration test.
- Migrations are loaded by `yiisoft/db-migration` via `sourcePaths`
  (global-namespace class in `migrations/`); the table name is a constructor arg.
- `CachedExperimentProvider` caches the whole set; invalidation by TTL or
  `clear()`. Any cache failure is non-fatal (`\Throwable` is caught: down backend,
  corrupted payload) — reads fall back to the inner provider. Cache key
  `rasuvaeff.ab-testing.experiments` is dot-separated (PSR-16 reserves `{}()/\@:`,
  so avoid `:` in keys — `yiisoft/test-support`'s `MemorySimpleCache` rejects it).
- Empty table → `[]`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build` and paste the output.
