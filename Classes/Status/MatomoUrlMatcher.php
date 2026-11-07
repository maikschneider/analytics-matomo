<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Status;

/**
 * Whether Matomo accepts tracking of a URL for a site whose "exclude unknown URLs" option
 * is on, following Matomo's SiteUrls::getIdSitesMatchingUrl(): hosts without "www.",
 * wildcard subdomains as ".example.org", and the deepest matching path.
 *
 * @see https://github.com/matomo-org/matomo/blob/5.x-dev/plugins/SitesManager/SiteUrls.php
 */
final class MatomoUrlMatcher
{
    /**
     * @param list<string> $siteUrls URLs of the Matomo site
     */
    public static function covers(array $siteUrls, string $url): bool
    {
        $host = self::host($url);
        if ($host === '') {
            return false;
        }
        $paths = [];
        foreach ($siteUrls as $siteUrl) {
            $siteHost = self::host($siteUrl);
            if ($siteHost !== '') {
                $paths[$siteHost][] = self::path($siteUrl);
            }
        }
        $hosts = [$host, '.' . $host];
        while (substr_count($host, '.') >= 2) {
            $host = substr($host, strpos($host, '.') + 1);
            $hosts[] = '.' . $host;
        }
        foreach ($hosts as $candidate) {
            if (isset($paths[$candidate])) {
                $path = self::path($url);
                foreach ($paths[$candidate] as $sitePath) {
                    if (str_starts_with($path, $sitePath)) {
                        return true;
                    }
                }
                // Matomo stops at the first known host, even without a matching path
                return false;
            }
        }
        return false;
    }

    private static function host(string $url): string
    {
        $host = mb_strtolower((string)parse_url($url, PHP_URL_HOST));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function path(string $url): string
    {
        $path = mb_strtolower((string)parse_url($url, PHP_URL_PATH));
        return $path === '' ? '/' : rtrim($path, '/') . '/';
    }
}
