<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(DbExperimentProvider::class)]
final class DbExperimentProviderTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        $this->createTable('ab_experiments');
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function readsNothingFromAnEmptyTable(): void
    {
        Assert::same((new DbExperimentProvider(db: $this->db))->getExperiments(), []);
    }

    public function keysEveryRowByItsExperimentName(): void
    {
        $this->insert('ab_experiments', 'checkout', '{"control":50,"green":50}');
        $this->insert('ab_experiments', 'search', '{"control":10,"blue":90}');

        $experiments = (new DbExperimentProvider(db: $this->db))->getExperiments();

        Assert::same(array_keys($experiments), ['checkout', 'search']);
        Assert::same($experiments['checkout']->variants, ['control' => 50, 'green' => 50]);
        Assert::same($experiments['search']->variants, ['control' => 10, 'blue' => 90]);
    }

    public function readsFromTheConfiguredTable(): void
    {
        $this->createTable('custom_experiments');
        $this->insert('custom_experiments', 'only-here', '{"control":100}');

        $experiments = (new DbExperimentProvider(db: $this->db, table: 'custom_experiments'))->getExperiments();

        Assert::same(array_keys($experiments), ['only-here']);
        Assert::same((new DbExperimentProvider(db: $this->db))->getExperiments(), []);
    }

    public function cacheNamespaceIsTheTableNameBehindTheDbPrefix(): void
    {
        Assert::same((new DbExperimentProvider(db: $this->db))->getCacheNamespace(), 'db.ab_experiments');
        Assert::same(
            (new DbExperimentProvider(db: $this->db, table: 'custom_experiments'))->getCacheNamespace(),
            'db.custom_experiments',
        );
    }

    private function createTable(string $table): void
    {
        $this->db->createCommand(sql: sprintf('
            CREATE TABLE %s (
                name VARCHAR(190) PRIMARY KEY,
                enabled INTEGER NOT NULL DEFAULT 1,
                salt VARCHAR(190) NOT NULL DEFAULT \'\',
                fallback_variant VARCHAR(190) NOT NULL DEFAULT \'control\',
                variants TEXT NOT NULL,
                targeting TEXT NULL,
                revision INTEGER NOT NULL DEFAULT 1
            )
        ', $table))->execute();
    }

    private function insert(string $table, string $name, string $variants): void
    {
        $this->db->createCommand()->insert($table, [
            'name' => $name,
            'enabled' => 1,
            'variants' => $variants,
        ])->execute();
    }
}
