<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

/**
 * One metric tile of the statistics bar, ready for the template.
 */
final readonly class Tile
{
    /**
     * @param 'positive'|'negative'|null $trendRating whether the change is good or bad news
     */
    public function __construct(
        public string $key,
        public string $icon,
        public string $value,
        public ?string $trend,
        public ?string $trendIcon,
        public ?string $trendRating,
        public string $sparklineLine,
        public string $sparklineArea,
    ) {
    }
}
