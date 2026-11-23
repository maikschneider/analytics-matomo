<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;

/**
 * Page-level numbers of Matomo Actions.getPageUrl (requested with format_metrics=0).
 */
final readonly class PageMetrics
{
    private const COUNTS = ['nb_hits', 'nb_visits', 'sum_time_spent', 'entry_nb_visits', 'entry_bounce_count', 'exit_nb_visits'];

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

        return new self(
            views: $sums['nb_hits'],
            bounceRate: $sums['entry_nb_visits'] > 0 ? $sums['entry_bounce_count'] / $sums['entry_nb_visits'] : 0.0,
            averageTimeOnPage: $sums['nb_hits'] > 0 ? (int)round($sums['sum_time_spent'] / $sums['nb_hits']) : 0,
            exitRate: $sums['nb_visits'] > 0 ? $sums['exit_nb_visits'] / $sums['nb_visits'] : 0.0,
        );
    }

    private static function unexpected(mixed $result): MatomoApiException
    {
        return new MatomoApiException('Matomo answered with an unexpected result: ' . mb_substr((string)json_encode($result), 0, 200), 1793960006);
    }
}
