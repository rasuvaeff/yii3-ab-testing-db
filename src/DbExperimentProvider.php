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
final readonly class DbExperimentProvider implements ExperimentProvider
{
    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private ConnectionInterface $db,
        private string $table = 'ab_experiments',
    ) {}

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
}
