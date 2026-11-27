<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

/**
 * What the store holds of a connection for a range of days.
 */
final readonly class Coverage
{
    /**
     * @param bool $hasStore the connection has imported days at all
     * @param bool $isComplete every day of the range is imported
     * @param ?int $oldestDay oldest imported day of the connection (Ymd)
     * @param ?\DateTimeImmutable $asOf import time of the newest imported day of the range
     * @param bool $isStale the range includes today, and today is not imported or was imported too long ago
     * @param int $totalHits page views of the imported days of the range, including Matomo's summary rows
     * @param int $summaryHits page views in Matomo's summary rows of the imported days of the range
     */
    public function __construct(
        public bool $hasStore,
        public bool $isComplete,
        public ?int $oldestDay,
        public ?\DateTimeImmutable $asOf,
        public bool $isStale,
        public int $totalHits,
        public int $summaryHits,
    ) {
    }

    /**
     * Matomo cut the report of at least one day of the range.
     */
    public function isTruncated(): bool
    {
        return $this->summaryHits > 0;
    }
}
