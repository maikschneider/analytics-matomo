<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Statistics\Period;

/**
 * The page view store of a Matomo site that the token may read, and what it
 * holds of the periods asked for (PageViewStore::source()).
 */
final readonly class PageViewSource
{
    /**
     * @param bool $isComplete every day of every period is imported (and the URLs mapped for the site asked for): the store answers instead of Matomo
     * @param array<string, array{from: int, to: int, coverage: Coverage}> $periods days (Ymd) and coverage by period (Period::toMatomoDate())
     * @param int $oldestDay oldest stored day (Ymd)
     * @param int $newestDay newest stored day (Ymd)
     * @param \DateTimeImmutable $lastImport latest import of any day
     */
    public function __construct(
        public int $connection,
        public bool $isComplete,
        public array $periods,
        public int $oldestDay,
        public int $newestDay,
        public \DateTimeImmutable $lastImport,
    ) {
    }

    /**
     * @return array{from: int, to: int, coverage: Coverage}
     */
    public function of(Period $period): array
    {
        return $this->periods[$period->toMatomoDate()] ?? throw new \InvalidArgumentException('The source was not asked for ' . $period->toMatomoDate(), 1794600001);
    }
}
