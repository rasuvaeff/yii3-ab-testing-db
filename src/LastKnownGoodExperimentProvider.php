<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\ExperimentProvider;
use Throwable;

/**
 * Serves the last successfully read experiment set when the source fails.
 *
 * **Opt-in, and never silent.** A database outage otherwise takes the whole
 * application down with it, because the registry is built on every request. But
 * serving stale definitions is a trade, not a free win: a kill switch flipped
 * during the outage will not take effect, so every fallback is reported through
 * the injected logger at `error` level. A deployment that cannot see those logs
 * should not enable this decorator.
 *
 * The first read has nothing to fall back to and rethrows — starting up against
 * a broken database must fail loudly rather than serve an empty experiment set,
 * which would look like "no experiments configured" and silently send every
 * visitor to their fallback variant.
 *
 * @api
 */
final class LastKnownGoodExperimentProvider implements ExperimentProvider
{
    /** @var array<string, Experiment>|null */
    private ?array $lastKnownGood = null;

    public function __construct(
        private readonly ExperimentProvider $provider,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, Experiment>
     */
    #[\Override]
    public function getExperiments(): array
    {
        try {
            $experiments = $this->provider->getExperiments();
        } catch (Throwable $e) {
            if ($this->lastKnownGood === null) {
                throw $e;
            }

            $this->logger->error(
                'Serving last known good A/B experiments: the source is unavailable',
                [
                    'event' => 'experiments_stale',
                    'experiments' => \count($this->lastKnownGood),
                    'exception' => $e,
                ],
            );

            return $this->lastKnownGood;
        }

        $this->lastKnownGood = $experiments;

        return $experiments;
    }

    /**
     * Whether the last read fell back to the cached set. Expose it through a
     * health check so a stale registry is visible without reading logs.
     */
    public function hasLastKnownGood(): bool
    {
        return $this->lastKnownGood !== null;
    }
}
