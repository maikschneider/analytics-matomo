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
 * The site, period and compare setting a backend user looks at, shared by the
 * dashboard widgets and the backend module. Offered are the sites with a
 * Matomo connection whose root page the user may see, if the user has the site
 * statistics permission. The choice is stored in the user settings.
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

    /**
     * @param Site|null $site a site chosen elsewhere, e.g. in the page tree, instead of the stored one
     */
    public function resolve(BackendUserAuthentication $backendUser, ?Site $site = null): ?DashboardSelection
    {
        $sites = $this->getSites($backendUser);
        $stored = $this->getStored($backendUser);
        $site = $site === null ? ($sites[$stored['site']] ?? reset($sites)) : ($sites[$site->getIdentifier()] ?? null);
        if (!$site instanceof Site) {
            return null;
        }
        $configuration = $this->configurationResolver->resolve($site);
        if ($configuration === null) {
            return null;
        }

        return new DashboardSelection(
            $site,
            $configuration,
            Period::fromPreset($stored['period'], $this->getToday(), $stored['from'], $stored['to']),
            $sites,
            $stored['compare'],
        );
    }

    /**
     * Stores the given values and keeps the others. A custom period needs $from and $to (Y-m-d).
     */
    public function save(
        BackendUserAuthentication $backendUser,
        ?string $siteIdentifier = null,
        ?string $period = null,
        string $from = '',
        string $to = '',
        ?bool $compare = null,
    ): bool {
        if ($siteIdentifier !== null && !isset($this->getSites($backendUser)[$siteIdentifier])) {
            return false;
        }
        $stored = $this->getStored($backendUser);
        if ($siteIdentifier !== null) {
            $stored['site'] = $siteIdentifier;
        }
        if ($period !== null) {
            $normalized = Period::fromPreset($period, $this->getToday(), $from, $to);
            $isCustom = $normalized->preset === Period::CUSTOM;
            $stored['period'] = $normalized->preset;
            $stored['from'] = $isCustom ? $normalized->from->format('Y-m-d') : '';
            $stored['to'] = $isCustom ? $normalized->to->format('Y-m-d') : '';
        }
        if ($compare !== null) {
            $stored['compare'] = $compare;
        }
        $backendUser->uc['analytics_matomo']['dashboard'] = $stored;
        $backendUser->writeUC();
        return true;
    }

    /**
     * @return array{site: string, period: string, from: string, to: string, compare: bool}
     */
    private function getStored(BackendUserAuthentication $backendUser): array
    {
        $stored = $backendUser->uc['analytics_matomo']['dashboard'] ?? [];
        $stored = is_array($stored) ? $stored : [];
        return [
            'site' => (string)($stored['site'] ?? ''),
            // "days" (7, 14, 30) of a choice stored before the presets
            'period' => (string)($stored['period'] ?? $stored['days'] ?? Period::PRESETS[0]),
            'from' => (string)($stored['from'] ?? ''),
            'to' => (string)($stored['to'] ?? ''),
            'compare' => (bool)($stored['compare'] ?? false),
        ];
    }

    private function getToday(): \DateTimeImmutable
    {
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        return $now;
    }
}
