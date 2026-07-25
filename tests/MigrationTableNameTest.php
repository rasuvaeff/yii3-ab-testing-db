<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260619000001AddTargetingToAbExperiments;
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

    public function bothMigrationsFollowTheSameConfiguredTable(): void
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

        $schema = $this->db->getTableSchema('custom_experiments', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('targeting'));
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

        $schema = $this->db->getTableSchema('ab_experiments', true);
        Assert::notNull($schema);
        Assert::same(array_keys($schema->getColumns()), [
            'name',
            'enabled',
            'salt',
            'fallback_variant',
            'variants',
            'targeting',
        ]);
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

    private function builder(): MigrationBuilder
    {
        return new MigrationBuilder($this->db, new NullMigrationInformer());
    }
}
