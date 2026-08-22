# Changelog

## Unreleased

### Changed

- Adopt `rasuvaeff/rector-named-literals` and apply the named-argument rule to literal calls (development tooling only; no runtime behaviour changes).

### Fixed

- The bundled migrations can now be applied on MySQL 8. `M260610000000CreateAbExperimentsTable` declared `variants` as `text NOT NULL DEFAULT '{}'` and `M260619000001AddTargetingToAbExperiments` declared `targeting` as `text NULL DEFAULT NULL`; MySQL rejects a literal `DEFAULT` on a TEXT column with error 1101, so the chain aborted at the first migration and the package could not be installed on MySQL at all. Both `DEFAULT` clauses are removed — neither was reachable (the repository always writes `variants`, and `NULL` is already the implicit default of a nullable column), so the change is a no-op on PostgreSQL and SQLite.
- `ExperimentRowMapper` no longer guesses PHP truthiness for the `enabled` column. Every string except `''` and `'0'` used to read as `true`, so an unrecognised representation of *false* — `'f'`, `'false'`, or the raw `"\x00"` a `BIT(1)` column returns through a libmysqlclient-linked PDO build — silently re-enabled a disabled experiment. Known representations are now recognised explicitly (`''`, `0`/`1`, `"\x00"`/`"\x01"`, `f`/`t`, `false`/`true`, `n`/`y`, `no`/`yes`, `off`/`on`, case-insensitive) and anything else throws `InvalidExperimentRowException` rather than failing open on a kill switch.
- `CrossDatabaseRepositoryTest` now builds `ab_experiments` from the real migration chain instead of hand-written SQL, and asserts that a disabled experiment round-trips as disabled on both MySQL and PostgreSQL. Both defects above existed because that test bypassed the package's own migrations.

## 3.0.1 — 2026-08-04

### Fixed

- Require `yiisoft/db-migration` ^2.1, which fixes `setSourceNamespaces()` matching a sibling namespace as a parent (upstream [yiisoft/db-migration#350](https://github.com/yiisoft/db-migration/pull/350)). Drop the manual `Injector::make()` migration workaround from both READMEs.

## 3.0.0 — 2026-08-01

### Added

- `DbAssignmentStore` — server-side sticky assignments keyed by
  `(experiment, subject_id)`, implementing core's
  `ConfigurationAwareAssignmentStore`. `forget()` and `deleteExperiment()`
  cover erasure and cleanup; `subject_id` is personal data when it is a user id.
- `ExperimentSchedule` on `ExperimentRecord` — the planned run window
  (`starts_at` / `ends_at`). Planning data only: it does not affect assignment.
- `LastKnownGoodExperimentProvider` — opt-in decorator serving the last
  successful read during a source outage, logging every fallback at `error`.
- Migrations `M260801000000CreateAbAssignmentsTable` and
  `M260801000001AddScheduleToAbExperiments`.

### Changed

- **Breaking.** Requires `rasuvaeff/yii3-ab-testing` `^2.0`.
- **Breaking.** A row without a `revision` column now throws
  `InvalidExperimentRowException` instead of yielding an experiment with no
  configuration identity — which made sticky stores serve stale variants and
  exposure deduplication lose its key, both silently.

See [UPGRADE.md](UPGRADE.md) for the migration steps.

## 2.1.1 — 2026-08-01

- Docs: the documented `setSourceNamespaces()` migration registration does not
  find the bundled migration and never has — `yiisoft/db-migration` matches the
  PSR-4 map by string prefix and resolves into the core package, so
  `./yii migrate:up` exits 0 having created nothing. Both READMEs now say so and
  give a working `Injector`-based recipe until the upstream fix ships.

## 2.1.0 — 2026-08-01

- Add `ExperimentRepository` / `DbExperimentRepository` with create, upsert,
  enable, disable, reweight and archive operations.
- Add optimistic locking, lifecycle state and timestamps through the additive
  `M260731000000AddOperationalFieldsToAbExperiments` migration.
- Project DB revisions into core's string `configurationId` as `db:<revision>`
  and use the shared extensible targeting codec registry for reads and writes.
- Register `ab-testing:validate`, `list`, `create`, `enable`, `disable` and
  `reweight` console commands.
- Invalidate the configured experiment cache only after successful writes.
- Run the integration suite against MySQL and PostgreSQL as well as SQLite. The
  new PDO driver dev dependencies are pinned in `config.platform` so the
  Docker-image build gate still installs without those extensions.

## 2.0.1 — 2026-07-29

- Recursively validate targeting JSON, including non-empty `and`/`or` lists and
  string-only environment values; all malformed shapes now throw
  `InvalidExperimentRowException`.
- Validate every key and value in a cached experiment registry before returning
  it; poisoned arrays fall back to and are replaced from the inner provider.
- Namespace cache keys by DB table by default and add an optional cache
  `namespace` for tenant/connection isolation.

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
