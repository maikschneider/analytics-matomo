<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;

/**
 * Page-level numbers of one Matomo Actions.getPageUrl row (requested with format_metrics=0).
 */
final readonly class PageMetrics
{
    public function __construct(
        public int $views = 0,
        public float $bounceRate = 0.0,
        public int $averageTimeOnPage = 0,
        public float $exitRate = 0.0,
    ) {
    }

    /**
     * Matomo returns an empty list for a page or day without data.
     *
     * @param mixed $result decoded result of Actions.getPageUrl for one period
     * @throws MatomoApiException
     */
    public static function fromApiResult(mixed $result): self
    {
        if (!is_array($result) || !array_is_list($result) || ($result !== [] && !is_array($result[0]))) {
            throw new MatomoApiException('Matomo answered with an unexpected result: ' . mb_substr((string)json_encode($result), 0, 200), 1793960006);
        }
        $row = $result[0] ?? [];

        return new self(
            views: (int)($row['nb_hits'] ?? 0),
            bounceRate: (float)($row['bounce_rate'] ?? 0),
            averageTimeOnPage: (int)($row['avg_time_on_page'] ?? 0),
            exitRate: (float)($row['exit_rate'] ?? 0),
        );
    }
}
