<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\TargetingRuleCodecRegistry;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * DB-backed operational repository with optimistic revision checks.
 *
 * @api
 */
final readonly class DbExperimentRepository implements ExperimentRepository
{
    private string $table;

    private ExperimentRowMapper $mapper;

    public function __construct(
        private ConnectionInterface $db,
        string $table = 'ab_experiments',
        TargetingRuleCodecRegistry $targetingCodecs = new TargetingRuleCodecRegistry(),
        private ?ExperimentCacheInvalidator $cacheInvalidator = null,
    ) {
        $this->table = (new AbExperimentsTableName($table))->value;
        $this->mapper = new ExperimentRowMapper(targetingCodecs: $targetingCodecs);
    }

    /** @return array<string, ExperimentRecord> */
    #[\Override]
    public function list(): array
    {
        $rows = (new Query($this->db))->from($this->table)->all();
        $records = [];

        foreach ($rows as $row) {
            /** @var array<array-key, mixed> $row */
            $record = $this->mapper->mapRecord($row);
            $records[$record->experiment->name] = $record;
        }

        return $records;
    }

    #[\Override]
    public function get(string $name): ExperimentRecord
    {
        return $this->find($name)
            ?? throw new Exception\ExperimentNotFoundException(
                sprintf('Experiment "%s" does not exist', $name),
            );
    }

    #[\Override]
    public function create(Experiment $experiment, ?ExperimentState $state = null): ExperimentRecord
    {
        $state ??= $experiment->enabled ? ExperimentState::Running : ExperimentState::Paused;

        try {
            $this->db->transaction(function () use ($experiment, $state): void {
                if ($this->find($experiment->name) instanceof ExperimentRecord) {
                    throw new Exception\ExperimentAlreadyExistsException(
                        sprintf('Experiment "%s" already exists', $experiment->name),
                    );
                }

                $now = $this->now();
                $this->db->createCommand()->insert(
                    table: $this->table,
                    columns: $this->toRow(
                        experiment: $experiment,
                        state: $state,
                        revision: 1,
                        createdAt: $now,
                        updatedAt: $now,
                    ),
                )->execute();
            });
        } catch (IntegrityException $e) {
            throw new Exception\ExperimentAlreadyExistsException(
                message: sprintf('Experiment "%s" already exists', $experiment->name),
                code: $e->getCode(),
                previous: $e,
            );
        }

        $this->invalidateCache();

        return $this->get($experiment->name);
    }

    #[\Override]
    public function upsert(
        Experiment $experiment,
        ?int $expectedRevision = null,
        ?ExperimentState $state = null,
    ): ExperimentRecord {
        $current = $this->find($experiment->name);

        if (!$current instanceof ExperimentRecord) {
            if ($expectedRevision !== null) {
                throw new Exception\ExperimentNotFoundException(
                    sprintf('Experiment "%s" does not exist', $experiment->name),
                );
            }

            return $this->create(experiment: $experiment, state: $state);
        }

        $state ??= $experiment->enabled ? ExperimentState::Running : ExperimentState::Paused;
        $this->update(
            experiment: $experiment,
            state: $state,
            expectedRevision: $expectedRevision ?? $current->revision,
        );

        return $this->get($experiment->name);
    }

    #[\Override]
    public function enable(string $name, int $expectedRevision): ExperimentRecord
    {
        $current = $this->get($name);
        $this->update(
            experiment: $current->experiment,
            state: ExperimentState::Running,
            expectedRevision: $expectedRevision,
        );

        return $this->get($name);
    }

    #[\Override]
    public function disable(string $name, int $expectedRevision): ExperimentRecord
    {
        $current = $this->get($name);
        $this->update(
            experiment: $current->experiment,
            state: ExperimentState::Paused,
            expectedRevision: $expectedRevision,
        );

        return $this->get($name);
    }

    /** @param array<string, int<0, max>> $variants */
    #[\Override]
    public function reweight(string $name, array $variants, int $expectedRevision): ExperimentRecord
    {
        $current = $this->get($name);
        $experiment = $current->experiment;

        $this->update(
            experiment: new Experiment(
                name: $experiment->name,
                enabled: $experiment->enabled,
                salt: $experiment->salt,
                fallbackVariant: $experiment->fallbackVariant,
                variants: $variants,
                targeting: $experiment->targeting,
            ),
            state: $current->state,
            expectedRevision: $expectedRevision,
        );

        return $this->get($name);
    }

    #[\Override]
    public function archive(string $name, int $expectedRevision): ExperimentRecord
    {
        $current = $this->get($name);
        $this->update(
            experiment: $current->experiment,
            state: ExperimentState::Archived,
            expectedRevision: $expectedRevision,
        );

        return $this->get($name);
    }

    private function find(string $name): ?ExperimentRecord
    {
        $row = (new Query($this->db))
            ->from($this->table)
            ->where(['name' => $name])
            ->one();

        if ($row === null) {
            return null;
        }

        /** @var array<array-key, mixed> $row */
        return $this->mapper->mapRecord($row);
    }

    private function update(
        Experiment $experiment,
        ExperimentState $state,
        int $expectedRevision,
    ): void {
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException('Expected revision must be at least 1');
        }

        $affected = $this->db->transaction(fn(): int => $this->db->createCommand()->update(
            table: $this->table,
            columns: [
                ...$this->definitionColumns(experiment: $experiment, state: $state),
                'revision' => new Expression('revision + 1'),
                'updated_at' => $this->now(),
            ],
            condition: ['name' => $experiment->name, 'revision' => $expectedRevision],
        )->execute());

        if ($affected !== 1) {
            if (!$this->find($experiment->name) instanceof ExperimentRecord) {
                throw new Exception\ExperimentNotFoundException(
                    sprintf('Experiment "%s" does not exist', $experiment->name),
                );
            }

            throw new Exception\RevisionConflictException(
                sprintf('Experiment "%s" revision is not %d', $experiment->name, $expectedRevision),
            );
        }

        $this->invalidateCache();
    }


    /** @return array<string, scalar|null> */
    private function definitionColumns(Experiment $experiment, ExperimentState $state): array
    {
        return [
            'enabled' => $state === ExperimentState::Running,
            'salt' => $experiment->salt === $experiment->name ? '' : $experiment->salt,
            'fallback_variant' => $experiment->fallbackVariant,
            'variants' => json_encode($experiment->variants, JSON_THROW_ON_ERROR),
            'targeting' => $this->mapper->encodeTargeting($experiment->targeting),
            'state' => $state->value,
        ];
    }

    /** @return array<string, scalar|null> */
    private function toRow(
        Experiment $experiment,
        ExperimentState $state,
        int $revision,
        string $createdAt,
        string $updatedAt,
    ): array {
        return [
            'name' => $experiment->name,
            ...$this->definitionColumns(experiment: $experiment, state: $state),
            'revision' => $revision,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function invalidateCache(): void
    {
        $this->cacheInvalidator?->invalidate();
    }
}
