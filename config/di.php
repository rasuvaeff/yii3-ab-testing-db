<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
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
    ExperimentProvider::class => static function (
        ConnectionInterface $db,
        ContainerInterface $container,
        AbExperimentsTableName $table,
    ) use ($params): ExperimentProvider {
        $config = $params['rasuvaeff/yii3-ab-testing-db'] ?? [];

        $provider = new DbExperimentProvider(db: $db, table: $table->value);

        $cacheConfig = $config['cache'] ?? [];

        if (($cacheConfig['enabled'] ?? false) === true) {
            return new CachedExperimentProvider(
                inner: $provider,
                cache: $container->get(CacheInterface::class),
                ttl: $cacheConfig['ttl'] ?? 60,
                namespace: $cacheConfig['namespace'] ?? null,
            );
        }

        return $provider;
    },
];
