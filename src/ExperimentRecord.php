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
        // Planning data, not a runtime input: the provider still reads the
        // `enabled` flag, and a scheduler flips it based on this window.
        public ExperimentSchedule $schedule = new ExperimentSchedule(),
    ) {
        if ($revision < 1) {
            throw new \InvalidArgumentException('Experiment revision must be at least 1');
        }
    }
}
