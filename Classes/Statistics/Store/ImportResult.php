<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

/**
 * What an import run did for one connection.
 */
final readonly class ImportResult
{
    /**
     * @param int $requests Matomo requests sent, failed ones included
     * @param list<int> $importedDays Ymd, newest first
     * @param array<int, string> $failedDays error message by day (Ymd), newest first
     * @param int $remainingDays days to import that were left for a later run, as the request budget ran out
     */
    public function __construct(
        public int $requests,
        public array $importedDays,
        public array $failedDays,
        public int $remainingDays,
    ) {
    }
}
