# rasuvaeff/yii3-ab-testing-db

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-db.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-db.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-db/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-db/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-db/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-db/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-db/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-db.svg)](LICENSE.md)

Database-backed experiment provider for Yii3 A/B testing. Implements the
`ExperimentProvider` interface from `rasuvaeff/yii3-ab-testing` and reads
experiment configuration from a database table in a single query, so experiments
can be toggled and reweighted at runtime without a deploy.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can ingest in your prompt context.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.0
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
    variants         TEXT         NOT NULL DEFAULT '{}'
);
```

| Column | Type | Default | Description |
|---|---|---|---|
| `name` | `VARCHAR(190)` PK | — | Experiment name (core regex: `/^[a-z][a-z0-9_-]*$/`) |
| `enabled` | `BOOLEAN` | `true` | Disabled experiment returns the fallback variant |
| `salt` | `VARCHAR(190)` | `''` | Empty string falls back to the experiment name |
| `fallback_variant` | `VARCHAR(190)` | `''` | Must be one of the `variants` keys |
| `variants` | `JSON`/`TEXT` | `'{}'` | JSON object `{"variant": weight}`, non-negative integer weights |

A row's `variants` looks like `{"control":50,"green":50}`. The total weight must
be greater than zero and `fallback_variant` must match one of the keys, or the row
is rejected with `InvalidExperimentRowException`.

### Migration

The package ships a migration (`migrations/`) for [yiisoft/db-migration](https://github.com/yiisoft/db-migration).
Register the source path in your app's `config/params.php`:

```php
'yiisoft/db-migration' => [
    'sourcePaths' => [
        dirname(__DIR__) . '/vendor/rasuvaeff/yii3-ab-testing-db/migrations',
    ],
],
```

Then apply and revert it with Yii Console:

```bash
./yii migrate:up
./yii migrate:down --limit=1
```

The table name defaults to `ab_experiments` and must match the `table` argument of
`DbExperimentProvider`. To use a custom name, bind the migration constructor argument:

```php
M260610000000CreateAbExperimentsTable::class => [
    '__construct()' => ['table' => 'my_ab_experiments'],
],
```

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
);

$ab = new AbTesting(provider: $cached, strategy: new WeightedHashAssignmentStrategy());
```

### Clear cache

```php
$cached->clear();               // removes cached experiments, next call reloads from DB
```

## API reference

| Class | Description |
|---|---|
| `DbExperimentProvider` | Reads all experiments from DB in one `SELECT *` |
| `CachedExperimentProvider` | PSR-16 decorator, caches the entire experiment set with TTL |
| `InvalidExperimentRowException` | Thrown when a DB row has invalid structure or yields an invalid experiment |

## Security

- Assignment hashing, fallback handling, forced/disabled logic remain in the core
  package — the DB adapter is only a configuration source.
- Invalid row data (missing columns, malformed `variants` JSON, wrong types,
  negative weights, invalid experiment name, unknown fallback, zero total weight)
  throws `InvalidExperimentRowException` instead of silently mis-assigning. Core
  validation errors are wrapped, so callers only need to catch one exception type.
- No SQL injection risk: the table name is quoted via the yiisoft/db quoter.
- **Reweighting shifts buckets.** Safe to flip `enabled` (kill switch). Changing
  weights or the variant set shifts bucket boundaries and reshuffles subjects — use
  `yii3-ab-testing-web` sticky assignment to pin subjects across such changes.

## Examples

See [examples/](examples/) for runnable scripts.

## Development

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run tests
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
