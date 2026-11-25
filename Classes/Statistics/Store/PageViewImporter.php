<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Matomo\SiteTimezone;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Imports Matomo's page report per day of one connection into the store,
 * newest day first: the days that are not final yet (today, yesterday until
 * it is, and older days imported before their end), then the days missing back
 * to the retention limit. Each day costs one request; a day that fails is
 * recorded and skipped.
 *
 * Maintenance runs once per connection and run, after its imports:
 * purgeExpiredDays() and remapSites().
 *
 * Callers must hold the import lock of the connection.
 */
final readonly class PageViewImporter
{
    private const TIMEZONE_LIFETIME = 3600;

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private Context $context,
        private ConnectionPool $connectionPool,
        private ConnectionRepository $connections,
        private UrlDayRepository $urlDays,
        private ImportRepository $imports,
        private UrlPageMapper $urlPageMapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Site> $sites the TYPO3 sites of the connection; the URLs are mapped to the pages of each
     * @param int $budget Matomo requests this run may send (one per day)
     * @param int $timeout seconds per request
     * @param int $retentionDays days kept, today included
     * @param array{from: int, to: int}|null $range days (Ymd) to import again, imported or not, instead of the days due; limited to the retention
     * @param list<int> $exclude days (Ymd) not to import in this run, e.g. imported by an earlier pass of the same run
     * @param bool $notFinalOnly import only today and yesterday until final, so that every connection gets them before any backfill
     * @throws \InvalidArgumentException for demo configurations and configurations with a segment: the store holds the data of a whole Matomo site
     */
    public function importConnection(array $sites, MatomoConfiguration $configuration, int $budget, int $timeout, int $retentionDays, ?array $range = null, array $exclude = [], bool $notFinalOnly = false): ImportResult
    {
        $connection = $this->connection($configuration);
        $now = $this->now($configuration);
        $timezone = $now->getTimezone();
        $today = Day::fromDate($now);
        $oldestDay = $this->oldestDay($now, $retentionDays);

        $notFinal = $this->imports->notFinalDays($connection, $now);
        $days = $range === null
            ? [...$notFinal, ...$this->imports->missingDays($connection, $oldestDay, $today)]
            : Day::range(max($range['from'], $oldestDay), min($range['to'], $today));
        $days = array_values(array_diff(array_unique($days), $exclude));
        // Yesterday may be beyond a retention of one day
        $days = array_values(array_filter($days, static fn (int $day): bool => $day >= $oldestDay));
        rsort($days);
        $current = array_filter($notFinal, static fn (int $day): bool => $day >= Day::fromDate($now->modify('-1 day')));
        $due = $notFinalOnly ? array_values(array_intersect($days, $current)) : $days;

        $newestDay = $this->imports->newestDay($connection) ?? 0;
        $requests = 0;
        $imported = [];
        $failed = [];
        while ($due !== [] && $requests < $budget) {
            $day = array_shift($due);
            $requests++;
            try {
                $this->importDay($sites, $configuration, $connection, $day, Day::toDate($day, $timezone), $now, $timeout, $day >= $newestDay);
                $imported[] = $day;
                $newestDay = max($newestDay, $day);
            } catch (\Throwable $exception) {
                $failed[$day] = $exception->getMessage();
                $this->logger->warning('Page views of {day} not imported from Matomo site {idSite}: {message}', [
                    'day' => $day,
                    'idSite' => $configuration->idSite,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return new ImportResult($requests, $imported, $failed, count($days) - $requests);
    }

    /**
     * Deletes the days of the connection beyond the retention, and the URLs
     * no day points to anymore.
     *
     * @param int $retentionDays days kept, today included
     */
    public function purgeExpiredDays(MatomoConfiguration $configuration, int $retentionDays): void
    {
        $connection = $this->connection($configuration);
        $this->imports->purgeBefore($connection, $this->oldestDay($this->now($configuration), $retentionDays));
    }

    /**
     * Maps the stored URLs of the connection again for the sites that are
     * new to it or whose configuration changed, up to $limit URLs in all;
     * a re-map that does not fit goes on in the next run.
     *
     * @param list<Site> $sites
     * @return int URLs mapped
     */
    public function remapSites(array $sites, MatomoConfiguration $configuration, int $limit): int
    {
        $connection = $this->connection($configuration);
        $mapped = 0;
        foreach ($sites as $site) {
            $mapped += $this->urlPageMapper->remapSite($site, $connection, $limit - $mapped);
        }
        return $mapped;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function connection(MatomoConfiguration $configuration): int
    {
        if ($configuration->demo || $configuration->segment !== '') {
            throw new \InvalidArgumentException('Demo configurations and configurations with a segment are not stored', 1794500010);
        }
        return $this->connections->getOrCreate($configuration);
    }

    /**
     * Now in the time zone of the Matomo site: Matomo's report of a day runs
     * from midnight to midnight there, so it decides which day is today and
     * when a day has ended.
     *
     * @throws MatomoApiException
     */
    private function now(MatomoConfiguration $configuration): \DateTimeImmutable
    {
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $timezone = $this->cache->remember(['siteTimezone', $configuration->getCacheIdentifier()], function () use ($configuration): string {
            $site = $this->client->bulkRequest($configuration, [['method' => 'SitesManager.getSiteFromId']])[0] ?? null;
            return SiteTimezone::fromSite($site)?->getName()
                ?? throw new MatomoApiException('Matomo site ' . $configuration->idSite . ' has no time zone PHP understands', 1794500012);
        }, self::TIMEZONE_LIFETIME);
        return $now->setTimezone(new \DateTimeZone($timezone));
    }

    /**
     * @return int Ymd
     */
    private function oldestDay(\DateTimeImmutable $now, int $retentionDays): int
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('The retention must be at least one day, got ' . $retentionDays, 1794500011);
        }
        $today = Day::toDate(Day::fromDate($now), $now->getTimezone());
        return Day::fromDate($today->modify('-' . ($retentionDays - 1) . ' days'));
    }

    /**
     * Stores the day. Its URLs are mapped before the day is marked imported,
     * so a failing mapping leaves the day due for the next run.
     *
     * @param list<Site> $sites
     * @param bool $mapAll map all URLs of the day (the newest day: last sight), or only those without mapping (an older day)
     */
    private function importDay(array $sites, MatomoConfiguration $configuration, int $connection, int $day, \DateTimeImmutable $date, \DateTimeImmutable $now, int $timeout, bool $mapAll): void
    {
        $result = $this->client->bulkRequest($configuration, [[
            'method' => 'Actions.getPageUrls',
            'period' => 'day',
            // Always an explicit date: "today" would be Matomo's today in the site's timezone
            'date' => $date->format('Y-m-d'),
            'flat' => 1,
            'format_metrics' => 0,
            'filter_limit' => -1,
            // Label and URL are kept, they are not columns
            'showColumns' => implode(',', PageMetrics::COUNTS),
        ]], timeout: $timeout);
        $urlDay = UrlDayRows::fromReport($result[0] ?? null);

        $uids = $this->urlDays->addUrls($connection, $day, $urlDay);
        $urls = [];
        foreach ($uids as $hash => $uid) {
            $urls[$uid] = $urlDay->rows[$hash]->url;
        }
        foreach ($sites as $site) {
            if ($mapAll) {
                $this->urlPageMapper->mapUrls($site, $urls);
            } else {
                $this->urlPageMapper->mapUnmappedUrls($site, $urls);
            }
        }

        $this->connectionPool->getConnectionForTable(UrlDayRepository::TABLE)->transactional(function (Connection $database) use ($connection, $day, $urlDay, $uids, $now): void {
            $this->urlDays->replaceDayFacts($database, $connection, $day, $urlDay, $uids);
            $this->imports->markImported($database, $connection, $day, $now->getTimestamp(), $urlDay);
        });
    }
}
