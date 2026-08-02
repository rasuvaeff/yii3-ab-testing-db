<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTestingDb\AbAssignmentsTableName;
use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260619000001AddTargetingToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260731000000AddOperationalFieldsToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000000CreateAbAssignmentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000001AddScheduleToAbExperiments;
use Testo\Assert;
use Testo\Codecov\Covers;
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
#[Covers(M260610000000CreateAbExperimentsTable::class)]
#[Covers(M260619000001AddTargetingToAbExperiments::class)]
#[Covers(M260731000000AddOperationalFieldsToAbExperiments::class)]
#[Covers(M260801000000CreateAbAssignmentsTable::class)]
#[Covers(M260801000001AddScheduleToAbExperiments::class)]
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
        (new M260619000001AddTargetingToAbExperiments())->up($this->builder);
        (new M260731000000AddOperationalFieldsToAbExperiments())->up($this->builder);

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
                  VALUES ('paused-exp', 0, 'v1', 'control', '{\"control\":100}'),
                         ('running-exp', 1, 'v1', 'control', '{\"control\":100}')",
        )->execute();

        (new M260731000000AddOperationalFieldsToAbExperiments())->up($this->builder);

        $schema = $this->db->getTableSchema('ab_experiments', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('state'));
        Assert::notNull($schema->getColumn('revision'));
        Assert::notNull($schema->getColumn('created_at'));
        Assert::notNull($schema->getColumn('updated_at'));

        $paused = $this->row('paused-exp');
        Assert::same($paused['state'], 'paused');
        Assert::same((int) $paused['revision'], 1);

        $running = $this->row('running-exp');
        Assert::same($running['state'], 'running');
        Assert::same((int) $running['revision'], 1);

        foreach ([$paused, $running] as $row) {
            Assert::false(str_starts_with((string) $row['created_at'], '1970-'));
            Assert::same($row['created_at'], $row['updated_at']);
        }
    }

    public function createsAndDropsAssignmentsTable(): void
    {
        $migration = new M260801000000CreateAbAssignmentsTable();

        $migration->up($this->builder);

        $schema = $this->db->getTableSchema('ab_assignments', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('experiment'));
        Assert::notNull($schema->getColumn('subject_id'));
        Assert::notNull($schema->getColumn('variant'));
        Assert::notNull($schema->getColumn('configuration_id'));
        Assert::notNull($schema->getColumn('created_at'));
        Assert::notNull($schema->getColumn('updated_at'));

        $indexes = $this->db->getSchema()->getTableIndexes('ab_assignments', true);
        $byName = [];
        foreach ($indexes as $index) {
            $byName[$index->name] = $index;
        }

        Assert::array($byName)->hasKeys('ab_assignments_subject_uq', 'ab_assignments_subject_idx');
        Assert::same($byName['ab_assignments_subject_uq']->columnNames, ['experiment', 'subject_id']);
        Assert::true($byName['ab_assignments_subject_uq']->isUnique);
        Assert::same($byName['ab_assignments_subject_idx']->columnNames, ['subject_id']);
        Assert::false($byName['ab_assignments_subject_idx']->isUnique);

        $migration->down($this->builder);

        Assert::null($this->db->getTableSchema('ab_assignments', true));
    }

    public function assignmentsTableRejectsADuplicateSubjectPerExperiment(): void
    {
        (new M260801000000CreateAbAssignmentsTable())->up($this->builder);

        $insert = "INSERT INTO ab_assignments (experiment, subject_id, variant, created_at, updated_at)
                    VALUES ('checkout', 'user-1', 'control', '2026-08-01 00:00:00.000000', '2026-08-01 00:00:00.000000')";
        $this->db->createCommand(sql: $insert)->execute();

        try {
            $this->db->createCommand(sql: $insert)->execute();
            Assert::fail('Expected a unique constraint violation');
        } catch (\Yiisoft\Db\Exception\IntegrityException) {
        }

        /** @var array<string, mixed> $row */
        $row = $this->db->createCommand(
            sql: 'SELECT COUNT(*) AS cnt FROM ab_assignments WHERE experiment = :e AND subject_id = :s',
            params: ['e' => 'checkout', 's' => 'user-1'],
        )->queryOne();
        Assert::same((int) $row['cnt'], 1);
    }

    public function createsAssignmentsTableWithCustomName(): void
    {
        (new M260801000000CreateAbAssignmentsTable(table: new AbAssignmentsTableName('custom_assignments')))
            ->up($this->builder);

        Assert::notNull($this->db->getTableSchema('custom_assignments', true));
        Assert::null($this->db->getTableSchema('ab_assignments', true));
    }

    public function addsTheScheduleColumnsNullByDefault(): void
    {
        (new M260610000000CreateAbExperimentsTable())->up($this->builder);
        (new M260801000001AddScheduleToAbExperiments())->up($this->builder);

        $schema = $this->db->getTableSchema('ab_experiments', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('starts_at'));
        Assert::notNull($schema->getColumn('ends_at'));

        $this->db->createCommand(
            sql: "INSERT INTO ab_experiments (name, enabled, salt, fallback_variant, variants)
                  VALUES ('checkout', 1, 'v1', 'control', '{\"control\":100}')",
        )->execute();
        /** @var array<string, mixed> $row */
        $row = $this->db->createCommand(
            sql: 'SELECT starts_at, ends_at FROM ab_experiments WHERE name = :name',
            params: ['name' => 'checkout'],
        )->queryOne();
        Assert::null($row['starts_at']);
        Assert::null($row['ends_at']);
    }

    /** @return array<string, mixed> */
    private function row(string $name): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->db->createCommand(
            sql: 'SELECT state, revision, created_at, updated_at FROM ab_experiments WHERE name = :name',
            params: ['name' => $name],
        )->queryOne();

        return $row;
    }
}
