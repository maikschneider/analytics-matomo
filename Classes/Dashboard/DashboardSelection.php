<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The site and period a backend user looks at, shared by the dashboard widgets and the backend module.
 */
final readonly class DashboardSelection
{
    /**
     * @param array<string, Site> $sites all sites the user may choose from, by identifier
     * @param bool $compare compare with the previous period in the module
     */
    public function __construct(
        public Site $site,
        public MatomoConfiguration $configuration,
        public Period $period,
        public array $sites,
        public bool $compare = false,
    ) {
    }
}
