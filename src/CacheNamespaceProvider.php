<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

/**
 * Supplies a stable identity for an experiment source sharing a cache backend.
 *
 * @internal
 */
interface CacheNamespaceProvider
{
    public function getCacheNamespace(): string;
}
