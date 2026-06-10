<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use M260610000000CreateAbExperimentsTable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversNothing]
final class MigrationTest extends TestCase
{
    private ConnectionInterface $db;

    private MigrationBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/M260610000000CreateAbExperimentsTable.php';

        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->close();
    }

    #[Test]
    public function createsAndDropsExperimentsTable(): void
    {
        $migration = new M260610000000CreateAbExperimentsTable();

        $migration->up($this->builder);

        $schema = $this->db->getTableSchema('ab_experiments', true);
        $this->assertNotNull($schema);
        $this->assertNotNull($schema->getColumn('name'));
        $this->assertNotNull($schema->getColumn('enabled'));
        $this->assertNotNull($schema->getColumn('salt'));
        $this->assertNotNull($schema->getColumn('fallback_variant'));
        $this->assertNotNull($schema->getColumn('variants'));
        $this->assertSame(['name'], $schema->getPrimaryKey());

        $migration->down($this->builder);

        $this->assertNull($this->db->getTableSchema('ab_experiments', true));
    }

    #[Test]
    public function createsTableWithCustomName(): void
    {
        (new M260610000000CreateAbExperimentsTable(table: 'custom_experiments'))->up($this->builder);

        $this->assertNotNull($this->db->getTableSchema('custom_experiments', true));
        $this->assertNull($this->db->getTableSchema('ab_experiments', true));
    }

    #[Test]
    public function migratedTableIsReadableByProvider(): void
    {
        (new M260610000000CreateAbExperimentsTable())->up($this->builder);

        $this->db->createCommand(
            sql: "INSERT INTO ab_experiments (name, enabled, salt, fallback_variant, variants)
                  VALUES ('checkout-button', 1, 'checkout-v1', 'control', '{\"control\":50,\"green\":50}')",
        )->execute();

        $experiments = (new DbExperimentProvider(db: $this->db))->getExperiments();

        $this->assertArrayHasKey('checkout-button', $experiments);
        $this->assertSame('control', $experiments['checkout-button']->fallbackVariant);
        $this->assertSame(['control' => 50, 'green' => 50], $experiments['checkout-button']->variants);
    }
}
