<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The site and period the dashboard widgets show, shared by all widgets of a backend user.
 */
final readonly class DashboardSelection
{
    /**
     * @param array<string, Site> $sites all sites the user may choose from, by identifier
     */
    public function __construct(
        public Site $site,
        public MatomoConfiguration $configuration,
        public Period $period,
        public array $sites,
    ) {
    }
}
