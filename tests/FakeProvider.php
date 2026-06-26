<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;

/**
 * @internal
 */
final class FakeProvider implements ExperimentProvider
{
    public int $callCount = 0;

    /**
     * @param array<string, Experiment> $experiments
     */
    public function __construct(
        private array $experiments = [],
    ) {}

    /**
     * @return array<string, Experiment>
     */
    #[\Override]
    public function getExperiments(): array
    {
        $this->callCount++;

        return $this->experiments;
    }
}
