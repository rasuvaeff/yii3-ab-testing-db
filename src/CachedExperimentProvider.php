<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
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
        try {
            /** @var array<string, Experiment>|null $cached */
            $cached = $this->cache->get(key: self::CACHE_KEY);
        } catch (CacheInvalidArgumentException) {
            $cached = null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $experiments = $this->inner->getExperiments();

        try {
            $this->cache->set(key: self::CACHE_KEY, value: $experiments, ttl: $this->ttl);
        } catch (CacheInvalidArgumentException) {
            // Cache write failure is non-fatal; experiments are still returned.
        }

        return $experiments;
    }

    public function clear(): void
    {
        try {
            $this->cache->delete(key: self::CACHE_KEY);
        } catch (CacheInvalidArgumentException) {
            // Cache clear failure is non-fatal.
        }
    }
}
