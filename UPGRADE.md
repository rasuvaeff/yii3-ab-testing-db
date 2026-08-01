# Upgrade guide

## 2.x → 3.0

Two things change: the package moves to `rasuvaeff/yii3-ab-testing` 2.0, and an
experiment revision becomes required rather than optional. Everything else is
additive.

### Apply the migrations first

| Migration | What |
|---|---|
| `M260801000000CreateAbAssignmentsTable` | new `ab_assignments` table for `DbAssignmentStore` |
| `M260801000001AddScheduleToAbExperiments` | nullable `starts_at` / `ends_at` on the experiments table |

Both are additive — no column is dropped or retyped, and an existing experiment
gets `NULL` for the schedule window, which means "already begun, runs until
stopped": exactly what it was doing before the columns existed.

The documented `setSourceNamespaces()` registration silently finds no migrations
while the core package is installed, so apply them through `Injector`:

```php
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000000CreateAbAssignmentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000001AddScheduleToAbExperiments;

foreach ([M260801000000CreateAbAssignmentsTable::class, M260801000001AddScheduleToAbExperiments::class] as $class) {
    $injector->make($class)->up($builder);
}
```

### A revision is now required

`ExperimentRowMapper` throws `InvalidExperimentRowException` when a row has no
`revision` column, where 2.x quietly produced an experiment with
`configurationId = null`.

Without a configuration identity a sticky store cannot tell a reweight from the
original definition, so it keeps serving a variant drawn from bucket boundaries
that no longer exist, and exposure deduplication loses its key. Both failures
are silent, which is why this one is now loud.

**You are affected only if you never applied the 2.1 migration**
(`M260731000000AddOperationalFieldsToAbExperiments`), which added the column.
Apply it and the error goes away. If you read experiments from a hand-built
table or a view, add a `revision` column holding a positive integer.

### Core 2.0 comes with it

The core's breaking changes apply transitively — trackers take events, and
`Assignment` replaced its boolean properties with `reason` / `source`. See
`vendor/rasuvaeff/yii3-ab-testing/UPGRADE.md`. This package's own API is
unaffected by them: it produces experiment definitions, not assignments.

### New, entirely optional

- **`DbAssignmentStore`** — server-side sticky assignments, so an authenticated
  user keeps their variant across devices and cookie clears. Implements core's
  `ConfigurationAwareAssignmentStore`; wire it into `yii3-ab-testing-web`'s
  `StickyAssignmentResolver` in place of the cookie store.

  `subject_id` is personal data when it is a user id. `forget($subjectId)`
  erases a subject across all experiments and `deleteExperiment($name)` cleans
  up after an experiment ends; neither runs automatically.

- **`ExperimentSchedule`** on `ExperimentRecord` — the planned run window. It
  does **not** affect assignment: the provider still reads the `enabled` flag,
  and a scheduler or operator flips that flag based on the window. Deciding
  activity from wall-clock time inside `assign()` would make the served variant
  time-dependent, which the deterministic hash never intends.

- **`LastKnownGoodExperimentProvider`** — serves the last successful read when
  the database is unavailable, instead of taking the application down with it.

  Opt in deliberately: stale definitions mean a kill switch flipped during the
  outage will not take effect. Every fallback is logged at `error` level, so do
  not enable it where those logs go unread. The first read rethrows — starting
  up against a broken database must fail loudly rather than serve an empty set,
  which would look like "no experiments configured" and send every visitor to
  their fallback variant.

  ```php
  new LastKnownGoodExperimentProvider(
      new CachedExperimentProvider(new DbExperimentProvider($db), $cache),
      $logger,
  );
  ```

## 1.x → 2.0

The bundled migration moved into the package namespace:

```
M260610000000CreateAbExperimentsTable
→ Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable
```

`yiisoft/db-migration` stores the applied migration's class name verbatim in the
`migration` table. Without the two steps below, `migrate:up` sees the namespaced
class as a *new* migration and fails with "table already exists".

### 1. Rewrite the applied migration's name

```sql
UPDATE migration
SET name = 'Rasuvaeff\\Yii3AbTestingDb\\Migration\\M260610000000CreateAbExperimentsTable'
WHERE name = 'M260610000000CreateAbExperimentsTable';
```

Run this **before** the first `migrate:up` on 2.0. If you have never applied the
migration, skip it — there is nothing to rename.

### 2. Register by namespace instead of by path

```diff
 MigrationService::class => [
-    'setSourcePaths()' => [[__DIR__ . '/../vendor/rasuvaeff/yii3-ab-testing-db/migrations']],
+    'setSourceNamespaces()' => [['Rasuvaeff\\Yii3AbTestingDb\\Migration']],
 ],
```

The path form no longer resolves: `migrations/` is gone and the class lives
under `src/Migration/`, autoloaded via PSR-4.

### 3. Remove any DI definition of the migration

```diff
-M260610000000CreateAbExperimentsTable::class => [
-    '__construct()' => ['table' => 'my_table'],
-],
```

That recipe was documented in 1.x and **never worked** — the migration is built
by `Injector::make()`, which resolves arguments by type and ignores container
definitions keyed by the migration's class. It also makes the container fatal at
build time in every request, because the class is not autoloadable until the
migration runner requires it.

Set the table name in params instead; the same value now reaches the migration
and `DbExperimentProvider`:

```php
'rasuvaeff/yii3-ab-testing-db' => [
    'table' => 'my_table',
    'table_prefix' => '',
],
```

### Defaults are unchanged

The default table and index names are exactly what 1.x produced, so this release
needs no schema migration — only the `migration` table row above.
