<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Matomo;

use MaikSchneider\AnalyticsMatomo\Statistics\PageTargetResolver;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Frontend URLs of the visible pages of a site in every language a visitor can
 * see them in, so the demo top pages link to real pages.
 */
final readonly class DemoPageUrls
{
    private const MAX_PAGES = 100;

    public function __construct(
        private SiteFinder $siteFinder,
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return list<string>
     */
    public function get(int $rootPageId): array
    {
        try {
            $site = $this->siteFinder->getSiteByRootPageId($rootPageId);
        } catch (SiteNotFoundException) {
            return [];
        }

        $pageIds = [];
        for ($parents = [$rootPageId]; $parents !== [] && count($pageIds) < self::MAX_PAGES; $parents = $this->children($parents)) {
            $pageIds = [...$pageIds, ...$parents];
        }
        $pageIds = array_slice($pageIds, 0, self::MAX_PAGES);

        $languageIds = [];
        foreach ($this->pagesWithTranslations($pageIds) as $page) {
            if (!in_array((int)$page['doktype'], PageTargetResolver::DOKTYPES_WITHOUT_URL, true)) {
                $pageId = (int)$page['sys_language_uid'] > 0 ? (int)$page['l10n_parent'] : (int)$page['uid'];
                $languageIds[$pageId][] = (int)$page['sys_language_uid'];
            }
        }

        $urls = [];
        foreach (array_diff($pageIds, $this->errorPageIds($site)) as $pageId) {
            foreach ($site->getLanguages() as $language) {
                $isVisible = in_array($language->getLanguageId(), $languageIds[$pageId] ?? [], true)
                    || ($language->getFallbackType() === 'fallback' && in_array(0, $languageIds[$pageId] ?? [], true));
                if ($isVisible) {
                    $urls[] = (string)$site->getRouter()->generateUri($pageId, ['_language' => $language], '', RouterInterface::ABSOLUTE_URL);
                }
            }
        }
        return $urls;
    }

    /**
     * Error pages are shown at the URL that was not found, never at their own
     *
     * @return list<int>
     */
    private function errorPageIds(Site $site): array
    {
        $pageIds = [];
        foreach ($site->getConfiguration()['errorHandling'] ?? [] as $errorHandling) {
            if (preg_match('#^t3://page\?uid=(\d+)$#', (string)($errorHandling['errorContentSource'] ?? ''), $match) === 1) {
                $pageIds[] = (int)$match[1];
            }
        }
        return $pageIds;
    }

    /**
     * @param list<int> $pageIds
     * @return list<int>
     */
    private function children(array $pageIds): array
    {
        $queryBuilder = $this->publicPages();
        return array_map(intval(...), $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @param list<int> $pageIds
     * @return list<array<string, mixed>>
     */
    private function pagesWithTranslations(array $pageIds): array
    {
        $queryBuilder = $this->publicPages();
        $ids = $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY);
        return $queryBuilder
            ->select('uid', 'l10n_parent', 'sys_language_uid', 'doktype')
            ->from('pages')
            ->where($queryBuilder->expr()->or(
                $queryBuilder->expr()->in('uid', $ids),
                $queryBuilder->expr()->in('l10n_parent', $ids),
            ))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Pages that anonymous visitors see in the live workspace
     */
    private function publicPages(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->add(new FrontendGroupRestriction([0, -1]))
            ->add(new WorkspaceRestriction(0));
        return $queryBuilder;
    }
}
