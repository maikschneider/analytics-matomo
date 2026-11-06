<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

final readonly class SitePerformance
{
    /**
     * @param array<string, SiteMetrics> $daily metrics per day (Y-m-d), oldest first
     */
    public function __construct(
        public SiteMetrics $current,
        public SiteMetrics $previous,
        public array $daily,
    ) {
    }
}
