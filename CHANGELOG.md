# Changelog

## 2.0.0 — 2026-07-25

**Breaking.** See [UPGRADE.md](UPGRADE.md) — an installation that already
applied the migration must rewrite one row in the `migration` table.

- The bundled migration moved to `Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable`
  (`src/Migration/`, PSR-4 autoloaded) from a global class in `migrations/`.
  Register it with `setSourceNamespaces()` instead of a `vendor/` path. Being
  autoloadable is what makes it safe to reference in DI at all: with the old
  global class, adding any container definition for it made
  `Yiisoft\Di\Container` fatal at build time in every request, because
  `new ReflectionClass()` ran before the migration runner had required the file.
- **The documented way to rename the table never worked.**
  `M...::class => ['__construct()' => ['table' => ...]]` is ignored:
  `yiisoft/db-migration` builds migrations through `Injector::make()`, which
  resolves arguments by name or type from the container and does not read
  definitions keyed by the migration's class — and a scalar `string $table` has
  no type to resolve. Users following the README silently got the default name.
- The table name is now a typed value object that `Injector` *can* resolve,
  built by `config/di.php` from params. One source of truth: the migration and
  `DbExperimentProvider` cannot disagree any more (in 1.x the runtime read params while the
  migration used its own default, so configuring params pointed the runtime at a
  table the migration had never created).
- New `table_prefix` param, prepended to `table` — a single place to keep
  package tables out of the way of an application's own.
- **Both migrations now take the same value object.** In 1.x each hard-coded its
  own default, so configuring the table produced a `CREATE TABLE my_experiments`
  followed by an `ALTER TABLE ab_experiments` — the targeting column landed on
  the wrong table or the migration failed outright. `MigrationTableNameTest`
  pins this.
- `DbExperimentProvider` validates the table name (through the same value
  object) — in 1.x it interpolated whatever string it was given straight into
  the query builder, with no identifier check at all.


## 1.1.1 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.1.0 — 2026-06-20

- `ExperimentRowMapper` now decodes optional `targeting` JSON column into a
  `TargetingRule` tree (`environment`, `attribute`, `and`, `or` types).
- New migration `M260619000001AddTargetingToAbExperiments`: `ALTER TABLE ab_experiments ADD COLUMN targeting TEXT NULL`.
- Requires `rasuvaeff/yii3-ab-testing ^1.4`.

## 1.0.0 — 2026-06-12

- `DbExperimentProvider` — reads all experiments from a DB table in one query and implements `Rasuvaeff\Yii3AbTesting\ExperimentProvider`.
- `CachedExperimentProvider` — PSR-16 decorator caching the whole experiment set with a TTL; `clear()` invalidates. Any cache failure (including a down backend or a corrupted payload) falls back to the inner provider instead of breaking the request.
- `ExperimentRowMapper` (`@internal`) — maps a DB row to a validated `Experiment`; wraps core validation errors into `InvalidExperimentRowException`.
- `Exception\InvalidExperimentRowException` — thrown on missing/invalid columns, malformed `variants` JSON, or invalid experiment definitions.
- `migrations/M260610000000CreateAbExperimentsTable` — creates the `ab_experiments` table (JSON `variants` column).
- Yii3 config-plugin: binds `ExperimentProvider` (optionally cached) from `config/di.php`; defaults in `config/params.php`.

