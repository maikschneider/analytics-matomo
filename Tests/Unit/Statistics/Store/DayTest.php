<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Statistics\Store\Day;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DayTest extends UnitTestCase
{
    #[Test]
    public function convertsBetweenIntegerAndDateInTheGivenTimezone(): void
    {
        $date = Day::toDate(20261123, new \DateTimeZone('Europe/Berlin'));

        self::assertSame('2026-11-23 00:00:00 Europe/Berlin', $date->format('Y-m-d H:i:s e'));
        self::assertSame(20261123, Day::fromDate($date));
        self::assertSame(20261231, Day::fromDate(new \DateTimeImmutable('2026-12-31 23:59:59')));
    }

    #[Test]
    public function rangeListsEveryDayNewestFirst(): void
    {
        self::assertSame([20260302, 20260301, 20260228, 20260227], Day::range(20260227, 20260302));
        self::assertSame([20261123], Day::range(20261123, 20261123));
        self::assertSame([], Day::range(20261124, 20261123));
    }

    #[Test]
    public function rejectsWhatIsNotADay(): void
    {
        $this->expectExceptionCode(1794500002);

        Day::toDate(20260231, new \DateTimeZone('UTC'));
    }
}
