<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

/**
 * Matomo's page report of one day, merged by normalized URL.
 */
final readonly class UrlDay
{
    /**
     * @param array<string, UrlDayRow> $rows by URL hash (UrlDayRows::hash())
     * @param int $totalHits page views of the day, including summary rows and rows without URL
     * @param int $summaryHits page views in Matomo's summary rows: more than 0 when Matomo cut the report
     */
    public function __construct(
        public array $rows,
        public int $totalHits,
        public int $summaryHits,
    ) {
    }

    public function isTruncated(): bool
    {
        return $this->summaryHits > 0;
    }
}
