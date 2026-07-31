<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

/**
 * Operational lifecycle state. Runtime assignment remains controlled by the
 * projected `Experiment::enabled` flag.
 *
 * @api
 */
enum ExperimentState: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';
}
