<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Sites the dashboard widgets may show: the user has the site statistics
 * permission, the site has a Matomo connection and the user may see its root
 * page. The chosen site and period are stored in the user settings.
 */
#[Autoconfigure(public: true)]
final readonly class DashboardSelectionResolver
{
    public function __construct(
        private SiteFinder $siteFinder,
        private MatomoConfigurationResolver $configurationResolver,
        private Context $context,
    ) {
    }

    /**
     * @return array<string, Site>
     */
    public function getSites(BackendUserAuthentication $backendUser): array
    {
        if (!StatisticsPermission::isGranted($backendUser, StatisticsPermission::SITE)) {
            return [];
        }
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $identifier => $site) {
            if ($this->configurationResolver->resolve($site) !== null
                && BackendUtility::readPageAccess($site->getRootPageId(), $backendUser->getPagePermsClause(Permission::PAGE_SHOW)) !== false
            ) {
                $sites[$identifier] = $site;
            }
        }
        return $sites;
    }

    public function resolve(BackendUserAuthentication $backendUser): ?DashboardSelection
    {
        $sites = $this->getSites($backendUser);
        if ($sites === []) {
            return null;
        }
        $stored = $backendUser->uc['analytics_matomo']['dashboard'] ?? [];
        $site = $sites[(string)($stored['site'] ?? '')] ?? reset($sites);
        $configuration = $this->configurationResolver->resolve($site);
        if ($configuration === null) {
            return null;
        }
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');

        return new DashboardSelection($site, $configuration, Period::lastDays((int)($stored['days'] ?? 0), $now), $sites);
    }

    public function save(BackendUserAuthentication $backendUser, string $siteIdentifier, int $days): bool
    {
        if (!isset($this->getSites($backendUser)[$siteIdentifier])) {
            return false;
        }
        $backendUser->uc['analytics_matomo']['dashboard'] = [
            'site' => $siteIdentifier,
            'days' => Period::normalizeDays($days),
        ];
        $backendUser->writeUC();
        return true;
    }
}
