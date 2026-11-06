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
        $uri = new Uri($url);
        if ($uri->getHost() === '') {
            return null;
        }
        $language = null;
        $languageBasePath = '';
        foreach ($site->getLanguages() as $siteLanguage) {
            $base = $siteLanguage->getBase();
            $basePath = '/' . trim($base->getPath(), '/');
            $isOnBase = strcasecmp($base->getHost(), $uri->getHost()) === 0
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
        $uri = $uri->withQuery('')->withFragment('');
        try {
            $pageArguments = $site->getRouter()->matchRequest(
                new ServerRequest($uri),
                new SiteRouteResult($uri, $site, $language, ltrim($tail, '/'))
            );
        } catch (RouteNotFoundException) {
            return new PageUrlMatch($language, null);
        }

        return new PageUrlMatch($language, $pageArguments instanceof PageArguments ? $pageArguments->getPageId() : null);
    }
}
