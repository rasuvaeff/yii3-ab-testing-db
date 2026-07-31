<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Rasuvaeff\Yii3AbTesting\Experiment;

/**
 * Persisted operational metadata alongside the runtime experiment projection.
 *
 * @api
 */
final readonly class ExperimentRecord
{
    public function __construct(
        public Experiment $experiment,
        public ExperimentState $state,
        public int $revision,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
        if ($revision < 1) {
            throw new \InvalidArgumentException('Experiment revision must be at least 1');
        }
    }
}
