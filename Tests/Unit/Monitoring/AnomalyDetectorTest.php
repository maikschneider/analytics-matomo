<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Monitoring;

use MaikSchneider\AnalyticsMatomo\Monitoring\AnomalyDetector;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnomalyDetectorTest extends UnitTestCase
{
    /** @param list<string> $metrics */
    #[Test]
    #[\PHPUnit\Framework\Attributes\TestWith([140, 0.5, ['visits']])]
    #[\PHPUnit\Framework\Attributes\TestWith([60, 0.5, ['visits']])]
    #[\PHPUnit\Framework\Attributes\TestWith([100, 0.7, ['bounceRate']])]
    #[\PHPUnit\Framework\Attributes\TestWith([100, 0.3, ['bounceRate']])]
    #[\PHPUnit\Framework\Attributes\TestWith([139, 0.69, []])]
    public function thresholdsIncludeTheExactBoundary(int $visits, float $bounce, array $metrics): void
    {
        $series = $this->series();
        $series['2026-11-07'] = ['nb_visits' => $visits, 'bounce_rate' => $bounce];
        self::assertSame($metrics, array_column(AnomalyDetector::detect($series), 'metric'));
    }

    #[Test]
    public function aHolidayDoesNotDistortTheSameWeekdayMedian(): void
    {
        $series = $this->series();
        $series['2026-10-17']['nb_visits'] = 0;
        $alerts = AnomalyDetector::detect($series);
        self::assertSame('visits', $alerts[0]['metric']);
        self::assertSame(100.0, $alerts[0]['baseline']);
        self::assertSame(-50.0, $alerts[0]['change']);
        self::assertSame('2026-11-07', $alerts[0]['date']);
    }

    #[Test]
    public function lowTrafficNeverProducesAnAlert(): void
    {
        $series = array_map(static fn (array $row): array => ['nb_visits' => 10, 'bounce_rate' => 0.3], $this->series());
        $series['2026-11-07'] = ['nb_visits' => 0, 'bounce_rate' => 1.0];
        self::assertSame([], AnomalyDetector::detect($series));
    }

    #[Test]
    public function missingBaselineDaysAreNotZero(): void
    {
        $series = $this->series();
        unset($series['2026-10-10']);
        self::assertSame([], AnomalyDetector::detect($series));
    }

    #[Test]
    public function bounceRateIsIgnoredOnSmallCurrentSamples(): void
    {
        $series = $this->series();
        $series['2026-11-07'] = ['nb_visits' => 5, 'bounce_rate' => 1.0];
        self::assertSame(['visits'], array_column(AnomalyDetector::detect($series), 'metric'));
    }

    #[Test]
    public function aBounceJumpWithEnoughVisitsIsFlagged(): void
    {
        $series = $this->series();
        $series['2026-11-07'] = ['nb_visits' => 100, 'bounce_rate' => 0.9];
        self::assertSame(['bounceRate'], array_column(AnomalyDetector::detect($series), 'metric'));
    }

    /** @return array<string, array{nb_visits: int, bounce_rate: float}> */
    private function series(): array
    {
        return [
            '2026-10-10' => ['nb_visits' => 100, 'bounce_rate' => 0.5],
            '2026-10-17' => ['nb_visits' => 100, 'bounce_rate' => 0.5],
            '2026-10-24' => ['nb_visits' => 100, 'bounce_rate' => 0.5],
            '2026-10-31' => ['nb_visits' => 100, 'bounce_rate' => 0.5],
            '2026-11-07' => ['nb_visits' => 50, 'bounce_rate' => 0.5],
        ];
    }
}
