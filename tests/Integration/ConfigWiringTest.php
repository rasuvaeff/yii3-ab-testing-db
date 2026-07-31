<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\AssignmentStrategy;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTesting\TargetingRuleCodecRegistry;
use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\ExperimentRepository;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
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
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsDbProviderWhenCacheDisabled(): void
    {
        $provider = $this->resolveExperimentProvider([
            'rasuvaeff/yii3-ab-testing-db' => ['table' => 'ab_experiments', 'cache' => ['enabled' => false]],
        ]);

        Assert::instanceOf($provider, DbExperimentProvider::class);
    }

    public function bindsDbProviderWhenParamsAbsent(): void
    {
        Assert::instanceOf($this->resolveExperimentProvider([]), DbExperimentProvider::class);
    }

    public function bindsCachedProviderWhenCacheEnabled(): void
    {
        $provider = $this->resolveExperimentProvider([
            'rasuvaeff/yii3-ab-testing-db' => ['table' => 'ab_experiments', 'cache' => ['enabled' => true, 'ttl' => 60]],
        ]);

        Assert::instanceOf($provider, CachedExperimentProvider::class);
    }

    public function bindsCachedProviderWithCustomNamespace(): void
    {
        $provider = $this->resolveExperimentProvider([
            'rasuvaeff/yii3-ab-testing-db' => [
                'table' => 'ab_experiments',
                'cache' => ['enabled' => true, 'ttl' => 60, 'namespace' => 'tenant-a'],
            ],
        ]);

        Assert::instanceOf($provider, CachedExperimentProvider::class);
    }

    public function bindsOperationalRepository(): void
    {
        $definitions = $this->loadDb([]);
        $repositoryFactory = $definitions[ExperimentRepository::class];
        $providerFactory = $definitions[ExperimentProvider::class];
        $tableFactory = $definitions[AbExperimentsTableName::class];
        $db = $this->sqlite();
        $container = new SimpleContainer([CacheInterface::class => new MemorySimpleCache()]);
        $codecs = new TargetingRuleCodecRegistry();
        $table = $tableFactory();
        $provider = $providerFactory($db, $container, $table, $codecs);

        Assert::instanceOf(
            $repositoryFactory($db, $table, $codecs, $provider),
            ExperimentRepository::class,
        );
    }

    public function packageBindsOnlyItsProviderRepositoryAndTableKeys(): void
    {
        $definitions = $this->loadDb([]);

        // AbExperimentsTableName is this package's own type; the core binds
        // neither it nor ExperimentProvider, so there is nothing for
        // yiisoft/config to call a duplicate
        Assert::same(array_keys($definitions), [
            AbExperimentsTableName::class,
            ExperimentProvider::class,
            ExperimentRepository::class,
        ]);
    }

    public function coreBindsFacadeAndStrategyButNotSwappableKeys(): void
    {
        $core = $this->loadCore();

        Assert::array($core)->hasKeys(AbTesting::class, AssignmentStrategy::class);
        Assert::array($core)->doesNotHaveKeys(ExperimentProvider::class, ExposureTracker::class, ConversionTracker::class);
    }

    public function coreAndBackendDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadDb([]));

        Assert::same($overlap, []);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveExperimentProvider(array $params): ExperimentProvider
    {
        $definitions = $this->loadDb($params);
        $factory = $definitions[ExperimentProvider::class];

        $container = new SimpleContainer([CacheInterface::class => new MemorySimpleCache()]);

        $tableFactory = $definitions[AbExperimentsTableName::class];
        Assert::true(is_callable($tableFactory));

        $provider = $factory(
            $this->sqlite(),
            $container,
            $tableFactory(),
            new TargetingRuleCodecRegistry(),
        );
        Assert::instanceOf($provider, ExperimentProvider::class);

        return $provider;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadDb(array $params): array
    {
        return (static function (array $config): array {
            $params = $config;

            return require dirname(__DIR__, 2) . '/config/di.php';
        })($params);
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
