<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\AssignmentStrategy;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Exercises the package `config/di.php`, which is otherwise covered by neither cs,
 * psalm, nor the unit suite. Invokes the real `ExperimentProvider` factory and
 * checks the one-source rule against the core package's own `config/di.php`
 * (vendored as `rasuvaeff/yii3-ab-testing`): the merged `di` group must not have a
 * key defined by two packages, which is what triggers `yiisoft/config`'s
 * `Duplicate key` error.
 */
#[CoversNothing]
final class ConfigWiringTest extends TestCase
{
    #[Test]
    public function bindsDbProviderWhenCacheDisabled(): void
    {
        $provider = $this->resolveExperimentProvider([
            'rasuvaeff/yii3-ab-testing-db' => ['table' => 'ab_experiments', 'cache' => ['enabled' => false]],
        ]);

        $this->assertInstanceOf(DbExperimentProvider::class, $provider);
    }

    #[Test]
    public function bindsDbProviderWhenParamsAbsent(): void
    {
        $this->assertInstanceOf(DbExperimentProvider::class, $this->resolveExperimentProvider([]));
    }

    #[Test]
    public function bindsCachedProviderWhenCacheEnabled(): void
    {
        $provider = $this->resolveExperimentProvider([
            'rasuvaeff/yii3-ab-testing-db' => ['table' => 'ab_experiments', 'cache' => ['enabled' => true, 'ttl' => 60]],
        ]);

        $this->assertInstanceOf(CachedExperimentProvider::class, $provider);
    }

    #[Test]
    public function packageBindsOnlyTheExperimentProviderKey(): void
    {
        $definitions = $this->loadDb([]);

        $this->assertSame([ExperimentProvider::class], array_keys($definitions));
    }

    #[Test]
    public function coreBindsFacadeAndStrategyButNotSwappableKeys(): void
    {
        $core = $this->loadCore();

        $this->assertArrayHasKey(AbTesting::class, $core);
        $this->assertArrayHasKey(AssignmentStrategy::class, $core);
        $this->assertArrayNotHasKey(ExperimentProvider::class, $core);
        $this->assertArrayNotHasKey(ExposureTracker::class, $core);
        $this->assertArrayNotHasKey(ConversionTracker::class, $core);
    }

    #[Test]
    public function coreAndBackendDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadDb([]));

        $this->assertSame([], $overlap, 'core and -db must not define the same di key (yiisoft/config Duplicate key)');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveExperimentProvider(array $params): ExperimentProvider
    {
        $definitions = $this->loadDb($params);
        $factory = $definitions[ExperimentProvider::class];

        $container = new SimpleContainer([CacheInterface::class => new MemorySimpleCache()]);

        $provider = $factory($this->sqlite(), $container);
        $this->assertInstanceOf(ExperimentProvider::class, $provider);

        return $provider;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadDb(array $params): array
    {
        return require dirname(__DIR__, 2) . '/config/di.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCore(): array
    {
        $params = [];

        return require dirname(__DIR__, 2) . '/vendor/rasuvaeff/yii3-ab-testing/config/di.php';
    }

    private function sqlite(): ConnectionInterface
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');

        return new SqliteConnection(driver: $driver, schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()));
    }
}
