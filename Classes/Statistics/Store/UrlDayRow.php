<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

/**
 * The counts of one normalized URL on one day, with the URL to show for it.
 */
final readonly class UrlDayRow
{
    /**
     * @param string $url the busiest variant as tracked: with scheme, query and original encoding
     * @param array<string, int> $counts by column of PageMetrics::COUNTS, in that order
     */
    public function __construct(
        public string $url,
        public array $counts,
    ) {
    }
}
