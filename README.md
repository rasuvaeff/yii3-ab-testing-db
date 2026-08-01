# rasuvaeff/yii3-ab-testing-db

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-db.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-db.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-db/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-db/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-db/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-db/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-db/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-db.svg)](LICENSE.md)
[Русская версия](README.ru.md)

Database-backed experiment provider for Yii3 A/B testing. Implements the
`ExperimentProvider` interface from `rasuvaeff/yii3-ab-testing` and reads
experiment configuration from a database table in a single query, so experiments
can be toggled and reweighted at runtime without a deploy.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can ingest in your prompt context.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer
> plugin also get this package's agent skill synced into `.agents/skills/`
> automatically on install.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.6
- `yiisoft/db` ^2.0
- `yiisoft/db-migration` ^2.0 (ships the table migration)
- a PSR-16 cache implementation — required transitively by `yiisoft/db` 2.0
  (e.g. `yiisoft/cache`)

## Installation

```bash
composer require rasuvaeff/yii3-ab-testing-db
```

With Yii3 config-plugin this package binds `ExperimentProvider` automatically — do
**not** also bind `ExperimentProvider` in your application or another backend, or
`yiisoft/config` reports a `Duplicate key` error.

## Database schema

Create the `ab_experiments` table (adjust types for your RDBMS):

```sql
CREATE TABLE ab_experiments (
    name             VARCHAR(190) PRIMARY KEY,
    enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    salt             VARCHAR(190) NOT NULL DEFAULT '',
    fallback_variant VARCHAR(190) NOT NULL DEFAULT '',
    variants         TEXT         NOT NULL DEFAULT '{}',
    targeting        TEXT         NULL,
    state            VARCHAR(20)  NOT NULL DEFAULT 'running',
    revision         INTEGER      NOT NULL DEFAULT 1,
    created_at       VARCHAR(32)  NOT NULL,
    updated_at       VARCHAR(32)  NOT NULL
);
```

| Column | Type | Default | Description |
|---|---|---|---|
| `name` | `VARCHAR(190)` PK | — | Experiment name (core regex: `/^[a-z][a-z0-9_-]*\z/`) |
| `enabled` | `BOOLEAN` | `true` | Disabled experiment returns the fallback variant |
| `salt` | `VARCHAR(190)` | `''` | Empty string falls back to the experiment name |
| `fallback_variant` | `VARCHAR(190)` | `''` | Must be one of the `variants` keys |
| `variants` | `JSON`/`TEXT` | `'{}'` | JSON object `{"variant": weight}`, non-negative integer weights |
| `targeting` | `JSON`/`TEXT` nullable | `null` | Targeting rule encoded by the shared core codec registry |
| `state` | `VARCHAR(20)` | `running` | `draft`, `running`, `paused`, `completed` or `archived` |
| `revision` | `INTEGER` | `1` | Optimistic-lock version; increments after every write. **Required since 3.0** — without it an experiment has no configuration identity |
| `created_at`, `updated_at` | `VARCHAR(32)` | — | UTC operational timestamps |
| `starts_at`, `ends_at` | `VARCHAR(32)` nullable | `null` | Planned run window; `null` means unscheduled |

A row's `variants` looks like `{"control":50,"green":50}`. The total weight must
be greater than zero and `fallback_variant` must match one of the keys, or the row
is rejected with `InvalidExperimentRowException`.

### Migration

Register the bundled migration **by namespace** — no vendor paths:

```php
// config/common/di/migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [[
            'App\\Migration',
            'Rasuvaeff\\Yii3AbTestingDb\\Migration',
        ]],
    ],
];
```

```bash
./yii migrate:up
./yii migrate:down --limit=1
```

> **Heads up: the snippet above does not find the migration yet.** It is the
> correct configuration and will start working with no change on your side once
> the upstream bug below is fixed — but today `./yii migrate:up` reports
> "Your system is up-to-date", exits 0 and creates no tables.
>
> `yiisoft/db-migration` (2.0.x) resolves a namespace to a directory by taking
> the first entry in `composer/autoload_psr4.php` that the namespace starts
> with, comparing against the key with its trailing separator trimmed but
> cutting the remainder with the *untrimmed* length. Trimming the separator
> destroys the segment boundary, so `Rasuvaeff\Yii3AbTesting\` matches
> `Rasuvaeff\Yii3AbTestingDb\Migration` as if it were its parent —
> and this package depends on that one, so the collision is always present. The
> resolved directory does not exist, discovery skips missing directories
> silently, and nothing is applied.

Until that is fixed upstream, apply the bundled migration yourself:

```php
// src/Console/MigrateCommand.php (excerpt)
use Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260619000001AddTargetingToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260731000000AddOperationalFieldsToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000000CreateAbAssignmentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000001AddScheduleToAbExperiments;
use Yiisoft\Db\Migration\Informer\ConsoleMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Injector\Injector;

$builder = new MigrationBuilder($db, new ConsoleMigrationInformer());
$injector = new Injector($container);

foreach ([
    M260610000000CreateAbExperimentsTable::class,
    M260619000001AddTargetingToAbExperiments::class,
    M260731000000AddOperationalFieldsToAbExperiments::class,
    M260801000000CreateAbAssignmentsTable::class,
    M260801000001AddScheduleToAbExperiments::class,
] as $class) {
    $injector->make($class)->up($builder);
}
```

`Injector::make()` is required rather than `new`: it resolves the table-name
value object from your configuration. Keep the loop idempotent (skip when the
table already exists) — it has no migration history of its own.

Set the table name in params — the same value reaches the migration **and**
`DbExperimentProvider`:

```php
// config/common/params.php
'rasuvaeff/yii3-ab-testing-db' => [
    'table' => 'my_ab_experiments',
    'table_prefix' => '',   // prepended to `table`; e.g. 'rsv_' → rsv_my_ab_experiments
],
```

All bundled migrations take the same table name, so the
`CREATE` and the later `ALTER` can no longer target different tables.

> **Upgrading an existing installation.** The operational control plane
> (`ExperimentRepository` and the console commands) needs the `state`,
> `revision`, `created_at` and `updated_at` columns added by
> `M260731000000AddOperationalFieldsToAbExperiments`. Run `./yii migrate:up`
> before using them; `DbExperimentProvider` keeps reading a table that has not
> been migrated yet. The migration is additive: existing rows get
> `revision = 1`, epoch timestamps, and `state = paused` when `enabled` is
> false, `running` otherwise. No assignment data changes.

> **Do not configure the migration through the DI container.**
> `M...::class => ['__construct()' => ['table' => ...]]` does not work: the
> migration is built by `Injector::make()`, which resolves arguments by type
> and never reads a container definition keyed by the migration's own class.
> Worse, adding that definition makes the container fatal at build time in
> **every** request, because the class is not autoloadable until the migration
> runner requires it. That recipe was documented in 1.x; it never worked.

## Usage

### Basic DB provider

```php
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;

$provider = new DbExperimentProvider(
    db: $connection,            // yiisoft/db ConnectionInterface
    table: 'ab_experiments',    // optional, default is 'ab_experiments'
);

$ab = new AbTesting(provider: $provider, strategy: new WeightedHashAssignmentStrategy());

if ($ab->is(experiment: 'checkout-button', variant: 'green', subjectId: (string) $userId)) {
    // green variant
}
```

### With PSR-16 caching

`getExperiments()` runs on every registry build (per request). Without caching that
is a DB query per request — wrap the provider in `CachedExperimentProvider`:

```php
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;

$cached = new CachedExperimentProvider(
    inner: $provider,
    cache: $psr16Cache,         // PSR-16 CacheInterface
    ttl: 60,                    // seconds
    namespace: null,            // optional tenant/connection identity
);

$ab = new AbTesting(provider: $cached, strategy: new WeightedHashAssignmentStrategy());
```

The default cache namespace includes the `DbExperimentProvider` table name, so
providers for different tables cannot read each other's registries. When tenants
or connections share the same table name and cache backend, set a distinct
non-empty `namespace` (or `cache.namespace` in Yii params). Cached arrays are
accepted only when every key is a string matching an `Experiment::name` and every
value is an `Experiment`; an invalid payload is replaced from the inner provider.

### Clear cache

```php
$cached->clear();               // removes cached experiments, next call reloads from DB
```

### Operational repository

The config-plugin also binds `ExperimentRepository`. Writes are transactional,
increment `revision` atomically and invalidate the configured cache only after a
successful commit:

```php
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\ExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\ExperimentState;

/** @var ExperimentRepository $repository */
$record = $repository->create(
    experiment: new Experiment(
        name: 'checkout-button',
        enabled: false,
        salt: 'checkout-v1',
        fallbackVariant: 'control',
        variants: ['control' => 50, 'green' => 50],
    ),
    state: ExperimentState::Draft,
);

$record = $repository->enable(
    name: 'checkout-button',
    expectedRevision: $record->revision,
);
$record = $repository->reweight(
    name: 'checkout-button',
    variants: ['control' => 10, 'green' => 90],
    expectedRevision: $record->revision,
);
```

A stale revision throws `RevisionConflictException`; a missing name throws
`ExperimentNotFoundException`. `archive()` disables the experiment while
preserving its name and revision history. Runtime experiments receive
`configurationId = "db:<revision>"`, so exposure deduplication and sticky
assignments do not mix different definitions.

### Console control plane

```bash
./yii ab-testing:validate
./yii ab-testing:list
./yii ab-testing:create checkout-button '{"control":50,"green":50}' control --salt=checkout-v1
./yii ab-testing:enable checkout-button 1
./yii ab-testing:reweight checkout-button '{"control":10,"green":90}' 2
./yii ab-testing:disable checkout-button 3
```

Revision arguments are mandatory on mutations to prevent one operator from
silently overwriting another operator's change.

## API reference

| Class | Description |
|---|---|
| `DbExperimentProvider` | Reads all experiments from DB in one `SELECT *` |
| `CachedExperimentProvider` | PSR-16 decorator, caches the entire experiment set with TTL |
| `ExperimentRepository` | Operational create/update/lifecycle contract |
| `DbExperimentRepository` | Transactional DB implementation with optimistic locking |
| `ExperimentRecord` | Runtime experiment plus state, revision and timestamps |
| `ExperimentState` | Lifecycle enum: draft/running/paused/completed/archived |
| `LastKnownGoodExperimentProvider` | Opt-in decorator: serves the last successful read during a source outage, logging every fallback |
| `DbAssignmentStore` | Server-side sticky assignments, keyed by subject rather than by browser |
| `ExperimentSchedule` | Planned run window; planning data only, never an assignment input |
| `AbExperimentsTableName`, `AbAssignmentsTableName` | Table names as types, so migrations can be configured through `Injector` |
| `InvalidExperimentRowException` | Thrown when a DB row has invalid structure or yields an invalid experiment |

## Security

- Assignment hashing, fallback handling, forced/disabled logic remain in the core
  package — the DB adapter is only a configuration source.
- Invalid row data (missing columns, malformed `variants`/`targeting` JSON, wrong
  types, non-string environment values, empty `and`/`or`, invalid nested rules,
  negative weights, invalid experiment name, unknown fallback, zero total weight)
  throws `InvalidExperimentRowException` instead of silently mis-assigning. Core
  validation errors are wrapped, so callers only need to catch one exception type.
- No SQL injection risk: the table name is quoted via the yiisoft/db quoter.
- **Reweighting shifts buckets.** Safe to flip `enabled` (kill switch). Changing
  weights or the variant set shifts bucket boundaries and reshuffles subjects — use
  `yii3-ab-testing-web` sticky assignment to pin subjects across such changes.
- Repository commands use bound values and an identifier-validated table name.
  Never construct write SQL from command arguments.

## Examples

See [examples/](examples/) for runnable scripts.

## Development

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run tests
vendor/bin/testo --suite=Integration
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
