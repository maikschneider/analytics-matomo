<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Module;

use MaikSchneider\AnalyticsMatomo\Module\EvolutionChartFactory;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EvolutionChartFactoryTest extends UnitTestCase
{
    #[Test]
    #[DataProvider('periods')]
    public function buildsCalendarBucketsAndAlignsPreviousByPosition(string $from, string $to, string $granularity, string $first): void
    {
        $period = Period::fromPreset('custom', new \DateTimeImmutable('2026-11-02'), $from, $to);
        $previousFirst = match ($granularity) {
            'week' => $period->previous()->from->modify('monday this week')->format('Y-m-d'),
            'month' => $period->previous()->from->modify('first day of this month')->format('Y-m-d'),
            default => $period->previous()->from->format('Y-m-d'),
        };
        $chart = (new EvolutionChartFactory())->create($period, [$first => ['nb_visits' => 12]], [$previousFirst => ['nb_visits' => 7]]);

        self::assertSame($granularity, $chart['granularity']);
        self::assertSame($first, $chart['labels'][0]);
        self::assertSame(12, $chart['metrics']['visits']['current'][0]);
        self::assertArrayHasKey('previous', $chart['metrics']['visits']);
        $previousSeries = $chart['metrics']['visits']['previous'] ?? [];
        self::assertSame(7, $previousSeries[0] ?? null);
        self::assertSame($previousFirst, $chart['previousLabels'][0]);
        self::assertCount(count($chart['labels']), $previousSeries);
        self::assertNull($chart['metrics']['visits']['current'][1]);
        self::assertNull($chart['metrics']['visitors']['current'][0]);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function periods(): iterable
    {
        yield 'days' => ['2026-10-27', '2026-11-02', 'day', '2026-10-27'];
        yield 'weeks' => ['2026-08-01', '2026-11-02', 'week', '2026-07-27'];
        yield 'months' => ['2025-01-15', '2026-11-02', 'month', '2025-01-01'];
    }

    #[Test]
    public function noComparisonOmitsPreviousSeriesAndPreservesZero(): void
    {
        $period = Period::lastDays(7, new \DateTimeImmutable('2026-11-02'));
        $chart = (new EvolutionChartFactory())->create($period, ['2026-10-27' => ['nb_visits' => 0]]);
        self::assertSame(0, $chart['metrics']['visits']['current'][0]);
        self::assertArrayNotHasKey('previous', $chart['metrics']['visits']);
    }

    #[Test]
    public function seriesOfWeeksAndMonthsAreKeyedByTheirFirstDay(): void
    {
        $series = EvolutionChartFactory::byBucketStart(['2026-07-27,2026-08-02' => ['nb_conversions' => 4], '2026-08-03,2026-08-09' => ['nb_conversions' => 2], '2026-08-10' => []]);

        self::assertSame(['2026-07-27' => ['nb_conversions' => 4], '2026-08-03' => ['nb_conversions' => 2], '2026-08-10' => []], $series);
    }
}
