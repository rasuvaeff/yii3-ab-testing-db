<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(CachedExperimentProvider::class)]
final class CachedExperimentProviderTest
{
    private const string CACHE_KEY = 'rasuvaeff.ab-testing.experiments';

    public function loadsFromInnerOnMissAndStoresInCache(): void
    {
        $experiment = $this->experiment('test-exp');
        $inner = new FakeProvider(['test-exp' => $experiment]);
        $cache = new MemorySimpleCache();

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache);
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('test-exp');
        Assert::same($result['test-exp']->name, 'test-exp');
        Assert::true($cache->has(self::CACHE_KEY));
    }

    public function passesConfiguredTtlToCache(): void
    {
        $inner = new FakeProvider([]);
        $cache = new MemorySimpleCache();

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache, ttl: 120);
        $provider->getExperiments();

        Assert::true($cache->has(self::CACHE_KEY));
    }

    public function returnsCachedWithoutCallingInnerOnHit(): void
    {
        $cache = new MemorySimpleCache();
        $cache->set(self::CACHE_KEY, ['exp-a' => $this->experiment('exp-a'), 'exp-b' => $this->experiment('exp-b')]);

        $inner = new FakeProvider([]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache, ttl: 60);
        $result = $provider->getExperiments();

        Assert::count($result, 2);
        Assert::array($result)->hasKeys('exp-a', 'exp-b');
        Assert::same($inner->callCount, 0);
    }

    public function roundTripServesSecondCallFromCache(): void
    {
        $experiments = ['exp-a' => $this->experiment('exp-a'), 'exp-b' => $this->experiment('exp-b')];
        $inner = new FakeProvider($experiments);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new MemorySimpleCache(), ttl: 60);

        $first = $provider->getExperiments();
        $second = $provider->getExperiments();

        Assert::count($first, 2);
        Assert::count($second, 2);
        Assert::array($first)->hasKeys('exp-b');
        Assert::array($second)->hasKeys('exp-b');
        Assert::same($inner->callCount, 1);
    }

    public function clearRemovesCachedKey(): void
    {
        $cache = new MemorySimpleCache();
        $cache->set(self::CACHE_KEY, ['rt-exp' => $this->experiment('rt-exp')]);

        $inner = new FakeProvider([]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache, ttl: 60);
        $provider->clear();

        Assert::false($cache->has(self::CACHE_KEY));
    }

    public function clearForcesReloadFromInner(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = new FakeProvider(['rt-exp' => $experiment]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new MemorySimpleCache(), ttl: 60);

        $provider->getExperiments();
        $provider->clear();
        $provider->getExperiments();

        Assert::same($inner->callCount, 2);
    }

    public function fallsBackToInnerWhenCacheReadAndWriteFail(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = new FakeProvider(['rt-exp' => $experiment]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new ThrowingCache(), ttl: 60);
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
        Assert::same($result['rt-exp']->name, 'rt-exp');
    }

    public function fallsBackToInnerWhenCacheBackendIsDown(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = new FakeProvider(['rt-exp' => $experiment]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new BrokenCache(), ttl: 60);
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
        Assert::same($result['rt-exp']->name, 'rt-exp');
    }

    public function clearIsNonFatalWhenCacheBackendIsDown(): void
    {
        $inner = new FakeProvider([]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new BrokenCache(), ttl: 60);
        $provider->clear();
    }

    public function ignoresCorruptedNonArrayCacheValue(): void
    {
        $cache = new MemorySimpleCache();
        $cache->set(self::CACHE_KEY, 'corrupted');

        $experiment = $this->experiment('rt-exp');
        $inner = new FakeProvider(['rt-exp' => $experiment]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: $cache, ttl: 60);
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
    }

    public function clearIsNonFatalWhenCacheThrows(): void
    {
        $inner = new FakeProvider([]);

        $provider = new CachedExperimentProvider(inner: $inner, cache: new ThrowingCache(), ttl: 60);
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
