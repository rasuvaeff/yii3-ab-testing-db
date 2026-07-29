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
    private const string DEFAULT_NAMESPACE = 'test-default';

    public function loadsFromInnerOnMissAndStoresInCache(): void
    {
        $experiment = $this->experiment('test-exp');
        $inner = new FakeProvider(['test-exp' => $experiment]);
        $cache = new MemorySimpleCache();

        $provider = $this->provider(inner: $inner, cache: $cache);
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('test-exp');
        Assert::same($result['test-exp']->name, 'test-exp');
        Assert::count($cache->getValues(), 1);
    }

    public function passesConfiguredTtlToCache(): void
    {
        $inner = new FakeProvider([]);
        $cache = new MemorySimpleCache();

        $provider = $this->provider(inner: $inner, cache: $cache, ttl: 120);
        $provider->getExperiments();

        Assert::count($cache->getValues(), 1);
    }

    public function returnsCachedWithoutCallingInnerOnHit(): void
    {
        $cache = new MemorySimpleCache();
        $this->seedCache(
            cache: $cache,
            value: ['exp-a' => $this->experiment('exp-a'), 'exp-b' => $this->experiment('exp-b')],
        );

        $inner = new FakeProvider([]);

        $provider = $this->provider(inner: $inner, cache: $cache);
        $result = $provider->getExperiments();

        Assert::count($result, 2);
        Assert::array($result)->hasKeys('exp-a', 'exp-b');
        Assert::same($inner->callCount, 0);
    }

    public function roundTripServesSecondCallFromCache(): void
    {
        $experiments = ['exp-a' => $this->experiment('exp-a'), 'exp-b' => $this->experiment('exp-b')];
        $inner = new FakeProvider($experiments);

        $provider = $this->provider(inner: $inner, cache: new MemorySimpleCache());

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
        $this->seedCache(cache: $cache, value: ['rt-exp' => $this->experiment('rt-exp')]);

        $inner = new FakeProvider([]);

        $provider = $this->provider(inner: $inner, cache: $cache);
        $provider->clear();

        Assert::same($cache->getValues(), []);
    }

    public function clearForcesReloadFromInner(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = new FakeProvider(['rt-exp' => $experiment]);

        $provider = $this->provider(inner: $inner, cache: new MemorySimpleCache());

        $provider->getExperiments();
        $provider->clear();
        $provider->getExperiments();

        Assert::same($inner->callCount, 2);
    }

    public function fallsBackToInnerWhenCacheReadAndWriteFail(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = new FakeProvider(['rt-exp' => $experiment]);

        $provider = $this->provider(inner: $inner, cache: new ThrowingCache());
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
        Assert::same($result['rt-exp']->name, 'rt-exp');
    }

    public function fallsBackToInnerWhenCacheBackendIsDown(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = new FakeProvider(['rt-exp' => $experiment]);

        $provider = $this->provider(inner: $inner, cache: new BrokenCache());
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
        Assert::same($result['rt-exp']->name, 'rt-exp');
    }

    public function clearIsNonFatalWhenCacheBackendIsDown(): void
    {
        $inner = new FakeProvider([]);

        $provider = $this->provider(inner: $inner, cache: new BrokenCache());
        $provider->clear();

        Assert::same($inner->callCount, 0);
    }

    public function ignoresCorruptedNonArrayCacheValue(): void
    {
        $cache = new MemorySimpleCache();
        $this->seedCache(cache: $cache, value: 'corrupted');

        $experiment = $this->experiment('rt-exp');
        $inner = new FakeProvider(['rt-exp' => $experiment]);

        $provider = $this->provider(inner: $inner, cache: $cache);
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
    }

    public function clearIsNonFatalWhenCacheThrows(): void
    {
        $inner = new FakeProvider([]);

        $provider = $this->provider(inner: $inner, cache: new ThrowingCache());
        $provider->clear();

        Assert::same($inner->callCount, 0);
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function poisonedRegistryProvider(): iterable
    {
        yield 'scalar value' => [['exp' => 'not-an-experiment']];
        yield 'numeric key' => [[0 => self::staticExperiment('exp')]];
        yield 'key does not match experiment name' => [['other' => self::staticExperiment('exp')]];
        yield 'mixed valid and invalid entries' => [[
            'valid' => self::staticExperiment('valid'),
            'invalid' => null,
        ]];
    }

    /**
     * @param array<array-key, mixed> $poisoned
     */
    #[\Testo\Data\DataProvider('poisonedRegistryProvider')]
    public function poisonedArrayFallsBackToInnerAndIsReplaced(array $poisoned): void
    {
        $cache = new MemorySimpleCache();
        $this->seedCache(cache: $cache, value: $poisoned);
        $experiment = $this->experiment('fresh');
        $inner = new FakeProvider(['fresh' => $experiment]);
        $provider = $this->provider(inner: $inner, cache: $cache);

        $result = $provider->getExperiments();
        $second = $provider->getExperiments();

        Assert::same($result, ['fresh' => $experiment]);
        Assert::array($second)->hasKeys('fresh');
        Assert::same($inner->callCount, 1);
    }

    public function namespacesIsolateProvidersSharingOneCache(): void
    {
        $cache = new MemorySimpleCache();
        $firstInner = new FakeProvider(['first' => $this->experiment('first')]);
        $secondInner = new FakeProvider(['second' => $this->experiment('second')]);
        $first = new CachedExperimentProvider(
            inner: $firstInner,
            cache: $cache,
            namespace: 'tenant-a',
        );
        $second = new CachedExperimentProvider(
            inner: $secondInner,
            cache: $cache,
            namespace: 'tenant-b',
        );

        Assert::array($first->getExperiments())->hasKeys('first');
        Assert::array($second->getExperiments())->hasKeys('second');
        Assert::array($first->getExperiments())->hasKeys('first');
        Assert::array($second->getExperiments())->hasKeys('second');
        Assert::same($firstInner->callCount, 1);
        Assert::same($secondInner->callCount, 1);
        Assert::count($cache->getValues(), 2);
    }

    public function clearRemovesOnlyItsNamespace(): void
    {
        $cache = new MemorySimpleCache();
        $first = new CachedExperimentProvider(
            inner: new FakeProvider(['first' => $this->experiment('first')]),
            cache: $cache,
            namespace: 'tenant-a',
        );
        $second = new CachedExperimentProvider(
            inner: new FakeProvider(['second' => $this->experiment('second')]),
            cache: $cache,
            namespace: 'tenant-b',
        );
        $first->getExperiments();
        $second->getExperiments();

        $first->clear();

        Assert::count($cache->getValues(), 1);
        Assert::array($second->getExperiments())->hasKeys('second');
    }

    public function rejectsEmptyNamespace(): void
    {
        \Testo\Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Cache namespace must not be empty');

        new CachedExperimentProvider(inner: new FakeProvider(), cache: new MemorySimpleCache(), namespace: '');
    }

    private function experiment(string $name): Experiment
    {
        return self::staticExperiment($name);
    }

    private static function staticExperiment(string $name): Experiment
    {
        return new Experiment(
            name: $name,
            enabled: true,
            salt: $name,
            fallbackVariant: 'control',
            variants: ['control' => 50, 'green' => 50],
        );
    }

    private function provider(
        FakeProvider $inner,
        \Psr\SimpleCache\CacheInterface $cache,
        int $ttl = 60,
    ): CachedExperimentProvider {
        return new CachedExperimentProvider(
            inner: $inner,
            cache: $cache,
            ttl: $ttl,
            namespace: self::DEFAULT_NAMESPACE,
        );
    }

    private function seedCache(MemorySimpleCache $cache, mixed $value): void
    {
        $cache->set(
            key: 'rasuvaeff.ab-testing.experiments.' . hash('sha256', self::DEFAULT_NAMESPACE),
            value: $value,
        );
    }
}
