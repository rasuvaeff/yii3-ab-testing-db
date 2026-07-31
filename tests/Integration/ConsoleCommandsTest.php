<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use Rasuvaeff\Yii3AbTestingDb\Console\CreateExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\DisableExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\EnableExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ListExperimentsCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ReweightExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ValidateExperimentsCommand;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[CoversNothing]
final class ConsoleCommandsTest
{
    private ConnectionInterface $db;

    private DbExperimentRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new Connection(
            driver: new Driver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        $this->db->createCommand(sql: '
            CREATE TABLE ab_experiments (
                name VARCHAR(190) PRIMARY KEY,
                enabled INTEGER NOT NULL,
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
        $this->repository = new DbExperimentRepository(db: $this->db);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function validateAndListReportStoredExperiments(): void
    {
        $validate = new CommandTester(new ValidateExperimentsCommand($this->repository));
        Assert::same($validate->execute([]), Command::SUCCESS);
        Assert::string($validate->getDisplay())->contains('Valid experiments: 0');

        $this->create();
        $list = new CommandTester(new ListExperimentsCommand($this->repository));
        Assert::same($list->execute([]), Command::SUCCESS);
        Assert::string($list->getDisplay())->contains('checkout');
    }

    public function lifecycleCommandsRequireAndAdvanceRevision(): void
    {
        $this->create();

        $enable = new CommandTester(new EnableExperimentCommand($this->repository));
        Assert::same($enable->execute(['name' => 'checkout', 'revision' => '1']), Command::SUCCESS);

        $reweight = new CommandTester(new ReweightExperimentCommand($this->repository));
        Assert::same($reweight->execute([
            'name' => 'checkout',
            'variants' => '{"control":10,"green":90}',
            'revision' => '2',
        ]), Command::SUCCESS);

        $disable = new CommandTester(new DisableExperimentCommand($this->repository));
        Assert::same($disable->execute(['name' => 'checkout', 'revision' => '3']), Command::SUCCESS);

        $record = $this->repository->get('checkout');
        Assert::same($record->revision, 4);
        Assert::false($record->experiment->enabled);
        Assert::same($record->experiment->variants, ['control' => 10, 'green' => 90]);
    }

    public function staleRevisionReturnsFailure(): void
    {
        $this->create();
        $disable = new CommandTester(new DisableExperimentCommand($this->repository));

        Assert::same($disable->execute(['name' => 'checkout', 'revision' => '9']), Command::FAILURE);
        Assert::string($disable->getDisplay())->contains('revision is not 9');
    }

    private function create(): void
    {
        $create = new CommandTester(new CreateExperimentCommand($this->repository));
        Assert::same($create->execute([
            'name' => 'checkout',
            'variants' => '{"control":50,"green":50}',
            'fallback' => 'control',
            '--salt' => 'checkout-v1',
        ]), Command::SUCCESS);
    }
}
