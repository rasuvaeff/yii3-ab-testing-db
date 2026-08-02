<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTestingDb\Console\CreateExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\DisableExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\EnableExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ListExperimentsCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ReweightExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ValidateExperimentsCommand;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\ExperimentState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(CreateExperimentCommand::class)]
#[Covers(DisableExperimentCommand::class)]
#[Covers(EnableExperimentCommand::class)]
#[Covers(ListExperimentsCommand::class)]
#[Covers(ReweightExperimentCommand::class)]
#[Covers(ValidateExperimentsCommand::class)]
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

    public function createStartsTheExperimentOnlyWithTheRunOption(): void
    {
        $draft = new CommandTester(new CreateExperimentCommand($this->repository));
        Assert::same($draft->execute([
            'name' => 'checkout',
            'variants' => '{"control":50,"green":50}',
            'fallback' => 'control',
        ]), Command::SUCCESS);
        Assert::string($draft->getDisplay())->contains('Created checkout at revision 1');

        $record = $this->repository->get('checkout');
        Assert::same($record->state, ExperimentState::Draft);
        Assert::false($record->experiment->enabled);

        $running = new CommandTester(new CreateExperimentCommand($this->repository));
        Assert::same($running->execute([
            'name' => 'search',
            'variants' => '{"control":50,"green":50}',
            'fallback' => 'control',
            '--run' => true,
        ]), Command::SUCCESS);

        $started = $this->repository->get('search');
        Assert::same($started->state, ExperimentState::Running);
        Assert::true($started->experiment->enabled);
    }

    public function createFallsBackToTheNameWhenNoSaltIsGiven(): void
    {
        $this->create();
        Assert::same($this->repository->get('checkout')->experiment->salt, 'checkout-v1');

        $bare = new CommandTester(new CreateExperimentCommand($this->repository));
        Assert::same($bare->execute([
            'name' => 'search',
            'variants' => '{"control":50,"green":50}',
            'fallback' => 'control',
        ]), Command::SUCCESS);

        Assert::same($this->repository->get('search')->experiment->salt, 'search');
    }

    public function createKeepsEveryVariantIncludingAZeroWeight(): void
    {
        $create = new CommandTester(new CreateExperimentCommand($this->repository));
        Assert::same($create->execute([
            'name' => 'checkout',
            'variants' => '{"control":50,"green":0,"blue":25}',
            'fallback' => 'control',
        ]), Command::SUCCESS);

        Assert::same(
            $this->repository->get('checkout')->experiment->variants,
            ['control' => 50, 'green' => 0, 'blue' => 25],
        );
    }

    #[DataProvider('rejectedVariantsProvider')]
    public function createRejectsMalformedVariants(string $variants, string $message): void
    {
        $create = new CommandTester(new CreateExperimentCommand($this->repository));

        Assert::same($create->execute([
            'name' => 'checkout',
            'variants' => $variants,
            'fallback' => 'control',
        ]), Command::FAILURE);
        Assert::string($create->getDisplay())->contains($message);
    }

    #[DataProvider('rejectedVariantsProvider')]
    public function reweightRejectsMalformedVariants(string $variants, string $message): void
    {
        $this->create();
        $reweight = new CommandTester(new ReweightExperimentCommand($this->repository));

        Assert::same($reweight->execute([
            'name' => 'checkout',
            'variants' => $variants,
            'revision' => '1',
        ]), Command::FAILURE);
        Assert::string($reweight->getDisplay())->contains($message);

        Assert::same($this->repository->get('checkout')->revision, 1);
    }

    public static function rejectedVariantsProvider(): iterable
    {
        yield 'json list' => ['[50,50]', 'Variants must be a JSON object'];
        yield 'negative weight' => ['{"control":-1}', 'Variant weights must be non-negative integers'];
        yield 'non-integer weight' => ['{"control":"50"}', 'Variant weights must be non-negative integers'];
        yield 'float weight' => ['{"control":1.5}', 'Variant weights must be non-negative integers'];
    }

    public function reweightKeepsEveryVariantIncludingAZeroWeight(): void
    {
        $this->create();
        $reweight = new CommandTester(new ReweightExperimentCommand($this->repository));

        Assert::same($reweight->execute([
            'name' => 'checkout',
            'variants' => '{"control":70,"green":0,"blue":30}',
            'revision' => '1',
        ]), Command::SUCCESS);
        Assert::string($reweight->getDisplay())->contains('Reweighted checkout at revision 2');

        Assert::same(
            $this->repository->get('checkout')->experiment->variants,
            ['control' => 70, 'green' => 0, 'blue' => 30],
        );
    }

    public function lifecycleCommandsReportTheResultingRevision(): void
    {
        $this->create();

        $enable = new CommandTester(new EnableExperimentCommand($this->repository));
        Assert::same($enable->execute(['name' => 'checkout', 'revision' => '1']), Command::SUCCESS);
        Assert::string($enable->getDisplay())->contains('Enabled checkout at revision 2');

        $disable = new CommandTester(new DisableExperimentCommand($this->repository));
        Assert::same($disable->execute(['name' => 'checkout', 'revision' => '2']), Command::SUCCESS);
        Assert::string($disable->getDisplay())->contains('Disabled checkout at revision 3');
    }

    public function failureMessagesAreWrappedInTheErrorStyle(): void
    {
        $this->create();
        $disable = new CommandTester(new DisableExperimentCommand($this->repository));

        Assert::same(
            $disable->execute(['name' => 'checkout', 'revision' => '9'], ['decorated' => true]),
            Command::FAILURE,
        );
        Assert::string($disable->getDisplay())
            ->contains($this->styledError('Experiment "checkout" revision is not 9'));

        $create = new CommandTester(new CreateExperimentCommand($this->repository));
        Assert::same($create->execute([
            'name' => 'checkout',
            'variants' => '[50,50]',
            'fallback' => 'control',
        ], ['decorated' => true]), Command::FAILURE);
        Assert::string($create->getDisplay())
            ->contains($this->styledError('Variants must be a JSON object'));

        $reweight = new CommandTester(new ReweightExperimentCommand($this->repository));
        Assert::same($reweight->execute([
            'name' => 'checkout',
            'variants' => '[50,50]',
            'revision' => '1',
        ], ['decorated' => true]), Command::FAILURE);
        Assert::string($reweight->getDisplay())
            ->contains($this->styledError('Variants must be a JSON object'));
    }

    private function styledError(string $message): string
    {
        return (new OutputFormatter(true))->format('<error>' . $message . '</error>');
    }

    public function listRendersEveryColumnForEachExperiment(): void
    {
        $this->create();
        $enable = new CommandTester(new EnableExperimentCommand($this->repository));
        Assert::same($enable->execute(['name' => 'checkout', 'revision' => '1']), Command::SUCCESS);

        $second = new CommandTester(new CreateExperimentCommand($this->repository));
        Assert::same($second->execute([
            'name' => 'search',
            'variants' => '{"control":40,"green":60}',
            'fallback' => 'control',
        ]), Command::SUCCESS);

        $list = new CommandTester(new ListExperimentsCommand($this->repository));
        Assert::same($list->execute([]), Command::SUCCESS);
        $display = $list->getDisplay();

        foreach (['Name', 'State', 'Revision', 'Enabled', 'Variants'] as $header) {
            Assert::string($display)->contains($header);
        }

        $enabledRow = $this->row($display, 'checkout');
        Assert::string($enabledRow)->contains('running');
        Assert::string($enabledRow)->contains('yes');
        Assert::string($enabledRow)->contains('2');

        $draftRow = $this->row($display, 'search');
        Assert::string($draftRow)->contains('draft');
        Assert::string($draftRow)->contains('no');
        Assert::string($draftRow)->contains('{"control":40,"green":60}');
    }

    private function row(string $display, string $name): string
    {
        foreach (explode("\n", $display) as $line) {
            if (str_contains($line, $name)) {
                return $line;
            }
        }

        return '';
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
