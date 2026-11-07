<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

/**
 * A row of a Matomo URL report and the page it belongs to.
 */
final readonly class PageUrlRow
{
    /**
     * @param array<string, mixed> $row
     */
    public function __construct(
        public array $row,
        public PageUrlMatch $match,
    ) {
    }
}
