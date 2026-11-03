<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTesting\ExperimentProvider;

/**
 * @internal
 */
final readonly class FixedExperimentProvider implements ExperimentProvider
{
    /**
     * @return array<string, never>
     */
    #[\Override]
    public function getExperiments(): array
    {
        return [];
    }
}
