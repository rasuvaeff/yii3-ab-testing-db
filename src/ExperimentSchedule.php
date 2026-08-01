<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * When an experiment is meant to run, as an operational fact.
 *
 * Deliberately **not** part of the runtime projection: `isActiveAt()` is a
 * planning question, and answering it during assignment would make the served
 * variant depend on wall-clock time in a way the deterministic hash never
 * intends. The provider still reads the `enabled` flag; a scheduler or an
 * operator flips that flag based on this.
 *
 * Both bounds are optional: an open start means "already begun", an open end
 * means "runs until stopped".
 *
 * @api
 */
final readonly class ExperimentSchedule
{
    public function __construct(
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
    ) {
        if ($startsAt instanceof DateTimeImmutable && $endsAt instanceof DateTimeImmutable && $endsAt <= $startsAt) {
            throw new InvalidArgumentException(
                sprintf(
                    'Experiment must end after it starts, got %s to %s',
                    $startsAt->format(DateTimeImmutable::ATOM),
                    $endsAt->format(DateTimeImmutable::ATOM),
                ),
            );
        }
    }

    public static function open(): self
    {
        return new self();
    }

    /**
     * Whether the schedule covers the given instant. The bounds are inclusive
     * at the start and exclusive at the end, so two back-to-back schedules
     * never both claim the same moment.
     */
    public function isActiveAt(DateTimeImmutable $moment): bool
    {
        if ($this->startsAt instanceof DateTimeImmutable && $moment < $this->startsAt) {
            return false;
        }

        return !$this->endsAt instanceof DateTimeImmutable || $moment < $this->endsAt;
    }
}
