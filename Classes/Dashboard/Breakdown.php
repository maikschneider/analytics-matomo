<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

/**
 * Visits split by one dimension (channel, device type, ...), largest first.
 */
final readonly class Breakdown
{
    public const CHANNEL = 'Referrers.getReferrerType';
    public const DEVICES = 'DevicesDetection.getType';
    public const BROWSERS = 'DevicesDetection.getBrowsers';
    public const COUNTRIES = 'UserCountry.getCountry';
    public const METHODS = [self::CHANNEL, self::DEVICES, self::BROWSERS, self::COUNTRIES];

    /**
     * @param list<BreakdownRow> $rows
     */
    public function __construct(
        public int $total,
        public array $rows,
    ) {
    }
}
