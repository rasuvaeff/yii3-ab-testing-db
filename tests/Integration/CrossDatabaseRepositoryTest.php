<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentRepository;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
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
            $db->createCommand('DROP TABLE IF EXISTS ab_experiments')->execute();
            $db->createCommand(sql: '
                CREATE TABLE ab_experiments (
                    name VARCHAR(190) PRIMARY KEY,
                    enabled BOOLEAN NOT NULL,
                    salt VARCHAR(190) NOT NULL,
                    fallback_variant VARCHAR(190) NOT NULL,
                    variants TEXT NOT NULL,
                    targeting TEXT NULL,
                    state VARCHAR(20) NOT NULL,
                    revision INTEGER NOT NULL,
                    created_at VARCHAR(32) NOT NULL,
                    updated_at VARCHAR(32) NOT NULL
                )
            ')->execute();

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
