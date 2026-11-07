<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Maps page URLs tracked by Matomo back to a language of the site and, if the
 * TYPO3 router resolves it, to the page. Every view that shows numbers per page
 * uses it, so a URL means the same page everywhere.
 */
final readonly class PageUrlMapper
{
    public function __construct(
        private Context $context,
    ) {
    }

    /**
     * Rows of a flat Matomo URL report (e.g. Actions.getPageUrls) that belong to the site.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function mapReport(Site $site, array $rows): PageUrlReport
    {
        $mapped = [];
        $isTruncated = false;
        foreach ($rows as $row) {
            if (self::isSummaryRow($row)) {
                $isTruncated = true;
                continue;
            }
            $match = $this->match($site, (string)($row['url'] ?? ''));
            if ($match !== null) {
                $mapped[] = new PageUrlRow($row, $match);
            }
        }
        return new PageUrlReport($mapped, $isTruncated);
    }

    public function match(Site $site, string $url): ?PageUrlMatch
    {
        $url = self::normalize($url);
        if ($url === null) {
            return null;
        }
        $uri = new Uri($url);
        $language = null;
        $languageBasePath = '';
        foreach ($site->getLanguages() as $siteLanguage) {
            $base = $siteLanguage->getBase();
            $basePath = '/' . trim($base->getPath(), '/');
            // A base without host ("/") serves any domain
            $isOnBase = ($base->getHost() === '' || strcasecmp($base->getHost(), $uri->getHost()) === 0)
                && ($basePath === '/' || $uri->getPath() === $basePath || str_starts_with($uri->getPath(), $basePath . '/'));
            // The most specific base wins: /de/ over /
            if ($isOnBase && ($language === null || strlen($basePath) > strlen($languageBasePath))) {
                $language = $siteLanguage;
                $languageBasePath = $basePath;
            }
        }
        if (!$language instanceof SiteLanguage) {
            return null;
        }

        // Uri encodes the path again; the router compares decoded slugs (like SiteMatcher's tail)
        $tail = rawurldecode($languageBasePath === '/' ? $uri->getPath() : substr($uri->getPath(), strlen($languageBasePath)));
        parse_str($uri->getQuery(), $query);
        $uri = $uri->withQuery('');
        // The router reads the page ID of "?id=" URLs from the query parameters
        $request = (new ServerRequest($uri))->withQueryParams(isset($query['id']) ? ['id' => $query['id']] : []);
        // Hidden pages were public before or will be, so their URLs still belong to them
        $context = clone $this->context;
        $context->setAspect('visibility', new VisibilityAspect(includeHiddenPages: true));
        try {
            $pageArguments = $site->getRouter($context)->matchRequest(
                $request,
                new SiteRouteResult($uri, $site, $language, ltrim($tail, '/'))
            );
        } catch (RouteNotFoundException) {
            return new PageUrlMatch($language, null);
        }

        return new PageUrlMatch($language, $pageArguments instanceof PageArguments ? $pageArguments->getPageId() : null);
    }

    /**
     * An absolute URL with what identifies a page only: scheme-less labels get
     * https, repeated slashes are collapsed, percent-encoding is decoded, and
     * of the query only a page ID is kept. cHash, plugin arguments and tracking
     * parameters (whether Matomo excludes them or not) don't change the page.
     * A path without host is kept for site bases without host. Returns null for
     * anything else that is not a URL.
     */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        $isLabel = !str_contains($url, '://');
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif ($isLabel && !str_starts_with($url, '/')) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || (($parts['host'] ?? '') === '' && !str_starts_with($url, '/'))) {
            return null;
        }
        // A label without a dot before the first slash is a word ("Others"), not a host
        if ($isLabel && isset($parts['host']) && !str_contains($parts['host'], '.')) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        $pageId = $query['id'] ?? null;

        return (isset($parts['host']) ? ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : '')
            . preg_replace('#/{2,}#', '/', rawurldecode($parts['path'] ?? '/'))
            . (is_scalar($pageId) && (string)$pageId !== '' ? '?id=' . rawurlencode((string)$pageId) : '');
    }

    /**
     * The row Matomo adds when it cuts a report (or one of its folders) at the
     * archiving row limit (datatable_archiving_maximum_rows_actions, 500 by
     * default, 100 per folder): every URL that is not in the report may be in there.
     *
     * @param array<string, mixed> $row
     */
    public static function isSummaryRow(array $row): bool
    {
        return !empty($row['is_summary']) || (string)($row['label'] ?? '') === '-1';
    }
}
