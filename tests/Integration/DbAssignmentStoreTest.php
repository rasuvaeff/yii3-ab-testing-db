<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use Rasuvaeff\Yii3AbTestingDb\AbAssignmentsTableName;
use Rasuvaeff\Yii3AbTestingDb\DbAssignmentStore;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000000CreateAbAssignmentsTable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Runs against a real SQLite schema created by the shipped migration, because
 * the upsert and the composite primary key are exactly the parts a mocked
 * connection would not exercise.
 */
#[Test]
#[Covers(DbAssignmentStore::class)]
final class DbAssignmentStoreTest
{
    private ConnectionInterface $db;

    private DbAssignmentStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );

        (new M260801000000CreateAbAssignmentsTable())->up(
            new MigrationBuilder($this->db, new NullMigrationInformer()),
        );

        $this->store = new DbAssignmentStore(db: $this->db);
    }

    public function returnsNullWhenNothingIsStored(): void
    {
        Assert::null($this->store->get('checkout', 'u1'));
    }

    public function storesAndReadsBackAVariant(): void
    {
        $this->store->put('checkout', 'u1', 'green');

        Assert::same($this->store->get('checkout', 'u1'), 'green');
    }

    public function keepsSubjectsApart(): void
    {
        $this->store->put('checkout', 'u1', 'green');
        $this->store->put('checkout', 'u2', 'control');

        Assert::same($this->store->get('checkout', 'u1'), 'green');
        Assert::same($this->store->get('checkout', 'u2'), 'control');
    }

    public function keepsExperimentsApartForTheSameSubject(): void
    {
        $this->store->put('checkout', 'u1', 'green');
        $this->store->put('pricing', 'u1', 'control');

        Assert::same($this->store->get('checkout', 'u1'), 'green');
        Assert::same($this->store->get('pricing', 'u1'), 'control');
    }

    /**
     * Writing twice must leave one row, not a duplicate-key error and not a
     * second row: the write is an upsert precisely so concurrent requests for
     * one subject converge.
     */
    public function repeatedWritesAreIdempotent(): void
    {
        $this->store->put('checkout', 'u1', 'green');
        $this->store->put('checkout', 'u1', 'green');

        Assert::same($this->store->get('checkout', 'u1'), 'green');
        Assert::same($this->rowCount(), 1);
    }

    public function reusesTheVariantForTheSameConfiguration(): void
    {
        $this->store->putForConfiguration('checkout', 'u1', 'green', 'rev-1');

        Assert::same($this->store->getForConfiguration('checkout', 'u1', 'rev-1'), 'green');
    }

    /**
     * The reason the configuration-aware variant exists: after a reweight the
     * stored variant came from bucket boundaries that no longer exist, so it
     * must not be reused.
     */
    public function refusesTheVariantOfADifferentConfiguration(): void
    {
        $this->store->putForConfiguration('checkout', 'u1', 'green', 'rev-1');

        Assert::null($this->store->getForConfiguration('checkout', 'u1', 'rev-2'));
    }

    public function anUnknownConfigurationMatchesOnlyAnUnknownOne(): void
    {
        $this->store->putForConfiguration('checkout', 'u1', 'green', null);

        Assert::same($this->store->getForConfiguration('checkout', 'u1', null), 'green');
        Assert::null($this->store->getForConfiguration('checkout', 'u1', 'rev-1'));
    }

    public function aNewConfigurationOverwritesTheStoredVariantInPlace(): void
    {
        $this->store->putForConfiguration('checkout', 'u1', 'green', 'rev-1');
        $this->store->putForConfiguration('checkout', 'u1', 'control', 'rev-2');

        Assert::same($this->store->getForConfiguration('checkout', 'u1', 'rev-2'), 'control');
        Assert::null($this->store->getForConfiguration('checkout', 'u1', 'rev-1'));
        Assert::same($this->rowCount(), 1);
    }

    public function theLegacyReadIgnoresConfiguration(): void
    {
        $this->store->putForConfiguration('checkout', 'u1', 'green', 'rev-1');

        Assert::same($this->store->get('checkout', 'u1'), 'green');
    }

    public function forgetErasesEverySubjectRowAcrossExperiments(): void
    {
        $this->store->put('checkout', 'u1', 'green');
        $this->store->put('pricing', 'u1', 'control');
        $this->store->put('checkout', 'u2', 'green');

        $removed = $this->store->forget('u1');

        Assert::same($removed, 2);
        Assert::null($this->store->get('checkout', 'u1'));
        Assert::null($this->store->get('pricing', 'u1'));
        Assert::same($this->store->get('checkout', 'u2'), 'green');
    }

    public function deleteExperimentDropsOnlyThatExperiment(): void
    {
        $this->store->put('checkout', 'u1', 'green');
        $this->store->put('pricing', 'u1', 'control');

        $removed = $this->store->deleteExperiment('checkout');

        Assert::same($removed, 1);
        Assert::null($this->store->get('checkout', 'u1'));
        Assert::same($this->store->get('pricing', 'u1'), 'control');
    }

    public function honoursAConfiguredTableName(): void
    {
        (new M260801000000CreateAbAssignmentsTable(new AbAssignmentsTableName('custom_assignments')))->up(
            new MigrationBuilder($this->db, new NullMigrationInformer()),
        );
        $store = new DbAssignmentStore(db: $this->db, table: 'custom_assignments');

        $store->put('checkout', 'u1', 'green');

        Assert::same($store->get('checkout', 'u1'), 'green');
        // written to the configured table, not the default one
        Assert::null($this->store->get('checkout', 'u1'));
    }

    public function rejectsAnInvalidTableName(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Invalid table name "bad name"');

        new DbAssignmentStore(db: $this->db, table: 'bad name');
    }

    private function rowCount(): int
    {
        return (int) $this->db->createCommand('SELECT COUNT(*) FROM ab_assignments')->queryScalar();
    }
}
