<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Imports Matomo's page report per day of one connection into the store,
 * newest day first: the days that are not final yet (today, and yesterday
 * until it is), then the days missing back to the retention limit. Each
 * day costs one request; a day that fails is recorded and skipped.
 *
 * Callers must hold the import lock of the connection.
 */
final readonly class PageViewImporter
{
    public function __construct(
        private MatomoClient $client,
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
     * @param int $retentionDays days kept, today included; older days are deleted
     * @param array{from: int, to: int}|null $range days (Ymd) to import again, imported or not, instead of the days due; limited to the retention
     * @param list<int> $exclude days (Ymd) not to import in this run, e.g. imported by an earlier pass of the same run
     * @throws \InvalidArgumentException for demo configurations and configurations with a segment: the store holds the data of a whole Matomo site
     */
    public function importConnection(array $sites, MatomoConfiguration $configuration, int $budget, int $timeout, int $retentionDays, ?array $range = null, array $exclude = []): ImportResult
    {
        if ($configuration->demo || $configuration->segment !== '') {
            throw new \InvalidArgumentException('Demo configurations and configurations with a segment are not stored', 1794500010);
        }
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('The retention must be at least one day, got ' . $retentionDays, 1794500011);
        }
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $timezone = $now->getTimezone();
        $today = Day::toDate(Day::fromDate($now), $timezone);
        $oldestDay = Day::fromDate($today->modify('-' . ($retentionDays - 1) . ' days'));
        $connection = $this->connections->getOrCreate($configuration);

        foreach ($sites as $site) {
            $this->urlPageMapper->refreshSite($site, $connection);
        }

        $days = $range === null
            ? [...$this->imports->notFinalDays($connection, $now), ...$this->imports->missingDays($connection, $oldestDay, Day::fromDate($today))]
            : Day::range(max($range['from'], $oldestDay), min($range['to'], Day::fromDate($today)));
        $days = array_values(array_diff(array_unique($days), $exclude));
        // Yesterday may be beyond a retention of one day
        $days = array_values(array_filter($days, static fn (int $day): bool => $day >= $oldestDay));
        rsort($days);

        $requests = 0;
        $imported = [];
        $failed = [];
        while ($days !== [] && $requests < $budget) {
            $day = array_shift($days);
            $requests++;
            try {
                $this->importDay($sites, $configuration, $connection, $day, Day::toDate($day, $timezone), $now, $timeout);
                $imported[] = $day;
            } catch (MatomoApiException $exception) {
                $failed[$day] = $exception->getMessage();
                $this->logger->warning('Page views of {day} not imported from Matomo site {idSite}: {message}', [
                    'day' => $day,
                    'idSite' => $configuration->idSite,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->imports->purgeBefore($connection, $oldestDay);

        return new ImportResult($connection, $requests, $imported, $failed, count($days));
    }

    /**
     * @param list<Site> $sites
     * @throws MatomoApiException
     */
    private function importDay(array $sites, MatomoConfiguration $configuration, int $connection, int $day, \DateTimeImmutable $date, \DateTimeImmutable $now, int $timeout): void
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
        $this->connectionPool->getConnectionForTable(UrlDayRepository::TABLE)->transactional(function (Connection $database) use ($connection, $day, $urlDay, $uids, $now): void {
            $this->urlDays->replaceDayFacts($database, $connection, $day, $urlDay, $uids);
            $this->imports->markImported($database, $connection, $day, $now->getTimestamp(), $urlDay);
        });

        $urls = [];
        foreach ($uids as $hash => $uid) {
            $urls[$uid] = $urlDay->rows[$hash]->url;
        }
        foreach ($sites as $site) {
            $this->urlPageMapper->mapUrls($site, $urls);
        }
    }
}
