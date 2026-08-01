---
name: rasuvaeff-yii3-ab-testing-db
description: >-
  Database-backed experiment definitions and server-side sticky assignments for
  Yii3 A/B testing with rasuvaeff/yii3-ab-testing-db — DbExperimentProvider,
  ExperimentRepository, DbAssignmentStore, migrations, revisions and lifecycle
  state. Use when writing, reviewing or debugging experiment storage, the
  write-side CLI, migrations, or sticky assignment persistence in a project
  that has this package installed.
---

# rasuvaeff/yii3-ab-testing-db

Experiment definitions in a table, an operational repository with optimistic
locking, and server-side sticky assignments. Namespace
`Rasuvaeff\Yii3AbTestingDb\`.

## Safety rules — verify these on every change

1. **This backend owns the `ExperimentProvider` key.** Binding it from a second
   source (an app-level `ConfigExperimentProvider`, or another backend) is a
   `yiisoft/config` `Duplicate key` error, by design.

2. **An invalid row throws, never defaults.** Wrap core validation failures in
   `InvalidExperimentRowException`; never silently skip a row or substitute a
   value. A silently-dropped experiment looks exactly like "not configured" and
   sends every visitor to their fallback.

3. **A revision is required (since 3.0).** A row without one throws. Without a
   configuration identity a sticky store cannot tell a reweight from the
   original definition and keeps serving a variant drawn from bucket boundaries
   that no longer exist, and exposure deduplication loses its key — both
   silently. The DB revision projects to core `configurationId` as
   `db:<revision>`.

4. **Never edit a released migration.** `yiisoft/db-migration` records applied
   files, so an edit makes an installation that already applied it diverge from
   one that has not, with nothing to notice. Change it with a NEW migration.
   This is why `M260731000000` still carries an epoch `DEFAULT` on its timestamp
   columns; new tables must not copy that default.

5. **Every write is conditional on the caller's expected revision.** Increment
   the revision and invalidate the cache only after the transaction commits.
   Archive rather than delete: a deleted experiment's name and revision stop
   being interpretable in historical events.

6. **`subject_id` in `ab_assignments` is personal data** when it is a user id.
   `forget()` and `deleteExperiment()` exist for erasure and cleanup, and
   neither runs automatically — dropping analytics-relevant history is the
   operator's decision.

## Migrations do not register by namespace

The documented `MigrationService::setSourceNamespaces()` recipe **silently finds
nothing** while the core package is installed, which is always: `yiisoft/db-migration`
matches the PSR-4 map by string prefix and lands inside `yii3-ab-testing`.
`migrate:up` then prints "up-to-date", exits 0 and creates no tables.

Apply them through `Injector` instead:

```php
foreach ([M260610000000CreateAbExperimentsTable::class, /* … */] as $class) {
    $injector->make($class)->up($builder);
}
```

The table name is a value object (`AbExperimentsTableName`,
`AbAssignmentsTableName`) because `Injector::make()` resolves by name or type
and never reads a container definition keyed by the migration's own class — a
scalar `string $table` could not be configured at all.

## Canonical usage

```php
$provider = new CachedExperimentProvider(new DbExperimentProvider($db), $cache);

// Optional: survive a database outage instead of taking the app down with it.
// Opt in deliberately — a kill switch flipped during the outage will not apply,
// and every fallback is logged at error level.
$provider = new LastKnownGoodExperimentProvider($provider, $logger);

// Server-side stickiness: survives a new device and a cookie clear, unlike the
// cookie store in yii3-ab-testing-web.
$store = new DbAssignmentStore(db: $db);
```

## Full API

The complete reference — repository contract, lifecycle states, schedule
window, console commands, caching and DI wiring — ships with the package:
`vendor/rasuvaeff/yii3-ab-testing-db/llms.txt`. Upgrading:
`vendor/rasuvaeff/yii3-ab-testing-db/UPGRADE.md`.
