<?php

declare(strict_types=1);

/**
 * Yii3 config-plugin integration example.
 *
 * In a real Yii3 application `config/params.php` and `config/di.php` are merged
 * automatically by yiisoft/config, and `ExperimentProvider` is resolved from the
 * DI container. This script shows the same wiring manually against an in-memory DB.
 *
 * The package ships:
 *   - config/params.php — defaults under the `rasuvaeff/yii3-ab-testing-db` key
 *   - config/di.php      — binds ExperimentProvider to DbExperimentProvider
 *                          (optionally wrapped in CachedExperimentProvider)
 */

use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var ConnectionInterface $db */
$db = require __DIR__ . '/bootstrap.php';

// Application config — override defaults in your app's config/params.php:
$params = [
    'rasuvaeff/yii3-ab-testing-db' => [
        'table' => 'ab_experiments',
        'cache' => [
            'enabled' => false,
            'ttl' => 60,
        ],
    ],
];

// What config/di.php does (ExperimentProvider factory), expressed inline.
$config = $params['rasuvaeff/yii3-ab-testing-db'];

$provider = new DbExperimentProvider(db: $db, table: $config['table']);

if (($config['cache']['enabled'] ?? false) === true) {
    // In the container the PSR-16 CacheInterface is resolved lazily, only when enabled.
    $psr16 = require __DIR__ . '/psr16-null-cache.php';
    $provider = new CachedExperimentProvider(inner: $provider, cache: $psr16, ttl: $config['cache']['ttl']);
}

// ExperimentProvider::class is now bound to $provider in the container.
assert($provider instanceof ExperimentProvider);

$ab = new AbTesting(provider: $provider, strategy: new WeightedHashAssignmentStrategy());

echo 'Provider: ' . $provider::class . "\n";
echo 'checkout-button / user-1 -> ' . $ab->assign(experiment: 'checkout-button', subjectId: 'user-1')->variant . "\n";

$db->close();
