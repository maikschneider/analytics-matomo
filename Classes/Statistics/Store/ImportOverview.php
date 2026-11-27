<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

/**
 * What the store holds of a connection as a whole.
 */
final readonly class ImportOverview
{
    /**
     * @param int $oldestDay oldest imported day (Ymd)
     * @param int $newestDay newest imported day (Ymd)
     * @param int $lastImportedAt time of the latest import of any day
     * @param int $truncatedDays imported days whose report Matomo cut (summary rows)
     */
    public function __construct(
        public int $oldestDay,
        public int $newestDay,
        public int $lastImportedAt,
        public int $truncatedDays,
    ) {
    }
}
