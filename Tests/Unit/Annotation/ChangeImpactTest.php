<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Annotation;

use MaikSchneider\AnalyticsMatomo\Annotation\ChangeImpact;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ChangeImpactTest extends UnitTestCase
{
    private const VIEWS = [
        '2026-10-27' => 10, '2026-10-28' => 20, '2026-10-29' => 99, '2026-10-30' => 30,
        '2026-10-31' => 40, '2026-11-01' => 50, '2026-11-02' => 1,
    ];

    #[Test]
    public function comparesTheAverageDailyViewsBeforeAndAfterTheChangeDayWithoutToday(): void
    {
        $impact = ChangeImpact::fromDailyViews(self::VIEWS, '2026-10-29', '2026-11-02');

        self::assertNotNull($impact);
        self::assertSame('2026-10-29', $impact->date);
        self::assertSame(15.0, $impact->before);
        self::assertSame(40.0, $impact->after);
        self::assertSame(2, $impact->daysBefore);
        self::assertSame(3, $impact->daysAfter);
        self::assertEqualsWithDelta(1.6667, $impact->change, 0.0001);
    }

    #[Test]
    public function needsAtLeastOneClosedDayOnEachSide(): void
    {
        self::assertNull(ChangeImpact::fromDailyViews(self::VIEWS, '2026-10-27', '2026-11-02'));
        self::assertNull(ChangeImpact::fromDailyViews(self::VIEWS, '2026-11-01', '2026-11-02'));
        self::assertNotNull(ChangeImpact::fromDailyViews(self::VIEWS, '2026-10-31', '2026-11-02'));
    }

    #[Test]
    public function hasNoChangeWithoutViewsBefore(): void
    {
        $impact = ChangeImpact::fromDailyViews(['2026-10-27' => 0, '2026-10-28' => 5, '2026-10-29' => 7], '2026-10-28', '2026-10-30');

        self::assertNotNull($impact);
        self::assertNull($impact->change);
        self::assertSame(7.0, $impact->after);
    }
}
