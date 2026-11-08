<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageRecordResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\PageTargetResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Visible pages of a site (or of a subtree) without views in the last 90 days, and pages
 * unchanged for a year that still get views, ranked by views × days since the last change.
 * A page created during the period is no candidate yet, nor is any page while Matomo has
 * tracked the site for less than the period.
 */
final readonly class ContentReviewService
{
    public const DAYS = 90;
    public const OLD_DAYS = 365;
    public const COLUMNS_WITHOUT_VIEWS = [
        'label' => ['column.pageTitle', 'string'],
        'nb_hits' => ['column.nb_hits', 'number'],
        'last_changed' => ['column.lastChanged', 'date'],
    ];
    public const COLUMNS_OLD = self::COLUMNS_WITHOUT_VIEWS + [
        'age' => ['column.age', 'number'],
        'priority' => ['column.priority', 'number', 'column.priority.description'],
    ];

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private PageUrlMapper $mapper,
        private PageRecordResolver $pageRecordResolver,
        private ConnectionPool $connectionPool,
        private Context $context,
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * @param int $pageId first page of the subtree, the root page for the whole site
     * @return array{period: Period, trackedSince: \DateTimeImmutable|null, truncated: bool, withoutViews: list<array<string, mixed>>, old: list<array<string, mixed>>}
     * @throws MatomoApiException
     */
    public function getReview(Site $site, MatomoConfiguration $configuration, int $pageId): array
    {
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $period = Period::fromPreset((string)self::DAYS, $now);
        $matomo = $this->cache->remember(['contentReview', $configuration->getCacheIdentifier(), $period->toMatomoDate()], function () use ($configuration, $period): array {
            [$rows, $site] = $this->client->bulkRequest($configuration, [
                ['method' => 'Actions.getPageUrls', 'period' => 'range', 'date' => $period->toMatomoDate(), 'flat' => 1, 'format_metrics' => 0, 'filter_limit' => -1, 'showColumns' => 'nb_hits'],
                ['method' => 'SitesManager.getSiteFromId'],
            ]);
            if (!is_array($rows) || !array_is_list($rows) || !is_array($site)) {
                throw new MatomoApiException('Matomo answered with an unexpected page report', 1794090001);
            }
            return ['rows' => array_values(array_filter($rows, is_array(...))), 'trackedSince' => (string)($site['ts_created'] ?? '')];
        });
        $trackedSince = $matomo['trackedSince'] === '' ? null : new \DateTimeImmutable(substr($matomo['trackedSince'], 0, 10));
        $report = $this->mapper->mapReport($site, $matomo['rows']);
        $provable = $trackedSince !== null && $trackedSince <= $period->from;

        $withoutViews = $old = [];
        foreach ($this->pages($site, $pageId) as $page) {
            $languageId = (int)$page['sys_language_uid'];
            $defaultPageId = $languageId > 0 ? (int)$page['l10n_parent'] : (int)$page['uid'];
            $user = $GLOBALS['BE_USER'];
            if (!$user->checkLanguageAccess($languageId) || !BackendUtility::readPageAccess($defaultPageId, $user->getPagePermsClause(Permission::PAGE_SHOW))) {
                continue;
            }
            $rows = $report->rowsOfPage($defaultPageId, $languageId);
            $views = $rows === null ? null : (int)array_sum(array_column($rows, 'nb_hits'));
            $lastChanged = max((int)$page['tstamp'], (int)$page['SYS_LASTCHANGED']);
            $age = $lastChanged > 0 ? (int)(new \DateTimeImmutable('@' . $lastChanged))->setTimezone($now->getTimezone())->setTime(0, 0)->diff($now->setTime(0, 0))->days : null;
            $language = $site->getLanguageById($languageId);
            $row = [
                'label' => (string)$page['title'],
                'url' => (string)$site->getRouter()->generateUri($this->pageRecordResolver->resolve($defaultPageId, $language), ['_language' => $language], '', RouterInterface::ABSOLUTE_URL),
                'nb_hits' => $views,
                'last_changed' => $lastChanged > 0 ? $lastChanged : null,
            ];
            if ($provable && !$views && (int)$page['crdate'] < $period->from->getTimestamp()) {
                $withoutViews[] = $row;
            }
            if ($views > 0 && $age !== null && $age >= self::OLD_DAYS) {
                $old[] = $row + ['age' => $age, 'priority' => $views * $age];
            }
        }
        // Longest unchanged first
        usort($withoutViews, static fn (array $a, array $b): int => ($a['last_changed'] ?? 0) <=> ($b['last_changed'] ?? 0));

        return ['period' => $period, 'trackedSince' => $trackedSince, 'truncated' => $report->isTruncated, 'withoutViews' => $withoutViews, 'old' => $old];
    }

    /**
     * Visible pages with a URL in the subtree, in the default language and as translations of a site language
     *
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
        $queryBuilder = $this->livePages();
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

    /**
     * Pages visitors see: default restrictions plus the live workspace
     */
    private function livePages(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->add(new WorkspaceRestriction(0));
        return $queryBuilder;
    }
}
