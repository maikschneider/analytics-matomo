<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

final readonly class PageStatistics
{
    /**
     * @param array<string, PageMetrics> $daily keyed by date (Y-m-d), oldest first
     */
    public function __construct(
        public PageMetrics $current,
        public PageMetrics $previous,
        public array $daily,
    ) {
    }
}
