<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\LastKnownGoodExperimentProvider;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Log\SimpleLogger;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(LastKnownGoodExperimentProvider::class)]
final class LastKnownGoodExperimentProviderTest
{
    private SimpleLogger $logger;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->logger = new SimpleLogger();
    }

    public function passesThroughWhenTheSourceWorks(): void
    {
        // more than one experiment on purpose: a decorator that returned only
        // the first would look correct against a single-element set
        $inner = $this->provider([self::makeExperiment('checkout'), self::makeExperiment('pricing')]);

        $experiments = (new LastKnownGoodExperimentProvider($inner, $this->logger))->getExperiments();

        Assert::same(array_keys($experiments), ['checkout', 'pricing']);
        Assert::same($this->logger->getMessages(), []);
    }

    public function servesTheCachedSetWhenTheSourceFails(): void
    {
        $inner = $this->provider([$this->experiment()], failAfter: 1);
        $provider = new LastKnownGoodExperimentProvider($inner, $this->logger);

        $first = $provider->getExperiments();
        $second = $provider->getExperiments();

        Assert::same(array_keys($second), array_keys($first));
    }

    /**
     * Stale definitions mean a kill switch flipped during the outage does not
     * take effect. That must never be silent.
     */
    public function everyFallbackIsReported(): void
    {
        $provider = new LastKnownGoodExperimentProvider(
            $this->provider([$this->experiment()], failAfter: 1),
            $this->logger,
        );

        $provider->getExperiments();
        $provider->getExperiments();
        $provider->getExperiments();

        $messages = $this->logger->getMessages();
        Assert::same(\count($messages), 2);
        Assert::same($messages[0]['level'], 'error');
        Assert::same($messages[0]['context']['event'], 'experiments_stale');
        Assert::same($messages[0]['context']['experiments'], 1);
    }

    /**
     * An empty set would look like "no experiments configured" and send every
     * visitor to their fallback variant, so a cold start against a broken
     * source must fail instead.
     */
    public function theFirstReadHasNothingToFallBackToAndRethrows(): void
    {
        $provider = new LastKnownGoodExperimentProvider(
            $this->provider([], failAfter: 0),
            $this->logger,
        );

        Expect::exception(RuntimeException::class)->withMessage('source is down');

        $provider->getExperiments();
    }

    public function reportsWhetherACachedSetExists(): void
    {
        $provider = new LastKnownGoodExperimentProvider(
            $this->provider([$this->experiment()]),
            $this->logger,
        );

        Assert::false($provider->hasLastKnownGood());
        $provider->getExperiments();
        Assert::true($provider->hasLastKnownGood());
        Assert::false($provider->servedStaleOnLastRead());

        $provider = new LastKnownGoodExperimentProvider(
            $this->provider([$this->experiment()], failAfter: 1),
            $this->logger,
        );
        $provider->getExperiments();
        $provider->getExperiments();

        Assert::true($provider->servedStaleOnLastRead());
    }

    public function aLaterSuccessReplacesTheCachedSet(): void
    {
        $provider = Understudy::for(ExperimentProvider::class);
        when(fn() => $provider->getExperiments())->returns(
            ['checkout' => self::makeExperiment('checkout')],
            ['pricing' => self::makeExperiment('pricing')],
        );

        $decorated = new LastKnownGoodExperimentProvider($provider, $this->logger);

        $decorated->getExperiments();
        $decorated->getExperiments();

        Assert::same(array_keys($decorated->getExperiments()), ['pricing']);
    }

    /**
     * The whole set must be cached, not a slice of it: serving a subset would
     * silently drop experiments during an outage.
     */
    public function cachesEveryExperimentNotJustOne(): void
    {
        $provider = new LastKnownGoodExperimentProvider(
            $this->provider(
                [self::makeExperiment('checkout'), self::makeExperiment('pricing'), self::makeExperiment('banner')],
                failAfter: 1,
            ),
            $this->logger,
        );

        $provider->getExperiments();

        Assert::same(array_keys($provider->getExperiments()), ['checkout', 'pricing', 'banner']);
    }

    public static function makeExperiment(string $name): Experiment
    {
        return new Experiment(
            name: $name,
            enabled: true,
            salt: $name,
            fallbackVariant: 'control',
            variants: ['control' => 50, 'green' => 50],
        );
    }

    private function experiment(): Experiment
    {
        return self::makeExperiment('checkout');
    }

    /**
     * @param list<Experiment> $experiments
     * @param int|null $failAfter Number of successful reads before every later
     *     call throws; null never fails.
     */
    private function provider(array $experiments, ?int $failAfter = null): ExperimentProvider
    {
        $indexed = [];

        foreach ($experiments as $experiment) {
            $indexed[$experiment->name] = $experiment;
        }

        $provider = Understudy::for(ExperimentProvider::class);

        if ($failAfter === null) {
            when(fn() => $provider->getExperiments())->returns($indexed);
        } elseif ($failAfter === 0) {
            when(fn() => $provider->getExperiments())->throws(new RuntimeException('source is down'));
        } else {
            when(fn() => $provider->getExperiments())
                ->returns(...array_fill(0, $failAfter, $indexed))
                ->then()
                ->throws(new RuntimeException('source is down'));
        }

        return $provider;
    }
}
