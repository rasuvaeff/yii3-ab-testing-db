<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversClass(CachedExperimentProvider::class)]
final class CachedExperimentProviderTest extends TestCase
{
    private const string CACHE_KEY = 'rasuvaeff.ab-testing.experiments';

    #[Test]
    public function loadsFromInnerOnMissAndStoresWithKeyAndDefaultTtl(): void
    {
        $experiment = $this->experiment('test-exp');
        $inner = $this->createStub(ExperimentProvider::class);
        $inner->method('getExperiments')->willReturn(['test-exp' => $experiment]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('set')->with(
            key: self::CACHE_KEY,
            value: ['test-exp' => $experiment],
            ttl: 60,
        );

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache);
        $result = $provider->getExperiments();

        $this->assertArrayHasKey('test-exp', $result);
        $this->assertSame('test-exp', $result['test-exp']->name);
    }

    #[Test]
    public function passesConfiguredTtlToCache(): void
    {
        $inner = $this->createStub(ExperimentProvider::class);
        $inner->method('getExperiments')->willReturn([]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('set')->with(
            key: self::CACHE_KEY,
            value: [],
            ttl: 120,
        );

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache, ttl: 120);
        $provider->getExperiments();
    }

    #[Test]
    public function returnsCachedWithoutCallingInnerOnHit(): void
    {
        $cache = new MemorySimpleCache();
        $cache->set(self::CACHE_KEY, ['exp-a' => $this->experiment('exp-a'), 'exp-b' => $this->experiment('exp-b')]);

        $inner = $this->createMock(ExperimentProvider::class);
        $inner->expects($this->never())->method('getExperiments');

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache, ttl: 60);
        $result = $provider->getExperiments();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('exp-a', $result);
        $this->assertArrayHasKey('exp-b', $result);
    }

    #[Test]
    public function roundTripServesSecondCallFromCache(): void
    {
        $experiments = ['exp-a' => $this->experiment('exp-a'), 'exp-b' => $this->experiment('exp-b')];
        $inner = $this->createMock(ExperimentProvider::class);
        $inner->expects($this->once())->method('getExperiments')->willReturn($experiments);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new MemorySimpleCache(), ttl: 60);

        $first = $provider->getExperiments();
        $second = $provider->getExperiments();

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertArrayHasKey('exp-b', $first);
        $this->assertArrayHasKey('exp-b', $second);
    }

    #[Test]
    public function clearRemovesCachedKey(): void
    {
        $cache = new MemorySimpleCache();
        $cache->set(self::CACHE_KEY, ['rt-exp' => $this->experiment('rt-exp')]);

        $inner = $this->createStub(ExperimentProvider::class);
        $inner->method('getExperiments')->willReturn([]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache, ttl: 60);
        $provider->clear();

        $this->assertFalse($cache->has(self::CACHE_KEY));
    }

    #[Test]
    public function clearForcesReloadFromInner(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = $this->createMock(ExperimentProvider::class);
        $inner->expects($this->exactly(2))->method('getExperiments')->willReturn(['rt-exp' => $experiment]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new MemorySimpleCache(), ttl: 60);

        $provider->getExperiments();
        $provider->clear();
        $provider->getExperiments();
    }

    #[Test]
    public function fallsBackToInnerWhenCacheReadAndWriteFail(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = $this->createStub(ExperimentProvider::class);
        $inner->method('getExperiments')->willReturn(['rt-exp' => $experiment]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new ThrowingCache(), ttl: 60);
        $result = $provider->getExperiments();

        $this->assertArrayHasKey('rt-exp', $result);
        $this->assertSame('rt-exp', $result['rt-exp']->name);
    }

    #[Test]
    public function clearIsNonFatalWhenCacheThrows(): void
    {
        $inner = $this->createStub(ExperimentProvider::class);
        $inner->method('getExperiments')->willReturn([]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new ThrowingCache(), ttl: 60);

        $this->expectNotToPerformAssertions();

        $provider->clear();
    }

    private function experiment(string $name): Experiment
    {
        return new Experiment(
            name: $name,
            enabled: true,
            salt: $name,
            fallbackVariant: 'control',
            variants: ['control' => 50, 'green' => 50],
        );
    }
}
