<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\DbAssignmentStore;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260619000001AddTargetingToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260731000000AddOperationalFieldsToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000000CreateAbAssignmentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000001AddScheduleToAbExperiments;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Mysql\Connection as MysqlConnection;
use Yiisoft\Db\Mysql\Driver as MysqlDriver;
use Yiisoft\Db\Pgsql\Connection as PgsqlConnection;
use Yiisoft\Db\Pgsql\Driver as PgsqlDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[CoversNothing]
final class CrossDatabaseRepositoryTest
{
    public function repositoryRoundTripsOnConfiguredDatabase(): void
    {
        $database = getenv('AB_TEST_DB');

        if ($database !== 'mysql' && $database !== 'pgsql') {
            Assert::true($database === false || $database === '');

            return;
        }

        $db = $this->connection($database);
        $db->open();

        try {
            $this->migrateExperiments($db);

            $repository = new DbExperimentRepository(db: $db);
            $created = $repository->create(new Experiment(
                name: 'checkout',
                enabled: true,
                salt: 'checkout-v1',
                fallbackVariant: 'control',
                variants: ['control' => 50, 'green' => 50],
            ));
            $updated = $repository->reweight(
                name: 'checkout',
                variants: ['control' => 25, 'green' => 75],
                expectedRevision: $created->revision,
            );

            Assert::same($updated->revision, 2);
            Assert::same($updated->experiment->configurationId, 'db:2');
            Assert::same($updated->experiment->variants, ['control' => 25, 'green' => 75]);
        } finally {
            $db->createCommand('DROP TABLE IF EXISTS ab_experiments')->execute();
            $db->close();
        }
    }

    /**
     * `upsert()` compiles to different SQL per driver — `ON CONFLICT` versus
     * `ON DUPLICATE KEY UPDATE` — and whether a plain unique *index* is a valid
     * conflict target is exactly the part that differs. SQLite passing proves
     * nothing about the other two.
     */
    public function assignmentStoreUpsertsOnConfiguredDatabase(): void
    {
        $database = getenv('AB_TEST_DB');

        if ($database !== 'mysql' && $database !== 'pgsql') {
            Assert::true($database === false || $database === '');

            return;
        }

        $db = $this->connection($database);
        $db->open();

        try {
            $db->createCommand('DROP TABLE IF EXISTS ab_assignments')->execute();
            (new M260801000000CreateAbAssignmentsTable())->up(
                new MigrationBuilder($db, new NullMigrationInformer()),
            );

            $store = new DbAssignmentStore(db: $db);

            $store->putForConfiguration('checkout', 'u1', 'green', 'db:1');
            Assert::same($store->getForConfiguration('checkout', 'u1', 'db:1'), 'green');

            // The upsert path: a second write for the same key must update in
            // place rather than fail on the unique index or add a row.
            $store->putForConfiguration('checkout', 'u1', 'control', 'db:2');
            Assert::same($store->getForConfiguration('checkout', 'u1', 'db:2'), 'control');
            Assert::null($store->getForConfiguration('checkout', 'u1', 'db:1'));
            Assert::same(
                (int) $db->createCommand('SELECT COUNT(*) FROM ab_assignments')->queryScalar(),
                1,
            );

            Assert::same($store->forget('u1'), 1);
        } finally {
            $db->createCommand('DROP TABLE IF EXISTS ab_assignments')->execute();
            $db->close();
        }
    }

    /**
     * The kill switch must survive a round trip through the real schema.
     *
     * `Query` reads without typecasting, so an `enabled` column comes back in
     * whatever shape the driver chooses — a native `bool` on pdo_pgsql, an
     * `int` for the `BIT(1)` that `yiisoft/db-mysql` compiles `boolean` into.
     * `ExperimentRowMapper` used to answer PHP truthiness for any string it did
     * not recognise, so a representation of *false* it had not seen before read
     * as `true` and silently re-enabled a disabled experiment.
     *
     * The table has to come from the bundled migrations for this to mean
     * anything: a hand-written `CREATE TABLE` picks its own column type and
     * proves nothing about the one operators actually get.
     */
    public function disabledExperimentStaysDisabledOnConfiguredDatabase(): void
    {
        $database = getenv('AB_TEST_DB');

        if ($database !== 'mysql' && $database !== 'pgsql') {
            Assert::true($database === false || $database === '');

            return;
        }

        $db = $this->connection($database);
        $db->open();

        try {
            $this->migrateExperiments($db);

            $repository = new DbExperimentRepository(db: $db);
            $repository->create(new Experiment(
                name: 'checkout',
                enabled: true,
                salt: 'checkout-v1',
                fallbackVariant: 'control',
                variants: ['control' => 50, 'green' => 50],
            ));

            $provider = new DbExperimentProvider(db: $db);
            Assert::true($provider->getExperiments()['checkout']->enabled);

            $db->createCommand()->update('ab_experiments', ['enabled' => false], ['name' => 'checkout'])->execute();

            Assert::false($provider->getExperiments()['checkout']->enabled);
        } finally {
            $db->createCommand('DROP TABLE IF EXISTS ab_experiments')->execute();
            $db->close();
        }
    }

    /**
     * Applies the bundled experiments migrations in order.
     *
     * This used to be a hand-written `CREATE TABLE` with no defaults, which is
     * why nothing caught that `M260610000000` and `M260619000001` both emitted
     * a literal `DEFAULT` on a TEXT column — MySQL rejects that with error
     * 1101, so the chain died at step 1 and the package could not be installed
     * on MySQL at all. Running the real migrations is the point of this test.
     */
    private function migrateExperiments(ConnectionInterface $db): void
    {
        $db->createCommand('DROP TABLE IF EXISTS ab_experiments')->execute();

        $b = new MigrationBuilder($db, new NullMigrationInformer());

        (new M260610000000CreateAbExperimentsTable())->up($b);
        (new M260619000001AddTargetingToAbExperiments())->up($b);
        (new M260731000000AddOperationalFieldsToAbExperiments())->up($b);
        (new M260801000001AddScheduleToAbExperiments())->up($b);
    }

    private function connection(string $database): ConnectionInterface
    {
        $cache = new SchemaCache(psrCache: new MemorySimpleCache());
        $mysqlPort = getenv('AB_TEST_MYSQL_PORT') ?: '3306';
        $pgsqlPort = getenv('AB_TEST_PGSQL_PORT') ?: '5432';

        return $database === 'mysql'
            ? new MysqlConnection(
                driver: new MysqlDriver(
                    dsn: sprintf('mysql:host=127.0.0.1;port=%s;dbname=ab_testing;charset=utf8mb4', $mysqlPort),
                    username: 'root',
                    password: 'ab_testing',
                ),
                schemaCache: $cache,
            )
            : new PgsqlConnection(
                driver: new PgsqlDriver(
                    dsn: sprintf('pgsql:host=127.0.0.1;port=%s;dbname=ab_testing', $pgsqlPort),
                    username: 'postgres',
                    password: 'ab_testing',
                ),
                schemaCache: $cache,
            );
    }
}
