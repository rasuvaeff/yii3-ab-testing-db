<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;

/**
 * @api
 */
final readonly class CachedExperimentProvider implements ExperimentProvider
{
    private const string CACHE_KEY = 'rasuvaeff.ab-testing.experiments';

    /**
     * @param int<0, max> $ttl TTL in seconds
     */
    public function __construct(
        private ExperimentProvider $inner,
        private CacheInterface $cache,
        private int $ttl = 60,
    ) {}

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
            /** @var mixed $cached */
            $cached = $this->cache->get(key: self::CACHE_KEY);
        } catch (\Throwable) {
            $cached = null;
        }

        if (\is_array($cached)) {
            /** @var array<string, Experiment> $cached */
            return $cached;
        }

        $experiments = $this->inner->getExperiments();

        try {
            $this->cache->set(key: self::CACHE_KEY, value: $experiments, ttl: $this->ttl);
        } catch (\Throwable) {
            // Cache write failure is non-fatal; experiments are still returned.
        }

        return $experiments;
    }

    public function clear(): void
    {
        try {
            $this->cache->delete(key: self::CACHE_KEY);
        } catch (\Throwable) {
            // Cache clear failure is non-fatal.
        }
    }
}
