<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Statistics\Period;
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
}
