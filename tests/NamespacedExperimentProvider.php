<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\CacheNamespaceProvider;

/**
 * @internal
 */
final readonly class NamespacedExperimentProvider implements ExperimentProvider, CacheNamespaceProvider
{
    public function __construct(
        private string $namespace,
    ) {}

    /**
     * @return array<string, never>
     */
    #[\Override]
    public function getExperiments(): array
    {
        return [];
    }

    #[\Override]
    public function getCacheNamespace(): string
    {
        return $this->namespace;
    }
}
