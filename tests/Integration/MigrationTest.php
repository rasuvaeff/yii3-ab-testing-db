<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260619000001AddTargetingToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260731000000AddOperationalFieldsToAbExperiments;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[CoversNothing]
final class MigrationTest
{
    private ConnectionInterface $db;

    private MigrationBuilder $builder;

    #[BeforeTest]
    public function setUp(): void
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function createsAndDropsExperimentsTable(): void
    {
        $migration = new M260610000000CreateAbExperimentsTable();

        $migration->up($this->builder);

        $schema = $this->db->getTableSchema('ab_experiments', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('name'));
        Assert::notNull($schema->getColumn('enabled'));
        Assert::notNull($schema->getColumn('salt'));
        Assert::notNull($schema->getColumn('fallback_variant'));
        Assert::notNull($schema->getColumn('variants'));
        Assert::same($schema->getPrimaryKey(), ['name']);

        $migration->down($this->builder);

        Assert::null($this->db->getTableSchema('ab_experiments', true));
    }

    public function createsTableWithCustomName(): void
    {
        (new M260610000000CreateAbExperimentsTable(table: new AbExperimentsTableName('custom_experiments')))->up($this->builder);

        Assert::notNull($this->db->getTableSchema('custom_experiments', true));
        Assert::null($this->db->getTableSchema('ab_experiments', true));
    }

    public function migratedTableIsReadableByProvider(): void
    {
        (new M260610000000CreateAbExperimentsTable())->up($this->builder);

        $this->db->createCommand(
            sql: "INSERT INTO ab_experiments (name, enabled, salt, fallback_variant, variants)
                  VALUES ('checkout-button', 1, 'checkout-v1', 'control', '{\"control\":50,\"green\":50}')",
        )->execute();

        $experiments = (new DbExperimentProvider(db: $this->db))->getExperiments();

        Assert::array($experiments)->hasKeys('checkout-button');
        Assert::same($experiments['checkout-button']->fallbackVariant, 'control');
        Assert::same($experiments['checkout-button']->variants, ['control' => 50, 'green' => 50]);
    }

    public function addsOperationalFieldsAndBackfillsDisabledState(): void
    {
        (new M260610000000CreateAbExperimentsTable())->up($this->builder);
        (new M260619000001AddTargetingToAbExperiments())->up($this->builder);
        $this->db->createCommand(
            sql: "INSERT INTO ab_experiments (name, enabled, salt, fallback_variant, variants)
                  VALUES ('paused-exp', 0, 'v1', 'control', '{\"control\":100}')",
        )->execute();

        (new M260731000000AddOperationalFieldsToAbExperiments())->up($this->builder);

        $schema = $this->db->getTableSchema('ab_experiments', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('state'));
        Assert::notNull($schema->getColumn('revision'));
        Assert::notNull($schema->getColumn('created_at'));
        Assert::notNull($schema->getColumn('updated_at'));

        $row = $this->db->createCommand("SELECT state, revision FROM ab_experiments WHERE name = 'paused-exp'")->queryOne();
        Assert::same($row['state'], 'paused');
        Assert::same((int) $row['revision'], 1);
    }
}
