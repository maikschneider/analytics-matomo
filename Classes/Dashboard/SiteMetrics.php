<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

final readonly class SiteMetrics
{
    public function __construct(
        public int $visits = 0,
        public int $pageViews = 0,
        public float $bounceRate = 0.0,
        public int $averageTimeOnSite = 0,
    ) {
    }
}
