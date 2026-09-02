<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTesting\TargetingRuleCodecRegistry;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Rasuvaeff\Yii3AbTestingDb\AbAssignmentsTableName;
use Rasuvaeff\Yii3AbTestingDb\DbAssignmentStore;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\ExperimentCacheInvalidator;
use Rasuvaeff\Yii3AbTestingDb\ExperimentRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

return [
    // BOTH migrations resolve this by type through Injector::make(), so the
    // CREATE and the later ALTER can never target different tables
    AbExperimentsTableName::class => static function () use ($params): AbExperimentsTableName {
        $config = $params['rasuvaeff/yii3-ab-testing-db'] ?? [];

        return new AbExperimentsTableName(
            ((string) ($config['table_prefix'] ?? '')) . ((string) ($config['table'] ?? 'ab_experiments')),
        );
    },
    AbAssignmentsTableName::class => static function () use ($params): AbAssignmentsTableName {
        $config = $params['rasuvaeff/yii3-ab-testing-db'] ?? [];

        return new AbAssignmentsTableName(
            ((string) ($config['table_prefix'] ?? '')) . ((string) ($config['assignments_table'] ?? 'ab_assignments')),
        );
    },
    DbAssignmentStore::class => static function (
        ConnectionInterface $db,
        AbAssignmentsTableName $table,
    ): DbAssignmentStore {
        return new DbAssignmentStore(db: $db, table: $table->value);
    },
    ExperimentProvider::class => static function (
        ConnectionInterface $db,
        ContainerInterface $container,
        AbExperimentsTableName $table,
        TargetingRuleCodecRegistry $targetingCodecs,
    ) use ($params): ExperimentProvider {
        $config = $params['rasuvaeff/yii3-ab-testing-db'] ?? [];

        $provider = new DbExperimentProvider(
            db: $db,
            table: $table->value,
            targetingCodecs: $targetingCodecs,
        );

        $cacheConfig = $config['cache'] ?? [];

        if (($cacheConfig['enabled'] ?? true) === true) {
            return new CachedExperimentProvider(
                inner: $provider,
                cache: $container->get(CacheInterface::class),
                ttl: $cacheConfig['ttl'] ?? 60,
                namespace: $cacheConfig['namespace'] ?? null,
            );
        }

        return $provider;
    },
    ExperimentRepository::class => static function (
        ConnectionInterface $db,
        AbExperimentsTableName $table,
        TargetingRuleCodecRegistry $targetingCodecs,
        ExperimentProvider $provider,
    ): ExperimentRepository {
        return new DbExperimentRepository(
            db: $db,
            table: $table->value,
            targetingCodecs: $targetingCodecs,
            cacheInvalidator: $provider instanceof ExperimentCacheInvalidator ? $provider : null,
        );
    },
];
