<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(CachedExperimentProvider::class)]
final class CachedExperimentProviderTest
{
    private const string DEFAULT_NAMESPACE = 'test-default';

    public function loadsFromInnerOnMissAndStoresInCache(): void
    {
        $experiment = $this->experiment('test-exp');
        $inner = $this->inner(['test-exp' => $experiment]);
        $cache = new MemorySimpleCache();

        $provider = $this->provider(inner: $inner, cache: $cache);
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('test-exp');
        Assert::same($result['test-exp']->name, 'test-exp');
        Assert::count($cache->getValues(), 1);
    }

    public function passesConfiguredTtlToCache(): void
    {
        $inner = $this->inner([]);
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

        $inner = $this->inner([]);

        $provider = $this->provider(inner: $inner, cache: $cache);
        $result = $provider->getExperiments();

        Assert::count($result, 2);
        Assert::array($result)->hasKeys('exp-a', 'exp-b');
        Understudy::unused($inner);
    }

    public function roundTripServesSecondCallFromCache(): void
    {
        $experiments = ['exp-a' => $this->experiment('exp-a'), 'exp-b' => $this->experiment('exp-b')];
        $inner = $this->inner($experiments);

        $provider = $this->provider(inner: $inner, cache: new MemorySimpleCache());

        $first = $provider->getExperiments();
        $second = $provider->getExperiments();

        Assert::count($first, 2);
        Assert::count($second, 2);
        Assert::array($first)->hasKeys('exp-b');
        Assert::array($second)->hasKeys('exp-b');
        verify(fn() => $inner->getExperiments(), times: 1);
    }

    public function clearRemovesCachedKey(): void
    {
        $cache = new MemorySimpleCache();
        $this->seedCache(cache: $cache, value: ['rt-exp' => $this->experiment('rt-exp')]);

        $inner = $this->inner([]);

        $provider = $this->provider(inner: $inner, cache: $cache);
        $provider->clear();

        Assert::same($cache->getValues(), []);
    }

    public function clearForcesReloadFromInner(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = $this->inner(['rt-exp' => $experiment]);

        $provider = $this->provider(inner: $inner, cache: new MemorySimpleCache());

        $provider->getExperiments();
        $provider->clear();
        $provider->getExperiments();

        verify(fn() => $inner->getExperiments(), times: 2);
    }

    public function fallsBackToInnerWhenCacheReadAndWriteFail(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = $this->inner(['rt-exp' => $experiment]);

        $provider = $this->provider(inner: $inner, cache: $this->throwingCache(new InvalidCacheKeyException('boom')));
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
        Assert::same($result['rt-exp']->name, 'rt-exp');
    }

    public function fallsBackToInnerWhenCacheBackendIsDown(): void
    {
        $experiment = $this->experiment('rt-exp');
        $inner = $this->inner(['rt-exp' => $experiment]);

        $provider = $this->provider(inner: $inner, cache: $this->throwingCache(new \RuntimeException('connection refused')));
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
        Assert::same($result['rt-exp']->name, 'rt-exp');
    }

    public function clearIsNonFatalWhenCacheBackendIsDown(): void
    {
        $inner = $this->inner([]);

        $provider = $this->provider(inner: $inner, cache: $this->throwingCache(new \RuntimeException('connection refused')));
        $provider->clear();

        Understudy::unused($inner);
    }

    public function ignoresCorruptedNonArrayCacheValue(): void
    {
        $cache = new MemorySimpleCache();
        $this->seedCache(cache: $cache, value: 'corrupted');

        $experiment = $this->experiment('rt-exp');
        $inner = $this->inner(['rt-exp' => $experiment]);

        $provider = $this->provider(inner: $inner, cache: $cache);
        $result = $provider->getExperiments();

        Assert::array($result)->hasKeys('rt-exp');
    }

    public function clearIsNonFatalWhenCacheThrows(): void
    {
        $inner = $this->inner([]);

        $provider = $this->provider(inner: $inner, cache: $this->throwingCache(new InvalidCacheKeyException('boom')));
        $provider->clear();

        Understudy::unused($inner);
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
        $inner = $this->inner(['fresh' => $experiment]);
        $provider = $this->provider(inner: $inner, cache: $cache);

        $result = $provider->getExperiments();
        $second = $provider->getExperiments();

        Assert::same($result, ['fresh' => $experiment]);
        Assert::array($second)->hasKeys('fresh');
        verify(fn() => $inner->getExperiments(), times: 1);
    }

    public function namespacesIsolateProvidersSharingOneCache(): void
    {
        $cache = new MemorySimpleCache();
        $firstInner = $this->inner(['first' => $this->experiment('first')]);
        $secondInner = $this->inner(['second' => $this->experiment('second')]);
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
        verify(fn() => $firstInner->getExperiments(), times: 1);
        verify(fn() => $secondInner->getExperiments(), times: 1);
        Assert::count($cache->getValues(), 2);
    }

    public function clearRemovesOnlyItsNamespace(): void
    {
        $cache = new MemorySimpleCache();
        $first = new CachedExperimentProvider(
            inner: $this->inner(['first' => $this->experiment('first')]),
            cache: $cache,
            namespace: 'tenant-a',
        );
        $second = new CachedExperimentProvider(
            inner: $this->inner(['second' => $this->experiment('second')]),
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

        new CachedExperimentProvider(inner: $this->inner([]), cache: new MemorySimpleCache(), namespace: '');
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

    /**
     * @param array<string, Experiment> $experiments
     */
    private function inner(array $experiments): ExperimentProvider
    {
        $provider = Understudy::for(ExperimentProvider::class);
        when(fn() => $provider->getExperiments())->returns($experiments);

        return $provider;
    }

    /**
     * Every operation the provider performs on the cache throws, mimicking a
     * down backend (broken Redis connection) or an invalid-key PSR-16 failure.
     */
    private function throwingCache(\Throwable $exception): CacheInterface
    {
        $cache = Understudy::for(CacheInterface::class);
        when(fn() => $cache->get(Arg::any()))->throws($exception);
        when(fn() => $cache->set(Arg::any(), Arg::any()))->throws($exception);
        when(fn() => $cache->delete(Arg::any()))->throws($exception);

        return $cache;
    }

    private function provider(
        ExperimentProvider $inner,
        CacheInterface $cache,
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
