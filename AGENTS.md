# AGENTS.md — yii3-ab-testing-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

Database-backed experiment provider for Yii3 A/B testing. Implements
`ExperimentProvider` from `rasuvaeff/yii3-ab-testing` core. Reads all experiments
from a DB table in one query via the yiisoft/db `Query` builder (`SELECT *`), and
maps each row to an `Experiment` through the `@internal ExperimentRowMapper`.
Also provides `CachedExperimentProvider` and an operational
`ExperimentRepository` with optimistic locking and lifecycle transitions.
Namespace: `Rasuvaeff\Yii3AbTestingDb`.

Public API: `DbExperimentProvider`, `CachedExperimentProvider`,
`LastKnownGoodExperimentProvider`, `ExperimentRepository`,
`DbExperimentRepository`, `ExperimentRecord`, `ExperimentState`,
`ExperimentSchedule`, `DbAssignmentStore`, `AbAssignmentsTableName`,
`AbExperimentsTableName`, and repository exceptions. `ExperimentRowMapper` is `@internal`
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
4. **Preserve the public contract.** Update both READMEs + tests with any API change.

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
- Every repository update is conditional on the caller's expected revision.
  Increment revision and invalidate cache only after a successful transaction;
  archive instead of deleting historical experiment identities.
- DB revision projects to core `configurationId` as `db:<revision>`, and since
  3.0 it is **required**: a row without a `revision` column throws. Without it
  an experiment has no configuration identity, so a sticky store cannot tell a
  reweight from the original definition and keeps serving a stale variant, and
  exposure deduplication loses its key. Do not use the integer DB revision as a
  cross-provider configuration identity.
- `DbAssignmentStore` implements core's `ConfigurationAwareAssignmentStore` —
  the interface lives in the core precisely so this package does not have to
  depend on `yii3-ab-testing-web`. Its write is an `upsert()`, which compiles to
  different SQL per driver, so schema changes there must be re-verified against
  MySQL and PostgreSQL, not only SQLite (`CrossDatabaseRepositoryTest`).
- `subject_id` in `ab_assignments` is personal data when it is a user id.
  `forget()` and `deleteExperiment()` exist for erasure and cleanup; neither is
  automatic, because dropping analytics-relevant history is the operator's call.
- `ExperimentSchedule` is planning data and deliberately outside the runtime
  projection. Answering `isActiveAt()` during assignment would make the served
  variant depend on wall-clock time, which the deterministic hash never intends.
- `LastKnownGoodExperimentProvider` is opt-in and must never be silent: every
  fallback logs at `error`, and the first read rethrows rather than serving an
  empty set that would look like "no experiments configured".
- `getExperiments()` returns the entire set eagerly; one query
  (`Query->from()->all()`) per call. Without `CachedExperimentProvider` that is a
  DB hit per registry build (per request). Enable caching in production.
- `variants` column is a JSON object `{"variant":weight}`; weights are
  non-negative integers. `fallback_variant` must be one of the variant keys.
- Empty `salt` falls back to the experiment `name` (the column default is `''`).
- Row → `Experiment` mapping lives in `ExperimentRowMapper` (pure, unit-tested).
  The provider is covered by the SQLite integration test.
- **Migrations live in `src/Migration/` under the package namespace.** The table
  name is `AbExperimentsTableName`, a VO — `Injector::make()` resolves arguments
  by name or type and never reads a container definition keyed by the
  migration's class, so a scalar `string $table` could not be configured at all.
  Never reintroduce one.
- **`setSourceNamespaces()` registration works as of `yiisoft/db-migration`
  ^2.1.** Earlier releases (≤ 2.0.1) matched the PSR-4 map by string prefix, so
  `Rasuvaeff\Yii3AbTestingDb\Migration` resolved into the core package and
  discovery silently found zero — see the README.
- **Never edit a migration that has been released.** `yiisoft/db-migration`
  records applied files, and the ClickHouse runner in the sibling package
  records a checksum; editing one after publication makes an installation that
  already applied it diverge from one that has not, with no error to notice.
  Change it with a NEW migration instead. This is why `M260731000000` still
  carries an epoch `DEFAULT` on its timestamp columns: the columns were added
  to a populated table, `NOT NULL` demanded a default, and the migration
  immediately `update()`s the real values. New tables must NOT copy that
  default — an INSERT that forgets a timestamp should fail rather than silently
  record 1970 (see `M260801000000`).
- **Never put a literal `DEFAULT` on a TEXT column.** MySQL rejects it outright
  (error 1101, `BLOB, TEXT, GEOMETRY or JSON column can't have a default
  value`), and `yiisoft/db-mysql` renders even a parsed `DEFAULT NULL` as the
  quoted string `'NULL'`, which fails the same way. PostgreSQL and SQLite accept
  both, so this only ever surfaces on MySQL.
  `M260610000000` (`variants`) and `M260619000001` (`targeting`) both carried
  one, which meant the chain died at step 1 and the package could not be
  installed on MySQL at all. Both were **edited in place**, against the rule
  above, and that exception is deliberate: `yiisoft/db-migration` records only
  the migration *name* in its history table (`Migrator::addMigrationHistory`),
  never a checksum, so an installation that already applied a file never
  re-reads its body; on PostgreSQL/SQLite the only divergence is a column
  default nothing reads; and on MySQL nothing was ever applied successfully, so
  there is no state to diverge from. A *new* migration could not have fixed it —
  the chain never reaches one.
- **`CrossDatabaseRepositoryTest` must build its tables from the real
  migrations.** It used to `CREATE TABLE` by hand with its own column types,
  which is precisely why the MySQL breakage above and the `extractBool`
  fail-open both went unnoticed while the job was green: neither the bundled
  DDL nor the real `BIT(1)`/`BOOLEAN` column ever ran on MySQL or PostgreSQL.
- **`extractBool` refuses what it does not recognise.** `Query` reads without
  typecasting, so booleans arrive as `bool`, `int`, `'0'`/`'1'`, or raw
  `"\x00"`/`"\x01"` depending on driver and PDO build. The recognised forms are
  listed in `ExperimentRowMapper::BOOLEAN_STRINGS`; anything else throws. Never
  restore a truthiness fallback — it answers `true` for every unrecognised
  representation of *false*, which makes the `enabled` kill switch fail open.
- **All migrations take the SAME value object.** They used to hard-code their
  own defaults independently, so a configured table got CREATEd under the custom
  name while the ALTER went to `ab_experiments`.
- Migrations are covered by cs, psalm and infection like any other source file;
  `MigrationTableNameTest` asserts the column set through the real `Injector`.
- `composer test` runs only the Unit suite; `composer mutation` runs every
  suite.
- `CachedExperimentProvider` caches the whole set; invalidation by TTL or
  `clear()`. It returns cache data only when it is a complete
  `array<string, Experiment>` with each key equal to `Experiment::name`. Any
  cache failure or poisoned payload falls back to inner. Cache keys use a SHA-256
  namespace: DB table by default, optional explicit tenant/connection namespace.
- Targeting JSON is recursively validated at runtime. Environment `values` must
  be a non-empty list of strings; `and`/`or` rules must be non-empty lists and
  every nested item must be a rule object. Malformed shapes always throw
  `InvalidExperimentRowException`, never raw `TypeError` or a core exception.
- Empty table → `[]`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

## When you finish

- Update `README.md` and `README.ru.md` together (and `examples/` if usage
  changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` and paste the output.
