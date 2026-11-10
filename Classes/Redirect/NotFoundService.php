<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Redirect;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * URLs that ended on the 404 page, tracked with the page title convention of
 * https://matomo.org/faq/how-to/faq_60/, with redirect suggestions per path
 */
final readonly class NotFoundService
{
    public const TITLE_PATTERN = '404/URL';
    /** Suggestions are computed for the paths with the most hits only */
    public const LIMIT = 100;

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private PageUrlMapper $mapper,
        private RedirectTargets $targets,
        private RedirectWriter $writer,
        private RedirectSuggester $suggester,
    ) {
    }

    /**
     * @return list<array{path: string, hits: int, referrer: string, suggestions: list<RedirectSuggestion>, redirect: int|null}> most hits first
     * @throws MatomoApiException
     */
    public function getPages(Site $site, MatomoConfiguration $configuration, Period $period, BackendUserAuthentication $user): array
    {
        $paths = $this->cache->remember(['notFound', $configuration->getCacheIdentifier(), $period->toMatomoDate()], function () use ($configuration, $period): array {
            [$rows] = $this->client->bulkRequest($configuration, [[
                'method' => 'Actions.getPageTitles', 'period' => 'range', 'date' => $period->toMatomoDate(),
                'flat' => 1, 'format_metrics' => 0, 'filter_limit' => -1, 'filter_pattern' => self::TITLE_PATTERN, 'showColumns' => 'nb_hits',
            ]]);
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new MatomoApiException('Matomo answered with an unexpected page title report', 1794300101);
            }
            return self::aggregate($rows);
        });

        $settings = $site->getSettings();
        $minimumHits = max(1, (int)$settings->get('analyticsMatomo.notFoundMinimumHits', 2));
        $ignored = array_filter(array_map(trim(...), (array)$settings->get('analyticsMatomo.notFoundIgnoredPaths', [])));
        $paths = array_filter($paths, static fn (array $page): bool => $page['hits'] >= $minimumHits && !self::isIgnored($page['path'], $ignored));
        $paths = array_slice($paths, 0, self::LIMIT);

        $existing = $this->writer->findExisting($site, array_column($paths, 'path'));
        $candidates = [];
        $pages = [];
        foreach ($paths as $page) {
            $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $page['path'])));
            $language = $this->mapper->match($site, (string)$site->getBase()->withPath($encodedPath)->withQuery('')->withFragment(''))?->language;
            $suggestions = [];
            if ($language !== null) {
                $candidates[$language->getLanguageId()] ??= $this->targets->get($site, $language, $user);
                $suggestions = $this->suggester->suggest($page['path'], $candidates[$language->getLanguageId()]);
            }
            $pages[] = $page + ['suggestions' => $suggestions, 'redirect' => $existing[$page['path']] ?? null];
        }
        return $pages;
    }

    /**
     * Hits per path without query; the top referrer is the one with the most hits
     *
     * @param list<mixed> $rows flat page title rows
     * @return list<array{path: string, hits: int, referrer: string}> most hits first
     */
    public static function aggregate(array $rows): array
    {
        $paths = [];
        foreach ($rows as $row) {
            $title = is_array($row) ? self::parse($row) : null;
            if ($title === null) {
                continue;
            }
            $hits = (int)($row['nb_hits'] ?? 0);
            $path = $title['path'];
            $paths[$path]['hits'] = ($paths[$path]['hits'] ?? 0) + $hits;
            if ($title['referrer'] !== '') {
                $paths[$path]['referrers'][$title['referrer']] = ($paths[$path]['referrers'][$title['referrer']] ?? 0) + $hits;
            }
        }
        $result = [];
        foreach ($paths as $path => $data) {
            $referrers = $data['referrers'] ?? [];
            // Ties go to the alphabetically first referrer, so the list does not change between requests
            uksort($referrers, static fn (string $a, string $b): int => [$referrers[$b], $a] <=> [$referrers[$a], $b]);
            $result[] = ['path' => (string)$path, 'hits' => $data['hits'], 'referrer' => (string)(array_key_first($referrers) ?? '')];
        }
        usort($result, static fn (array $a, array $b): int => [$b['hits'], $a['path']] <=> [$a['hits'], $b['path']]);
        return $result;
    }

    /**
     * The tracked title holds the browser's path and query and the referrer, each encoded with
     * encodeURIComponent(). Flat reports have it in Actions_PageTitle; the label is decoded once.
     *
     * @param array<mixed> $row
     * @return array{path: string, referrer: string}|null decoded path without query and referrer URL
     */
    public static function parse(array $row): ?array
    {
        $tracked = $row['Actions_PageTitle'] ?? null;
        $title = is_string($tracked) ? $tracked : (string)($row['label'] ?? '');
        if (preg_match('#^404/URL = (\S+) /From =(?: (.*))?$#', trim($title), $matches) !== 1) {
            return null;
        }
        $url = is_string($tracked) ? rawurldecode($matches[1]) : $matches[1];
        $referrer = $matches[2] ?? '';
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        return ['path' => rawurldecode(explode('?', $url, 2)[0]), 'referrer' => is_string($tracked) ? rawurldecode($referrer) : $referrer];
    }

    /**
     * @param array<string> $ignored path prefixes
     */
    private static function isIgnored(string $path, array $ignored): bool
    {
        foreach ($ignored as $prefix) {
            if (str_starts_with(mb_strtolower($path), mb_strtolower($prefix))) {
                return true;
            }
        }
        return false;
    }
}
