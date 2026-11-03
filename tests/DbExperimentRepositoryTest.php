<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3AbTesting\AttributeTargetingRule;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\Exception\ExperimentAlreadyExistsException;
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
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(DbExperimentRepository::class)]
#[Covers(RevisionConflictException::class)]
#[Covers(ExperimentRecord::class)]
#[Covers(ExperimentState::class)]
final class DbExperimentRepositoryTest
{
    private ConnectionInterface $db;

    private ExperimentCacheInvalidator $invalidator;

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
        $this->invalidator = Understudy::for(ExperimentCacheInvalidator::class);
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
        verify(fn() => $this->invalidator->invalidate(), times: 1);
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
        verify(fn() => $this->invalidator->invalidate(), times: 4);
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
        verify(fn() => $this->invalidator->invalidate(), times: 1);
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

    public function createRejectsADuplicateName(): void
    {
        $this->repository->create($this->experiment(enabled: true));

        try {
            $this->repository->create($this->experiment(enabled: false));
            Assert::fail('Expected ExperimentAlreadyExistsException');
        } catch (ExperimentAlreadyExistsException $e) {
            Assert::same($e->getMessage(), 'Experiment "checkout" already exists');
            Assert::null($e->getPrevious());
        }

        Assert::same($this->repository->get('checkout')->revision, 1);
        Assert::true($this->repository->get('checkout')->experiment->enabled);
    }

    public function createTranslatesAnIntegrityViolationFromTheInsert(): void
    {
        $violation = new IntegrityException('UNIQUE constraint failed');
        $repository = $this->repositoryWhoseTransactionAnswers(
            static function () use ($violation): never {
                throw $violation;
            },
        );

        try {
            $repository->create($this->experiment(enabled: true));
            Assert::fail('Expected ExperimentAlreadyExistsException');
        } catch (ExperimentAlreadyExistsException $e) {
            Assert::same($e->getMessage(), 'Experiment "checkout" already exists');
            Assert::same($e->getPrevious(), $violation);
        }
    }

    public function upsertCreatesAMissingExperiment(): void
    {
        $record = $this->repository->upsert($this->experiment(enabled: true));

        Assert::same($record->revision, 1);
        Assert::same($record->state, ExperimentState::Running);
    }

    public function upsertUpdatesAnExistingExperimentAndBumpsTheRevision(): void
    {
        $this->repository->create($this->experiment(enabled: true));

        $record = $this->repository->upsert(new Experiment(
            name: 'checkout',
            enabled: false,
            salt: 'checkout-v2',
            fallbackVariant: 'control',
            variants: ['control' => 20, 'green' => 80],
        ));

        Assert::same($record->revision, 2);
        Assert::same($record->state, ExperimentState::Paused);
        Assert::same($record->experiment->salt, 'checkout-v2');
        Assert::same($record->experiment->variants, ['control' => 20, 'green' => 80]);
    }

    public function upsertHonoursAnExplicitStateAndExpectedRevision(): void
    {
        $this->repository->create($this->experiment(enabled: true));
        $this->repository->disable('checkout', 1);

        $record = $this->repository->upsert(
            experiment: $this->experiment(enabled: true),
            expectedRevision: 2,
            state: ExperimentState::Archived,
        );

        Assert::same($record->state, ExperimentState::Archived);
        Assert::same($record->revision, 3);

        try {
            $this->repository->upsert(experiment: $this->experiment(enabled: true), expectedRevision: 1);
            Assert::fail('Expected RevisionConflictException');
        } catch (RevisionConflictException) {
        }

        Assert::same($this->repository->get('checkout')->revision, 3);
    }

    public function upsertWithAnExpectedRevisionRequiresTheExperimentToExist(): void
    {
        try {
            $this->repository->upsert(experiment: $this->experiment(enabled: true), expectedRevision: 1);
            Assert::fail('Expected ExperimentNotFoundException');
        } catch (ExperimentNotFoundException $e) {
            Assert::same($e->getMessage(), 'Experiment "checkout" does not exist');
        }

        Assert::same($this->repository->list(), []);
    }

    public function upsertTakesTheUpdateBranchWhenAnotherWriterWonTheInsertRace(): void
    {
        $raced = new DbExperimentRepository(db: $this->db);
        $repository = $this->repositoryWhoseTransactionAnswers(
            function () use ($raced): never {
                $raced->create($this->experiment(enabled: true));

                throw new IntegrityException('UNIQUE constraint failed');
            },
        );

        $record = $repository->upsert(new Experiment(
            name: 'checkout',
            enabled: true,
            salt: 'checkout-v1',
            fallbackVariant: 'control',
            variants: ['control' => 10, 'green' => 90],
        ));

        Assert::same($record->revision, 2);
        Assert::same($record->experiment->variants, ['control' => 10, 'green' => 90]);
    }

    public function upsertRethrowsWhenTheRacedRowIsNotThere(): void
    {
        $repository = $this->repositoryWhoseTransactionAnswers(
            static function (): never {
                throw new IntegrityException('UNIQUE constraint failed');
            },
        );

        try {
            $repository->upsert($this->experiment(enabled: true));
            Assert::fail('Expected ExperimentAlreadyExistsException');
        } catch (ExperimentAlreadyExistsException $e) {
            Assert::same($e->getMessage(), 'Experiment "checkout" already exists');
        }

        Assert::same($this->repository->list(), []);
    }

    public function rejectsAnExpectedRevisionBelowOne(): void
    {
        $this->repository->create($this->experiment(enabled: true));

        foreach ([0, -1] as $revision) {
            try {
                $this->repository->disable('checkout', $revision);
                Assert::fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                Assert::same($e->getMessage(), 'Expected revision must be at least 1');
            }
        }

        Assert::same($this->repository->get('checkout')->revision, 1);
    }

    public function updateReportsNotFoundWhenTheRowVanishesBeforeTheWrite(): void
    {
        $this->repository->create($this->experiment(enabled: true));
        $repository = $this->repositoryWhoseTransactionAnswers(
            function (Invocation $call): mixed {
                $this->db->createCommand()->delete('ab_experiments', ['name' => 'checkout'])->execute();

                return $call->callOriginal();
            },
        );

        try {
            $repository->disable('checkout', 1);
            Assert::fail('Expected ExperimentNotFoundException');
        } catch (ExperimentNotFoundException $e) {
            Assert::same($e->getMessage(), 'Experiment "checkout" does not exist');
        }
    }

    public function aRevisionBelowOneIsRejectedByTheRecord(): void
    {
        $experiment = $this->experiment(enabled: true);
        $now = new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC'));

        try {
            new ExperimentRecord(
                experiment: $experiment,
                state: ExperimentState::Running,
                revision: 0,
                createdAt: $now,
                updatedAt: $now,
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Experiment revision must be at least 1');
        }
    }

    /**
     * A repository over the same SQLite connection whose transaction() runs the
     * given answer for its first call instead of the closure it was handed (the answer may call
     * the original through the Invocation).
     */
    private function repositoryWhoseTransactionAnswers(callable $answer): DbExperimentRepository
    {
        $proxy = Understudy::delegate(ConnectionInterface::class, $this->db);
        $first = true;
        when(fn() => $proxy->transaction(Arg::any()))->answers(
            static function (Invocation $call) use ($answer, &$first): mixed {
                if (!$first) {
                    return $call->callOriginal();
                }

                $first = false;

                return $answer($call);
            },
        );

        return new DbExperimentRepository(db: $proxy);
    }
}
