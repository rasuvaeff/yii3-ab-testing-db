<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * @internal
 */
final class InvalidCacheKeyException extends \InvalidArgumentException implements InvalidArgumentException {}
