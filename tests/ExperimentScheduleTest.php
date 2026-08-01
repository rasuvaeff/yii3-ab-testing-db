<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Rasuvaeff\Yii3AbTestingDb\ExperimentSchedule;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ExperimentSchedule::class)]
final class ExperimentScheduleTest
{
    public function anOpenScheduleIsAlwaysActive(): void
    {
        $schedule = ExperimentSchedule::open();

        Assert::null($schedule->startsAt);
        Assert::null($schedule->endsAt);
        Assert::true($schedule->isActiveAt(self::at('2020-01-01 00:00:00')));
        Assert::true($schedule->isActiveAt(self::at('2100-01-01 00:00:00')));
    }

    /**
     * @param array{0: string|null, 1: string|null} $window
     */
    #[DataProvider('windowProvider')]
    public function reportsWhetherTheMomentFallsInTheWindow(array $window, string $moment, bool $expected): void
    {
        $schedule = new ExperimentSchedule(
            startsAt: $window[0] === null ? null : self::at($window[0]),
            endsAt: $window[1] === null ? null : self::at($window[1]),
        );

        Assert::same($schedule->isActiveAt(self::at($moment)), $expected);
    }

    public static function windowProvider(): iterable
    {
        yield 'before the start' => [['2026-08-01 10:00:00', null], '2026-08-01 09:59:59', false];
        yield 'exactly at the start is inside' => [['2026-08-01 10:00:00', null], '2026-08-01 10:00:00', true];
        yield 'after an open end' => [['2026-08-01 10:00:00', null], '2030-01-01 00:00:00', true];
        yield 'before an open start' => [[null, '2026-08-01 10:00:00'], '2020-01-01 00:00:00', true];
        // exclusive end, so two back-to-back schedules never both claim it
        yield 'exactly at the end is outside' => [[null, '2026-08-01 10:00:00'], '2026-08-01 10:00:00', false];
        yield 'inside a closed window' => [['2026-08-01 10:00:00', '2026-08-02 10:00:00'], '2026-08-01 20:00:00', true];
        yield 'after a closed window' => [['2026-08-01 10:00:00', '2026-08-02 10:00:00'], '2026-08-03 00:00:00', false];
    }

    public function rejectsAWindowThatEndsBeforeItStarts(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ExperimentSchedule(
            startsAt: self::at('2026-08-02 10:00:00'),
            endsAt: self::at('2026-08-01 10:00:00'),
        );
    }

    public function rejectsAZeroLengthWindow(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ExperimentSchedule(
            startsAt: self::at('2026-08-01 10:00:00'),
            endsAt: self::at('2026-08-01 10:00:00'),
        );
    }

    private static function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
