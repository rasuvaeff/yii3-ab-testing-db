<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;

/**
 * @api
 */
final readonly class CachedExperimentProvider implements ExperimentCacheInvalidator, ExperimentProvider
{
    private const string CACHE_KEY_PREFIX = 'rasuvaeff.ab-testing.experiments.';

    private string $cacheKey;

    /**
     * @param int<0, max> $ttl TTL in seconds
     * @param ?string $namespace Stable identity when several sources share one
     * cache backend; an empty string is rejected
     */
    public function __construct(
        private ExperimentProvider $inner,
        private CacheInterface $cache,
        private int $ttl = 60,
        ?string $namespace = null,
    ) {
        if ($namespace === '') {
            throw new \InvalidArgumentException('Cache namespace must not be empty');
        }

        $source = $namespace
            ?? ($inner instanceof CacheNamespaceProvider
                ? $inner->getCacheNamespace()
                : $inner::class);

        $this->cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $source);
    }

    /**
     * @return array<string, Experiment>
     */
    #[\Override]
    public function getExperiments(): array
    {
        // Any cache failure (down Redis, broken connection, incompatible
        // serialized payload) must fall back to the inner provider, not break
        // the request — hence \Throwable, not just the PSR-16 exception.
        try {
            $cacheRead = ['value' => $this->cache->get(key: $this->cacheKey)];
        } catch (\Throwable) {
            $cacheRead = null;
        }

        $cached = $cacheRead === null
            ? null
            : $this->validateExperimentRegistry(value: $cacheRead['value']);

        if ($cached !== null) {
            return $cached;
        }

        $experiments = $this->inner->getExperiments();

        try {
            $this->cache->set(key: $this->cacheKey, value: $experiments, ttl: $this->ttl);
        } catch (\Throwable) {
            // Cache write failure is non-fatal; experiments are still returned.
        }

        return $experiments;
    }

    public function clear(): void
    {
        try {
            $this->cache->delete(key: $this->cacheKey);
        } catch (\Throwable) {
            // Cache clear failure is non-fatal.
        }
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->clear();
    }

    /**
     * @return ?array<string, Experiment>
     */
    private function validateExperimentRegistry(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $experiments = [];

        foreach ($value as $name => $experiment) {
            if (!\is_string($name)) {
                return null;
            }

            if (!$experiment instanceof Experiment) {
                return null;
            }

            if (!$this->hasMatchingName(name: $name, experiment: $experiment)) {
                return null;
            }

            $experiments[$name] = $experiment;
        }

        return $experiments;
    }

    private function hasMatchingName(string $name, Experiment $experiment): bool
    {
        return $experiment->name === $name;
    }
}
