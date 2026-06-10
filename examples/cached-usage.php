<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var ConnectionInterface $db */
$db = require __DIR__ . '/bootstrap.php';

// Any PSR-16 cache works (yiisoft/cache, symfony/cache, ...). In-memory here for the demo.
$cache = new class implements CacheInterface {
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }
};

$cached = new CachedExperimentProvider(
    inner: new DbExperimentProvider(db: $db),
    cache: $cache,
    ttl: 60,
);

$ab = new AbTesting(provider: $cached, strategy: new WeightedHashAssignmentStrategy());

echo 'First call (cache miss, loads from DB): ' . $ab->assign(experiment: 'checkout-button', subjectId: 'user-1')->variant . "\n";
echo 'Second call (served from cache):        ' . $ab->assign(experiment: 'checkout-button', subjectId: 'user-1')->variant . "\n";

$cached->clear();
echo "Cache cleared — next call reloads from DB.\n";

$db->close();
