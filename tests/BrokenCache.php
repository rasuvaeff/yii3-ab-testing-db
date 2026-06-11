<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache whose every operation throws a runtime exception, mimicking a
 * down backend (broken Redis connection), to exercise the non-fatal
 * cache-failure branches of CachedExperimentProvider.
 *
 * @internal
 */
final class BrokenCache implements CacheInterface
{
    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        throw new \RuntimeException('connection refused');
    }

    #[\Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        throw new \RuntimeException('connection refused');
    }

    #[\Override]
    public function delete(string $key): bool
    {
        throw new \RuntimeException('connection refused');
    }

    #[\Override]
    public function clear(): bool
    {
        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    #[\Override]
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return false;
    }
}
