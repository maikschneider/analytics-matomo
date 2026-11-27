<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlPageMapper;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Fills the page view store like the import command does, without Matomo requests.
 */
trait PageViewStoreTrait
{
    /**
     * @param array<string, list<array<string, mixed>>> $days rows of Matomo's flat page report of a day (period=day), by day (Y-m-d)
     * @param list<Site> $sites the TYPO3 sites whose pages the URLs are mapped to
     * @return int the connection
     */
    private function fillPageViewStore(MatomoConfiguration $configuration, array $days, array $sites, int $importedAt = 1793000000): int
    {
        $pool = $this->getConnectionPool();
        $connection = (new ConnectionRepository($pool))->getOrCreate($configuration);
        $urlDays = new UrlDayRepository($pool);
        $imports = new ImportRepository($pool);
        foreach ($days as $date => $rows) {
            $day = (int)str_replace('-', '', $date);
            $urlDay = UrlDayRows::fromReport($rows);
            $uids = $urlDays->addUrls($connection, $day, $urlDay);
            $pool->getConnectionForTable(UrlDayRepository::TABLE)->transactional(static function (Connection $database) use ($urlDays, $imports, $connection, $day, $urlDay, $uids, $importedAt): void {
                $urlDays->replaceDayFacts($database, $connection, $day, $urlDay, $uids);
                $imports->markImported($database, $connection, $day, $importedAt, $urlDay);
            });
        }
        $mapper = new UrlPageMapper($this->get(PageUrlMapper::class), $pool);
        foreach ($sites as $site) {
            $mapper->remapSite($site, $connection, PHP_INT_MAX);
        }
        return $connection;
    }

    /**
     * The same rows on every day from $from to $to (Y-m-d)
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private static function everyDay(string $from, string $to, array $rows): array
    {
        $days = [];
        for ($day = new \DateTimeImmutable($from); $day <= new \DateTimeImmutable($to); $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = $rows;
        }
        return $days;
    }
}
