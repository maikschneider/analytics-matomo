<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Matomo\SiteTimezone;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMatch;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlReport;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlRow;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Reads the page view store for the backend. A report reads it instead of
 * Matomo when the store holds every day of every period the report shows;
 * otherwise it asks Matomo as without a store. The store holds all visits of
 * a Matomo site, so configurations with a segment and demo sites never read
 * it, and a configuration reads it only while its own token may view the
 * Matomo site.
 */
final readonly class PageViewStore
{
    /**
     * Seconds the answer of the access check is kept, whatever the lifetime of the reports
     */
    private const ACCESS_LIFETIME = 3600;

    public function __construct(
        private ConnectionRepository $connections,
        private ImportRepository $imports,
        private UrlDayRepository $urlDays,
        private UrlPageMapper $urlPageMapper,
        private MatomoClient $client,
        private StatisticsCache $cache,
        private Context $context,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * What the store holds of the periods; null when the configuration may
     * not read a store or there is none. Days after today of the Matomo site
     * count as held: Matomo has no views for them either.
     *
     * @param ?Site $site the site whose page mapping is read: the store is not complete while its URLs are being mapped
     */
    public function source(MatomoConfiguration $configuration, ?Site $site, Period ...$periods): ?PageViewSource
    {
        if ($configuration->demo || $configuration->segment !== '' || $periods === []) {
            return null;
        }
        $connection = $this->connections->find($configuration);
        $overview = $connection === null ? null : $this->imports->overview($connection);
        if ($connection === null || $overview === null) {
            return null;
        }
        $matomoSite = $this->matomoSite($configuration);
        if ($matomoSite === null) {
            return null;
        }

        $now = $this->context->getPropertyFromAspect('date', 'full');
        \assert($now instanceof \DateTimeImmutable);
        $siteNow = $now->setTimezone(SiteTimezone::fromSite($matomoSite) ?? $now->getTimezone());
        $today = Day::fromDate($siteNow);
        $isComplete = $site === null || !$this->urlPageMapper->isRemapPending($site, $connection);
        $ranges = [];
        foreach ($periods as $period) {
            $from = Day::fromDate($period->from);
            $to = min(Day::fromDate($period->to), $today);
            if ($from > $to) {
                return null;
            }
            $coverage = $this->imports->coverage($connection, $from, $to, $siteNow);
            $ranges[$period->toMatomoDate()] = ['from' => $from, 'to' => $to, 'coverage' => $coverage];
            $isComplete = $isComplete && $coverage->isComplete;
        }
        return new PageViewSource(
            $connection,
            $isComplete,
            $ranges,
            $overview->oldestDay,
            $overview->newestDay,
            (new \DateTimeImmutable('@' . $overview->lastImportedAt))->setTimezone($now->getTimezone()),
        );
    }

    /**
     * The Matomo site as the token of the configuration sees it
     * (SitesManager.getSiteFromId), cached; null when the token may not view
     * it or Matomo did not answer.
     *
     * @return array<string, mixed>|null
     */
    public function matomoSite(MatomoConfiguration $configuration): ?array
    {
        $key = ['matomoSite', $configuration->getCacheIdentifier()];
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return is_array($cached['site'] ?? null) ? $cached['site'] : null;
        }
        try {
            [$site] = $this->client->bulkRequest($configuration, [['method' => 'SitesManager.getSiteFromId']], keepErrors: true);
        } catch (MatomoApiException $exception) {
            // Not cached: whether the token may view the site is unknown
            $this->logger->warning('Checking access to the Matomo site failed: {message}', ['message' => $exception->getMessage(), 'idSite' => $configuration->idSite]);
            return null;
        }
        // Without view access Matomo answers with an error instead of the site
        $site = is_array($site) && (int)($site['idsite'] ?? 0) === $configuration->idSite ? $site : null;
        $this->cache->set($key, ['site' => $site], self::ACCESS_LIFETIME);
        return $site;
    }

    /**
     * Page views per URL over the period.
     *
     * @return array<int, int> by URL uid
     */
    public function hitsByUrl(PageViewSource $source, Period $period): array
    {
        ['from' => $from, 'to' => $to] = $source->of($period);
        return array_map(static fn (array $counts): int => $counts['nb_hits'], $this->urlDays->sumByUrl($source->connection, $from, $to));
    }

    /**
     * The URLs whose total over the period is known: Matomo keeps or drops a URL's row
     * of a day as a whole, so a URL missing on a truncated day may hide in its summary
     * row. Null when no day of the period was truncated: every total is known, 0 included.
     *
     * @return array<int, true>|null by URL uid
     */
    public function completeUrls(PageViewSource $source, Period $period): ?array
    {
        ['from' => $from, 'to' => $to] = $source->of($period);
        $truncatedDays = $this->imports->truncatedDays($source->connection, $from, $to);
        if ($truncatedDays === []) {
            return null;
        }
        $counts = array_filter($this->urlDays->countDays($source->connection, $truncatedDays), static fn (int $days): bool => $days === count($truncatedDays));
        return array_fill_keys(array_keys($counts), true);
    }

    /**
     * @param list<int> $urlUids
     * @return array<int, string> URL as tracked, by URL uid
     */
    public function displayUrls(array $urlUids): array
    {
        return $this->urlDays->displayUrls($urlUids);
    }

    /**
     * The $limit most viewed URLs over the period like rows of Matomo's flat
     * page report: url (as tracked), label (path and query), the counts and
     * the metrics derived from them (PageMetrics::row()). A summary row
     * follows when Matomo truncated a day of the period or there were more URLs.
     *
     * @return list<array<string, mixed>>
     */
    public function urlRows(PageViewSource $source, Period $period, int $limit): array
    {
        ['from' => $from, 'to' => $to, 'coverage' => $coverage] = $source->of($period);
        $sums = $this->urlDays->sumByUrl($source->connection, $from, $to);
        uasort($sums, static fn (array $a, array $b): int => $b['nb_hits'] <=> $a['nb_hits'] ?: $b['nb_visits'] <=> $a['nb_visits']);
        $cut = array_slice($sums, $limit, null, true);
        $sums = array_slice($sums, 0, $limit, true);
        $urls = $this->urlDays->displayUrls(array_keys($sums));

        $rows = [];
        foreach ($sums as $uid => $counts) {
            $url = $urls[$uid] ?? null;
            if ($url !== null) {
                $rows[] = ['label' => self::label($url), 'url' => $url] + PageMetrics::row($counts);
            }
        }
        if ($coverage->isTruncated() || $cut !== []) {
            $summary = array_fill_keys(PageMetrics::COUNTS, 0);
            foreach ($cut as $counts) {
                foreach (PageMetrics::COUNTS as $column) {
                    $summary[$column] += $counts[$column];
                }
            }
            // Matomo's summary rows of truncated days are stored as page views only: no visits, no time, nothing to derive from
            $rows[] = ['label' => '-1', 'is_summary' => 1] + ['nb_hits' => $summary['nb_hits'] + $coverage->summaryHits] + PageMetrics::row($summary);
        }
        return $rows;
    }

    /**
     * Counts of the given URLs over the period (PageMetrics::row()); zero counts for a URL without views.
     *
     * @param array<array-key, string> $urls
     * @return array<array-key, array<string, int|float>> by key of $urls
     */
    public function rowsOfUrls(PageViewSource $source, Period $period, array $urls): array
    {
        ['from' => $from, 'to' => $to] = $source->of($period);
        $hashes = array_map(UrlDayRows::hash(...), $urls);
        $known = $this->urlDays->findUrls($source->connection, array_values(array_unique(array_filter($hashes, is_string(...)))));
        $sums = $this->urlDays->sumByUrlFor($source->connection, $from, $to, array_column($known, 'uid'));
        $rows = [];
        foreach ($hashes as $key => $hash) {
            $uid = $known[$hash ?? '']['uid'] ?? null;
            $rows[$key] = PageMetrics::row(($uid === null ? [] : $sums[$uid] ?? []) + array_fill_keys(PageMetrics::COUNTS, 0));
        }
        return $rows;
    }

    /**
     * Counts per page and language of the site over the period, as a report
     * of the site's URLs with one row per page and language.
     */
    public function pageReport(PageViewSource $source, Site $site, Period $period): PageUrlReport
    {
        ['from' => $from, 'to' => $to, 'coverage' => $coverage] = $source->of($period);
        $rows = [];
        foreach ($this->urlDays->sumByPage($site->getIdentifier(), $source->connection, $from, $to) as $pageId => $languages) {
            foreach ($languages as $languageId => $counts) {
                try {
                    $language = $site->getLanguageById($languageId);
                } catch (\InvalidArgumentException) {
                    // A language removed from the site
                    continue;
                }
                $rows[] = new PageUrlRow(PageMetrics::row($counts), new PageUrlMatch($language, $pageId));
            }
        }
        return new PageUrlReport($rows, $coverage->isTruncated());
    }

    /**
     * Path and query of the URL, like the labels of Matomo's flat page report.
     */
    private static function label(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        return ((string)parse_url($url, PHP_URL_PATH) ?: '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
