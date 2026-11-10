<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Pages of a site (or of a subtree) that visitors see and the backend user may read,
 * in the default language and as translations of a site language
 */
final readonly class VisiblePages
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * @param int $pageId first page of the subtree, the root page for the whole site
     * @return list<array<string, mixed>> uid, l10n_parent, sys_language_uid, title, crdate, tstamp and SYS_LASTCHANGED
     */
    public function get(Site $site, int $pageId, BackendUserAuthentication $user): array
    {
        return array_values(array_filter($this->pages($site, $pageId), static function (array $page) use ($user): bool {
            $languageId = (int)$page['sys_language_uid'];
            $defaultPageId = $languageId > 0 ? (int)$page['l10n_parent'] : (int)$page['uid'];
            return $user->checkLanguageAccess($languageId) && BackendUtility::readPageAccess($defaultPageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
        }));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(Site $site, int $pageId): array
    {
        $excluded = PageTargetResolver::errorPageIds($site);
        $otherRoots = array_values(array_map(static fn (Site $other): int => $other->getRootPageId(), array_filter($this->siteFinder->getAllSites(), static fn (Site $other): bool => $other->getIdentifier() !== $site->getIdentifier())));
        $liveContext = clone $this->context;
        $liveContext->setAspect('workspace', new WorkspaceAspect(0));
        $liveContext->setAspect('visibility', new VisibilityAspect());
        $repository = GeneralUtility::makeInstance(PageRepository::class, $liveContext);
        $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId, '', $liveContext)->get();
        foreach ($rootline as $ancestor) {
            if (!empty($ancestor['extendToSubpages']) && $repository->checkRecord('pages', (int)$ancestor['uid']) === null) {
                return [];
            }
        }
        $pageIds = [$pageId, ...$repository->getDescendantPageIdsRecursive($pageId, 100, 0, $otherRoots)];
        // Pages visitors see: default restrictions plus the live workspace
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->add(new WorkspaceRestriction(0));
        $ids = $queryBuilder->createNamedParameter(array_values(array_diff($pageIds, $excluded)), Connection::PARAM_INT_ARRAY);
        $languageIds = array_map(static fn (SiteLanguage $language): int => $language->getLanguageId(), $site->getLanguages());
        return $queryBuilder
            ->select('uid', 'l10n_parent', 'sys_language_uid', 'title', 'crdate', 'tstamp', 'SYS_LASTCHANGED')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->and($queryBuilder->expr()->in('uid', $ids), $queryBuilder->expr()->eq('sys_language_uid', 0)),
                    $queryBuilder->expr()->and($queryBuilder->expr()->in('l10n_parent', $ids), $queryBuilder->expr()->gt('sys_language_uid', 0)),
                ),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter($languageIds, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->notIn('doktype', $queryBuilder->createNamedParameter(PageTargetResolver::DOKTYPES_WITHOUT_URL, Connection::PARAM_INT_ARRAY)),
                // Recycler and other system types
                $queryBuilder->expr()->lt('doktype', 200),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
