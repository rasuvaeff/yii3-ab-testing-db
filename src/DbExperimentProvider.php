<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * @api
 */
final readonly class DbExperimentProvider implements CacheNamespaceProvider, ExperimentProvider
{
    private string $table;

    /**
     * @param non-empty-string $table
     *
     * @throws \InvalidArgumentException when the name is not a valid identifier
     */
    public function __construct(
        private ConnectionInterface $db,
        string $table = 'ab_experiments',
    ) {
        // validation lives in the value object, so the provider and the bundled
        // migrations cannot disagree about what a valid table name is
        $this->table = (new AbExperimentsTableName($table))->value;
    }

    /**
     * @return array<string, Experiment>
     */
    #[\Override]
    public function getExperiments(): array
    {
        $rows = (new Query($this->db))
            ->from($this->table)
            ->all();

        $mapper = new ExperimentRowMapper();
        $experiments = [];

        foreach ($rows as $row) {
            /** @var array<array-key, mixed> $row */
            $experiment = $mapper->map(row: $row);
            $experiments[$experiment->name] = $experiment;
        }

        return $experiments;
    }

    #[\Override]
    public function getCacheNamespace(): string
    {
        return 'db.' . $this->table;
    }
}
