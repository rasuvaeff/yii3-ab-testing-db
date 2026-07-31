<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260619000001AddTargetingToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260731000000AddOperationalFieldsToAbExperiments;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Injector\Injector;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The migrations are created by `yiisoft/db-migration` through
 * `Injector::make()`, not by the container, so a test that instantiates them
 * directly proves nothing about whether configuration actually reaches them.
 * These go through the real resolver.
 */
#[Test]
#[Covers(M260610000000CreateAbExperimentsTable::class)]
#[Covers(M260619000001AddTargetingToAbExperiments::class)]
#[Covers(M260731000000AddOperationalFieldsToAbExperiments::class)]
final class MigrationTableNameTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
    }

    public function allMigrationsFollowTheSameConfiguredTable(): void
    {
        // the bug this pins: in 1.x each migration hard-coded its own default,
        // so a configured table got CREATEd under the custom name while the
        // later ALTER went to `ab_experiments`
        $container = new SimpleContainer([
            AbExperimentsTableName::class => new AbExperimentsTableName('custom_experiments'),
        ]);
        $builder = $this->builder();

        $this->create($container)->up($builder);
        $this->addTargeting($container)->up($builder);
        $this->addOperationalFields($container)->up($builder);

        $schema = $this->db->getTableSchema('custom_experiments', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('targeting'));
        Assert::notNull($schema->getColumn('revision'));
        Assert::null($this->db->getTableSchema('ab_experiments', true));
    }

    public function withoutABindingTheDefaultNameIsUsed(): void
    {
        // Injector falls back to the parameter default, so the package stays
        // usable with no configuration at all
        $builder = $this->builder();
        $container = new SimpleContainer([]);

        $this->create($container)->up($builder);
        $this->addTargeting($container)->up($builder);
        $this->addOperationalFields($container)->up($builder);

        Assert::notNull($this->db->getTableSchema('ab_experiments', true));
    }

    public function createsTheDocumentedColumnSet(): void
    {
        // the column list IS the contract with DbExperimentProvider: a column
        // silently dropped here surfaces only as a failing query in production
        $builder = $this->builder();
        $container = new SimpleContainer([]);

        $this->create($container)->up($builder);
        $this->addTargeting($container)->up($builder);
        $this->addOperationalFields($container)->up($builder);

        $schema = $this->db->getTableSchema('ab_experiments', true);
        Assert::notNull($schema);
        Assert::same(array_keys($schema->getColumns()), [
            'name',
            'enabled',
            'salt',
            'fallback_variant',
            'variants',
            'targeting',
            'state',
            'revision',
            'created_at',
            'updated_at',
        ]);
    }

    public function operationalFieldsBackfillExistingRowsFromTheEnabledFlag(): void
    {
        // the backfill runs once per installation and cannot be re-run: a row
        // left in the wrong lifecycle state would silently misreport a running
        // experiment as paused (and vice versa) in every later listing
        $builder = $this->builder();
        $container = new SimpleContainer([]);

        $this->create($container)->up($builder);
        $this->addTargeting($container)->up($builder);
        $this->db->createCommand(
            sql: "INSERT INTO ab_experiments (name, enabled, salt, fallback_variant, variants)
                  VALUES ('paused-exp', 0, 'v1', 'control', '{\"control\":100}'),
                         ('running-exp', 1, 'v1', 'control', '{\"control\":100}')",
        )->execute();

        $this->addOperationalFields($container)->up($builder);

        $paused = $this->row('paused-exp');
        $running = $this->row('running-exp');

        Assert::same($paused['state'], 'paused');
        Assert::same($running['state'], 'running');

        foreach ([$paused, $running] as $row) {
            Assert::same((int) $row['revision'], 1);
            Assert::false(str_starts_with((string) $row['created_at'], '1970-'));
            Assert::false(str_starts_with((string) $row['updated_at'], '1970-'));
            Assert::same($row['created_at'], $row['updated_at']);
        }
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

    public function downDropsTheConfiguredTable(): void
    {
        // only the create migration's down() is exercised here: yiisoft/db's
        // SQLite builder does not implement dropColumn, so the targeting
        // migration's down() cannot run on the in-memory database
        $container = new SimpleContainer([
            AbExperimentsTableName::class => new AbExperimentsTableName('custom_experiments'),
        ]);
        $builder = $this->builder();

        $this->create($container)->up($builder);
        $this->create($container)->down($builder);

        Assert::null($this->db->getTableSchema('custom_experiments', true));
    }

    private function create(SimpleContainer $container): M260610000000CreateAbExperimentsTable
    {
        /** @var M260610000000CreateAbExperimentsTable */
        return (new Injector($container))->make(M260610000000CreateAbExperimentsTable::class);
    }

    private function addTargeting(SimpleContainer $container): M260619000001AddTargetingToAbExperiments
    {
        /** @var M260619000001AddTargetingToAbExperiments */
        return (new Injector($container))->make(M260619000001AddTargetingToAbExperiments::class);
    }

    private function addOperationalFields(SimpleContainer $container): M260731000000AddOperationalFieldsToAbExperiments
    {
        /** @var M260731000000AddOperationalFieldsToAbExperiments */
        return (new Injector($container))->make(M260731000000AddOperationalFieldsToAbExperiments::class);
    }

    private function builder(): MigrationBuilder
    {
        return new MigrationBuilder($this->db, new NullMigrationInformer());
    }
}
