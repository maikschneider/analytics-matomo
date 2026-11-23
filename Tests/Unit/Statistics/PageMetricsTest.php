<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageMetricsTest extends UnitTestCase
{
    #[Test]
    public function fromDaysDerivesRatesFromSummedCounts(): void
    {
        $days = [
            // Matomo answers some counts of day archives as strings
            '2026-11-01' => [['nb_hits' => 4, 'nb_visits' => 4, 'sum_time_spent' => 33, 'entry_nb_visits' => '4', 'entry_bounce_count' => '4', 'exit_nb_visits' => '4', 'bounce_rate' => 1, 'exit_rate' => 1]],
            '2026-11-02' => [['nb_hits' => 8, 'nb_visits' => 5, 'sum_time_spent' => 266, 'entry_nb_visits' => '5', 'entry_bounce_count' => '3', 'exit_nb_visits' => '5', 'bounce_rate' => 0.6, 'exit_rate' => 1]],
            '2026-11-03' => [],
        ];

        // 7 of 9 entrances bounced, not the 0.8 average of the daily rates
        self::assertEquals(new PageMetrics(12, 7 / 9, 25, 1.0), PageMetrics::fromDays($days, ['2026-11-01', '2026-11-02', '2026-11-03']));
        self::assertEquals(new PageMetrics(8, 0.6, 33, 1.0), PageMetrics::fromDays($days, ['2026-11-02']));
    }

    #[Test]
    public function fromDaysTreatsDaysWithoutDataAsZero(): void
    {
        self::assertEquals(new PageMetrics(), PageMetrics::fromDays(['2026-11-01' => []], ['2026-11-01', '2026-11-02']));
        self::assertEquals(new PageMetrics(), PageMetrics::fromDays([], ['2026-11-01']));
    }

    #[Test]
    public function fromDaysDoesNotDivideByZero(): void
    {
        // Views without visits or entrances can only come from a broken archive, but must not crash
        self::assertEquals(new PageMetrics(3), PageMetrics::fromDays(['2026-11-01' => [['nb_hits' => 3]]], ['2026-11-01']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedResults(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['Maintenance'];
        yield 'error object' => [['result' => 'error', 'message' => 'Archiving failed']];
        yield 'list instead of days' => [[['nb_hits' => 3]]];
        yield 'scalar day' => [['2026-11-01' => 3]];
        yield 'list of scalars' => [['2026-11-01' => [1, 2]]];
    }

    #[Test]
    #[DataProvider('malformedResults')]
    public function fromDaysRejectsMalformedResults(mixed $result): void
    {
        $this->expectException(MatomoApiException::class);
        $this->expectExceptionCode(1793960006);

        PageMetrics::fromDays($result, ['2026-11-01']);
    }
}
