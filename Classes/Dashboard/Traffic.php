<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

/**
 * Visits per day; all lists are indexed like $dates.
 */
final readonly class Traffic
{
    /**
     * @param list<string> $dates Y-m-d, oldest first
     * @param list<int> $visits
     * @param list<int> $visitors
     * @param list<int> $newVisits
     * @param list<int> $returningVisits
     */
    public function __construct(
        public array $dates,
        public array $visits,
        public array $visitors,
        public array $newVisits,
        public array $returningVisits,
    ) {
    }

    public function totalVisits(): int
    {
        return array_sum($this->visits);
    }
}
