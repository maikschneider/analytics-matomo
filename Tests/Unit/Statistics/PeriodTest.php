<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PeriodTest extends UnitTestCase
{
    #[Test]
    public function lastDaysEndsTodayAndPreviousEndsTheDayBefore(): void
    {
        $period = Period::lastDays(7, new \DateTimeImmutable('2026-11-06 14:30:00'));

        self::assertSame('2026-10-31,2026-11-06', $period->toMatomoDate());
        self::assertSame('2026-10-24,2026-10-30', $period->previous()->toMatomoDate());
        self::assertSame(7, $period->days);
    }

    #[Test]
    public function datesListsEveryDayOldestFirst(): void
    {
        $dates = Period::lastDays(14, new \DateTimeImmutable('2026-03-30'))->dates();

        self::assertCount(14, $dates);
        self::assertSame('2026-03-17', $dates[0]);
        self::assertSame('2026-03-30', $dates[13]);
    }

    #[Test]
    public function unknownDaysFallBackToTheFirstPeriod(): void
    {
        self::assertSame(30, Period::normalizeDays(30));
        self::assertSame(7, Period::normalizeDays(365));
        self::assertSame(7, Period::lastDays(0, new \DateTimeImmutable('2026-11-06'))->days);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function presets(): iterable
    {
        yield '90 days' => ['90', '2026-08-09,2026-11-06', 90];
        yield 'this month' => ['thisMonth', '2026-11-01,2026-11-06', 6];
        yield 'last month' => ['lastMonth', '2026-10-01,2026-10-31', 31];
        yield 'this year' => ['thisYear', '2026-01-01,2026-11-06', 310];
        yield 'unknown preset' => ['last5years', '2026-10-31,2026-11-06', 7];
    }

    #[Test]
    #[DataProvider('presets')]
    public function fromPresetResolvesTheDaysOfThePreset(string $preset, string $expectedDate, int $expectedDays): void
    {
        $period = Period::fromPreset($preset, new \DateTimeImmutable('2026-11-06 14:30:00'));

        self::assertSame($expectedDate, $period->toMatomoDate());
        self::assertSame($expectedDays, $period->days);
    }

    #[Test]
    public function previousOfAMonthHasTheSameNumberOfDays(): void
    {
        $period = Period::fromPreset('lastMonth', new \DateTimeImmutable('2026-11-06'));

        self::assertSame('2026-08-31,2026-09-30', $period->previous()->toMatomoDate());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function customRanges(): iterable
    {
        yield 'range' => ['2026-02-01', '2026-02-28', '2026-02-01,2026-02-28'];
        yield 'reversed' => ['2026-02-28', '2026-02-01', '2026-02-01,2026-02-28'];
        yield 'single day' => ['2026-05-04', '2026-05-04', '2026-05-04,2026-05-04'];
        yield 'ends in the future' => ['2026-11-01', '2026-12-24', '2026-11-01,2026-11-06'];
        yield 'invalid date' => ['yesterday', '2026-02-30', '2026-10-31,2026-11-06'];
        yield 'empty' => ['', '', '2026-10-31,2026-11-06'];
    }

    #[Test]
    #[DataProvider('customRanges')]
    public function customRangesAreValidDaysUntilToday(string $from, string $to, string $expectedDate): void
    {
        self::assertSame($expectedDate, Period::fromPreset(Period::CUSTOM, new \DateTimeImmutable('2026-11-06'), $from, $to)->toMatomoDate());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function granularities(): iterable
    {
        yield 'two months are days' => ['2026-01-01', '2026-03-03', 'day'];
        yield 'more than two months are weeks' => ['2026-01-01', '2026-03-04', 'week'];
        yield 'a year is weeks' => ['2025-11-07', '2026-11-06', 'week'];
        yield 'more than a year is months' => ['2025-11-05', '2026-11-06', 'month'];
    }

    #[Test]
    #[DataProvider('granularities')]
    public function granularityOfTheChartDependsOnTheLength(string $from, string $to, string $expected): void
    {
        self::assertSame($expected, Period::fromPreset(Period::CUSTOM, new \DateTimeImmutable('2026-11-06'), $from, $to)->granularity());
    }
}
