<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;

/**
 * Page-level numbers of Matomo Actions.getPageUrl (requested with format_metrics=0).
 */
final readonly class PageMetrics
{
    public const COUNTS = ['nb_hits', 'nb_visits', 'sum_time_spent', 'entry_nb_visits', 'entry_bounce_count', 'exit_nb_visits'];

    public function __construct(
        public int $views = 0,
        public float $bounceRate = 0.0,
        public int $averageTimeOnPage = 0,
        public float $exitRate = 0.0,
    ) {
    }

    /**
     * Sums the counts of the given days and derives the rates from the sums, as Matomo
     * does for a range. Large instances build range archives slowly on request, while
     * day archives usually exist already.
     *
     * @param mixed $days decoded result of Actions.getPageUrl with period=day and a date range:
     *                    rows by date (Y-m-d), an empty list for a day without data
     * @param list<string> $dates days to sum
     * @throws MatomoApiException
     */
    public static function fromDays(mixed $days, array $dates): self
    {
        if (!is_array($days) || ($days !== [] && array_is_list($days))) {
            throw self::unexpected($days);
        }
        foreach ($days as $rows) {
            if (!is_array($rows) || !array_is_list($rows) || array_filter($rows, is_array(...)) !== $rows) {
                throw self::unexpected($days);
            }
        }
        $sums = array_fill_keys(self::COUNTS, 0);
        foreach ($dates as $date) {
            foreach ($days[$date] ?? [] as $row) {
                foreach (self::COUNTS as $column) {
                    $sums[$column] += (int)($row[$column] ?? 0);
                }
            }
        }
        return self::fromCounts($sums);
    }

    /**
     * @param array<string, int> $counts summed counts by column of COUNTS
     */
    public static function fromCounts(array $counts): self
    {
        $counts += array_fill_keys(self::COUNTS, 0);
        return new self(
            views: $counts['nb_hits'],
            bounceRate: $counts['entry_nb_visits'] > 0 ? $counts['entry_bounce_count'] / $counts['entry_nb_visits'] : 0.0,
            averageTimeOnPage: $counts['nb_hits'] > 0 ? (int)round($counts['sum_time_spent'] / $counts['nb_hits']) : 0,
            exitRate: $counts['nb_visits'] > 0 ? $counts['exit_nb_visits'] / $counts['nb_visits'] : 0.0,
        );
    }

    /**
     * A row of Matomo's page reports (format_metrics=0) from summed counts:
     * the counts and the metrics derived from them, rates rounded like Matomo's.
     *
     * @param array<string, int> $counts by column of COUNTS
     * @return array<string, int|float>
     */
    public static function row(array $counts): array
    {
        $metrics = self::fromCounts($counts);
        return array_intersect_key($counts, array_flip(self::COUNTS)) + [
            'bounce_rate' => round($metrics->bounceRate, 2),
            'avg_time_on_page' => $metrics->averageTimeOnPage,
            'exit_rate' => round($metrics->exitRate, 2),
        ];
    }

    private static function unexpected(mixed $result): MatomoApiException
    {
        return new MatomoApiException('Matomo answered with an unexpected result: ' . mb_substr((string)json_encode($result), 0, 200), 1793960006);
    }
}
