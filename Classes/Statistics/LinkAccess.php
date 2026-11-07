<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Module\ReportTableFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Whether a backend user may see a clicked link (outlink or download). Links
 * into a TYPO3 site follow the rules of the URL reports: the user needs access
 * to the language and to the page, or to the site for URLs without a page
 * (e.g. files). Links outside every site are shown as they are.
 *
 * @phpstan-import-type Report from ReportTableFactory
 */
final readonly class LinkAccess
{
    public function __construct(
        private PageUrlMapper $mapper,
        private SiteFinder $siteFinder,
    ) {
    }

    public function isVisible(Site $site, string $url, BackendUserAuthentication $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $found = $this->findSite($site, $url);
        if ($found === null) {
            return true;
        }
        [$owner, $match] = $found;
        if (!$user->checkLanguageAccess($match->language->getLanguageId())) {
            return false;
        }
        return BackendUtility::readPageAccess($match->pageId ?? $owner->getRootPageId(), $user->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }

    /**
     * A processed report of clicked links without the rows the user may not see
     *
     * @param Report $report
     * @return Report
     */
    public function filterReport(Site $site, array $report, BackendUserAuthentication $user): array
    {
        $rows = [];
        $metadata = [];
        foreach ($report['reportData'] as $index => $row) {
            $rowMetadata = $report['reportMetadata'][$index] ?? [];
            if (PageUrlMapper::isSummaryRow($row) || $this->isVisible($site, (string)($rowMetadata['url'] ?? $row['label'] ?? ''), $user)) {
                $rows[] = $row;
                $metadata[] = $rowMetadata;
            }
        }
        return ['reportData' => $rows, 'reportMetadata' => $metadata] + $report;
    }

    /**
     * @return array{Site, PageUrlMatch}|null
     */
    private function findSite(Site $site, string $url): ?array
    {
        $match = $this->mapper->match($site, $url);
        if ($match !== null) {
            return [$site, $match];
        }
        foreach ($this->siteFinder->getAllSites() as $other) {
            $match = $other->getIdentifier() === $site->getIdentifier() ? null : $this->mapper->match($other, $url);
            // A base without host serves any domain, so it claims no link of another site
            if ($match !== null && $match->language->getBase()->getHost() !== '') {
                return [$other, $match];
            }
        }
        return null;
    }
}
