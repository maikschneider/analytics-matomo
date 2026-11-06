<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

final readonly class TopPage
{
    /**
     * @param float $share share of all page views of the site, 0..1
     */
    public function __construct(
        public string $url,
        public int $views,
        public int $previousViews,
        public float $share,
    ) {
    }
}
