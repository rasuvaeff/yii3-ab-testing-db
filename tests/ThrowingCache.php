<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache whose every operation throws an invalid-argument exception,
 * to exercise the non-fatal cache-failure branches of CachedFlagProvider.
 *
 * @internal
 */
final class ThrowingCache implements CacheInterface
{
    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        throw new InvalidCacheKeyException('boom');
    }

    #[\Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        throw new InvalidCacheKeyException('boom');
    }

    #[\Override]
    public function delete(string $key): bool
    {
        throw new InvalidCacheKeyException('boom');
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
