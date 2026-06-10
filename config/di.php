<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

return [
    ExperimentProvider::class => static function (
        ConnectionInterface $db,
        ContainerInterface $container,
    ) use ($params): ExperimentProvider {
        $config = $params['rasuvaeff/yii3-ab-testing-db'] ?? [];

        $provider = new DbExperimentProvider(
            db: $db,
            table: $config['table'] ?? 'ab_experiments',
        );

        $cacheConfig = $config['cache'] ?? [];

        if (($cacheConfig['enabled'] ?? false) === true) {
            return new CachedExperimentProvider(
                inner: $provider,
                cache: $container->get(CacheInterface::class),
                ttl: $cacheConfig['ttl'] ?? 60,
            );
        }

        return $provider;
    },
];
