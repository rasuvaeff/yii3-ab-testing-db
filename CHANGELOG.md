# Changelog

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

