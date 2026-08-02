<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTesting\AttributeTargetingRule;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\Exception\ExperimentNotFoundException;
use Rasuvaeff\Yii3AbTestingDb\Exception\RevisionConflictException;
use Rasuvaeff\Yii3AbTestingDb\ExperimentCacheInvalidator;
use Rasuvaeff\Yii3AbTestingDb\ExperimentRecord;
use Rasuvaeff\Yii3AbTestingDb\ExperimentState;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(DbExperimentRepository::class)]
#[Covers(RevisionConflictException::class)]
#[Covers(ExperimentRecord::class)]
#[Covers(ExperimentState::class)]
final class DbExperimentRepositoryTest
{
    private ConnectionInterface $db;

    private RecordingInvalidator $invalidator;

    private DbExperimentRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
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
        $this->invalidator = new RecordingInvalidator();
        $this->repository = new DbExperimentRepository(db: $this->db, cacheInvalidator: $this->invalidator);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function createsDraftAndProjectsDbRevision(): void
    {
        $record = $this->repository->create(
            experiment: $this->experiment(enabled: false),
            state: ExperimentState::Draft,
        );

        Assert::same($record->state, ExperimentState::Draft);
        Assert::same($record->revision, 1);
        Assert::same($record->experiment->configurationId, 'db:1');
        Assert::false($record->experiment->enabled);
        Assert::same($this->invalidator->calls, 1);
    }

    public function lifecycleAndWeightsIncrementRevision(): void
    {
        $created = $this->repository->create($this->experiment(enabled: false));
        $enabled = $this->repository->enable('checkout', $created->revision);
        $reweighted = $this->repository->reweight('checkout', ['control' => 10, 'green' => 90], $enabled->revision);
        $archived = $this->repository->archive('checkout', $reweighted->revision);

        Assert::same($enabled->state, ExperimentState::Running);
        Assert::true($enabled->experiment->enabled);
        Assert::same($reweighted->experiment->variants, ['control' => 10, 'green' => 90]);
        Assert::same($archived->state, ExperimentState::Archived);
        Assert::false($archived->experiment->enabled);
        Assert::same($archived->revision, 4);
        Assert::same($archived->experiment->configurationId, 'db:4');
        Assert::same($this->invalidator->calls, 4);
    }

    public function rejectsStaleRevisionWithoutInvalidatingCache(): void
    {
        $this->repository->create($this->experiment(enabled: true));

        try {
            $this->repository->disable('checkout', 99);
            Assert::fail('Expected RevisionConflictException');
        } catch (RevisionConflictException) {
        }

        Assert::same($this->repository->get('checkout')->revision, 1);
        Assert::same($this->invalidator->calls, 1);
    }

    public function roundTripsTargetingThroughSharedCodecRegistry(): void
    {
        $record = $this->repository->create(new Experiment(
            name: 'checkout',
            enabled: true,
            salt: 'checkout-v1',
            fallbackVariant: 'control',
            variants: ['control' => 50, 'green' => 50],
            targeting: new AttributeTargetingRule(attribute: 'plan', value: 'pro'),
        ));

        Assert::instanceOf($record->experiment->targeting, AttributeTargetingRule::class);
    }

    public function getThrowsWhenTheExperimentDoesNotExist(): void
    {
        try {
            $this->repository->get('missing');
            Assert::fail('Expected ExperimentNotFoundException');
        } catch (ExperimentNotFoundException $e) {
            Assert::string($e->getMessage())->contains('"missing" does not exist');
        }
    }

    public function createDerivesTheStateFromTheEnabledFlag(): void
    {
        Assert::same(
            $this->repository->create($this->experiment(enabled: true))->state,
            ExperimentState::Running,
        );
        Assert::same(
            $this->repository->create($this->experiment(enabled: false, name: 'search'))->state,
            ExperimentState::Paused,
        );
    }

    public function readsAreMatchedByNameNotByRowOrder(): void
    {
        $this->repository->create($this->experiment(enabled: true));
        $this->repository->create($this->experiment(enabled: false, name: 'search'));

        Assert::same($this->repository->get('search')->experiment->name, 'search');
        Assert::same($this->repository->get('checkout')->experiment->name, 'checkout');
    }

    public function anUpdateTouchesOnlyTheNamedExperiment(): void
    {
        $this->repository->create($this->experiment(enabled: true));
        $this->repository->create($this->experiment(enabled: true, name: 'search'));

        $this->repository->disable('checkout', 1);

        Assert::same($this->repository->get('checkout')->revision, 2);
        Assert::same($this->repository->get('search')->revision, 1);
        Assert::same($this->repository->get('search')->state, ExperimentState::Running);
    }

    public function aSaltEqualToTheNameRoundTripsThroughTheEmptyColumnDefault(): void
    {
        $this->repository->create(new Experiment(
            name: 'checkout',
            enabled: true,
            salt: 'checkout',
            fallbackVariant: 'control',
            variants: ['control' => 50, 'green' => 50],
        ));
        Assert::same($this->repository->get('checkout')->experiment->salt, 'checkout');

        $this->repository->create($this->experiment(enabled: true, name: 'search'));
        Assert::same($this->repository->get('search')->experiment->salt, 'checkout-v1');
    }

    public function worksWithoutACacheInvalidator(): void
    {
        $repository = new DbExperimentRepository(db: $this->db);

        $created = $repository->create($this->experiment(enabled: true));
        Assert::same($created->revision, 1);

        Assert::same($repository->disable('checkout', 1)->revision, 2);
    }

    private function experiment(bool $enabled, string $name = 'checkout'): Experiment
    {
        return new Experiment(
            name: $name,
            enabled: $enabled,
            salt: 'checkout-v1',
            fallbackVariant: 'control',
            variants: ['control' => 50, 'green' => 50],
        );
    }
}

final class RecordingInvalidator implements ExperimentCacheInvalidator
{
    public int $calls = 0;

    #[\Override]
    public function invalidate(): void
    {
        ++$this->calls;
    }
}
