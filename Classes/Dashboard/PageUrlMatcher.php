<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Maps a page URL tracked by Matomo back to a language of the site and, if the
 * TYPO3 router resolves it, to the page. The scheme is ignored, as Matomo often
 * tracks http and https URLs of the same page.
 */
final readonly class PageUrlMatcher
{
    public function match(Site $site, string $url): ?PageUrlMatch
    {
        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException) {
            return null;
        }
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

        $tail = $languageBasePath === '/' ? $uri->getPath() : substr($uri->getPath(), strlen($languageBasePath));
        parse_str($uri->getQuery(), $query);
        $uri = $uri->withQuery('')->withFragment('');
        // The router reads the page ID of "?id=" URLs from the query parameters; tracking parameters don't matter
        $request = (new ServerRequest($uri))->withQueryParams(isset($query['id']) && is_scalar($query['id']) ? ['id' => $query['id']] : []);
        try {
            $pageArguments = $site->getRouter()->matchRequest(
                $request,
                new SiteRouteResult($uri, $site, $language, ltrim($tail, '/'))
            );
        } catch (RouteNotFoundException) {
            return new PageUrlMatch($language, null);
        }

        return new PageUrlMatch($language, $pageArguments instanceof PageArguments ? $pageArguments->getPageId() : null);
    }
}
