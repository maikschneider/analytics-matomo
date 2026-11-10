<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Redirect;

use MaikSchneider\AnalyticsMatomo\Statistics\PageRecordResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\VisiblePages;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Pages of a site language that may become redirect targets: their URL path and the
 * old slugs that redirects still point to them, also from disabled or expired redirects
 */
final readonly class RedirectTargets
{
    public function __construct(
        private VisiblePages $visiblePages,
        private PageRecordResolver $pageRecordResolver,
        private ConnectionPool $connectionPool,
        private LinkService $linkService,
    ) {
    }

    /**
     * @return list<RedirectCandidate>
     */
    public function get(Site $site, SiteLanguage $language, BackendUserAuthentication $user): array
    {
        // Untranslated pages are shown in a fallback language, unless the language is strict
        $languageIds = [$language->getLanguageId(), ...($language->getFallbackType() === 'strict' ? [] : $language->getFallbackLanguageIds())];
        $pages = [];
        foreach ($this->visiblePages->get($site, $site->getRootPageId(), $user) as $page) {
            $priority = array_search((int)$page['sys_language_uid'], $languageIds, true);
            $pageId = (int)$page['sys_language_uid'] > 0 ? (int)$page['l10n_parent'] : (int)$page['uid'];
            if ($priority !== false && $priority < ($pages[$pageId][0] ?? PHP_INT_MAX)) {
                $pages[$pageId] = [$priority, (string)$page['title']];
            }
        }
        $candidates = [];
        foreach ($pages as $pageId => [, $title]) {
            $uri = $site->getRouter()->generateUri($this->pageRecordResolver->resolve($pageId, $language), ['_language' => $language]);
            $candidates[] = new RedirectCandidate($pageId, $language->getLanguageId(), $title, $uri->getPath());
        }
        foreach ($this->oldSlugs($site) as [$path, $target]) {
            $link = $this->linkService->resolve($target);
            parse_str((string)($link['parameters'] ?? ''), $parameters);
            $pageId = (int)($link['pageuid'] ?? 0);
            if (($link['type'] ?? '') === LinkService::TYPE_PAGE && isset($pages[$pageId]) && (int)($parameters['_language'] ?? 0) === $language->getLanguageId()) {
                $candidates[] = new RedirectCandidate($pageId, $language->getLanguageId(), $pages[$pageId][1], $path, true);
            }
        }
        return $candidates;
    }

    /**
     * @return list<array{string, string}> source path and target of redirects to pages
     */
    private function oldSlugs(Site $site): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $rows = $queryBuilder
            ->select('source_path', 'target')
            ->from('sys_redirect')
            ->where(
                $queryBuilder->expr()->in('source_host', $queryBuilder->createNamedParameter(array_values(array_unique([RedirectWriter::sourceHost($site), '*'])), Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq('is_regexp', 0),
                $queryBuilder->expr()->like('target', $queryBuilder->createNamedParameter('t3://page?%')),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(static fn (array $row): array => [(string)$row['source_path'], (string)$row['target']], $rows);
    }
}
