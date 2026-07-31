<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

/**
 * @api
 */
interface ExperimentCacheInvalidator
{
    public function invalidate(): void;
}
