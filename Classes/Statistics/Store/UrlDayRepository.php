<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Counts per URL and day of a connection, and the URL dictionary they point to.
 *
 * Callers that write (addUrls(), replaceDayFacts()) must hold the import lock
 * of the connection: the writes are not safe against a concurrent import.
 * An import of a day runs addUrls() in autocommit, then replaceDayFacts() and
 * ImportRepository::markImported() in one transaction.
 */
final readonly class UrlDayRepository
{
    public const TABLE = 'tx_analyticsmatomo_url_day';
    public const URL_TABLE = 'tx_analyticsmatomo_url';
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Adds the URLs of the day that are new to the connection to the
     * dictionary, in autocommit: call it before the transaction of
     * replaceDayFacts(). Adding is idempotent; URLs no day points to are
     * removed by ImportRepository::purgeBefore(). The display URL of known
     * URLs follows the newest imported day, so a backfill of older days does
     * not bring back an outdated variant.
     *
     * @param int $day Ymd
     * @return array<string, int> uid of each URL of the day, by URL hash
     */
    public function addUrls(int $connection, int $day, UrlDay $urlDay): array
    {
        $database = $this->connectionPool->getConnectionForTable(self::URL_TABLE);
        $hashes = array_map(strval(...), array_keys($urlDay->rows));
        $known = $this->findUrls($connection, $hashes);

        if ($day >= $this->newestDay($connection)) {
            foreach ($known as $hash => $url) {
                if ($url['url'] !== $urlDay->rows[$hash]->url) {
                    $database->update(self::URL_TABLE, ['url' => $urlDay->rows[$hash]->url], ['uid' => $url['uid']], [Connection::PARAM_STR, Connection::PARAM_INT]);
                }
            }
        }

        $missing = array_values(array_diff($hashes, array_keys($known)));
        if ($missing !== []) {
            $this->insertUrls($connection, array_combine($missing, array_map(static fn (string $hash): string => $urlDay->rows[$hash]->url, $missing)));
            $known += $this->findUrls($connection, $missing);
        }

        $uids = [];
        foreach ($hashes as $hash) {
            if (!isset($known[$hash])) {
                throw new \RuntimeException('URL ' . $urlDay->rows[$hash]->url . ' could not be added to the page view store', 1794500003);
            }
            $uids[$hash] = $known[$hash]['uid'];
        }
        return $uids;
    }

    /**
     * Inserts the URLs into the dictionary, skipping those another process
     * inserted meanwhile. Runs in autocommit.
     *
     * @internal use addUrls(); public to test the race with another process
     * @param array<string, string> $urls display URL by URL hash
     */
    public function insertUrls(int $connection, array $urls): void
    {
        $database = $this->connectionPool->getConnectionForTable(self::URL_TABLE);
        $types = [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR];
        foreach (array_chunk($urls, self::CHUNK_SIZE, true) as $chunk) {
            $data = [];
            foreach ($chunk as $hash => $url) {
                $data[] = [$connection, (string)$hash, $url];
            }
            try {
                $database->bulkInsert(self::URL_TABLE, $data, ['connection', 'url_hash', 'url'], $types);
            } catch (UniqueConstraintViolationException) {
                // The statement failed as a whole: insert one by one
                foreach ($data as [$connectionUid, $hash, $url]) {
                    try {
                        $database->insert(self::URL_TABLE, ['connection' => $connectionUid, 'url_hash' => $hash, 'url' => $url], $types);
                    } catch (UniqueConstraintViolationException) {
                    }
                }
            }
        }
    }

    /**
     * Replaces the counts of the day. Runs on the given database connection,
     * inside the caller's transaction, which also marks the day imported
     * (ImportRepository::markImported()).
     *
     * @param int $day Ymd
     * @param array<string, int> $urlUids uid of each URL of the day, by URL hash, as returned by addUrls()
     */
    public function replaceDayFacts(Connection $database, int $connection, int $day, UrlDay $urlDay, array $urlUids): void
    {
        $database->delete(self::TABLE, ['connection' => $connection, 'day' => $day], [Connection::PARAM_INT, Connection::PARAM_INT]);

        $columns = ['connection', 'day', 'url', ...PageMetrics::COUNTS];
        $types = array_fill(0, count($columns), Connection::PARAM_INT);
        foreach (array_chunk($urlDay->rows, self::CHUNK_SIZE, true) as $chunk) {
            $data = [];
            foreach ($chunk as $hash => $row) {
                $uid = $urlUids[$hash] ?? throw new \InvalidArgumentException('URL ' . $row->url . ' has no uid; add it with addUrls() first', 1794500005);
                $data[] = [$connection, $day, $uid, ...array_values($row->counts)];
            }
            $database->bulkInsert(self::TABLE, $data, $columns, $types);
        }
    }

    /**
     * Counts summed per URL over the days $from to $to (Ymd, both included).
     *
     * @return array<int, array<string, int>> counts by column of PageMetrics::COUNTS, by URL uid
     */
    public function sumByUrl(int $connection, int $from, int $to): array
    {
        return $this->fetchSums($this->sumQuery($connection, $from, $to));
    }

    /**
     * Like sumByUrl(), for the given URLs only.
     *
     * @param list<int> $urlUids
     * @return array<int, array<string, int>>
     */
    public function sumByUrlFor(int $connection, int $from, int $to, array $urlUids): array
    {
        $sums = [];
        foreach (array_chunk($urlUids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->sumQuery($connection, $from, $to);
            $queryBuilder->andWhere($queryBuilder->expr()->in('url', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            $sums += $this->fetchSums($queryBuilder);
        }
        return $sums;
    }

    /**
     * Counts summed per page and language of the TYPO3 site over the days
     * $from to $to (Ymd, both included), by the URL mapping of the site
     * (UrlPageMapper). URLs that are not on the site or do not route to a
     * page are left out.
     *
     * @return array<int, array<int, array<string, int>>> counts by column of PageMetrics::COUNTS, by language ID, by page ID (default language)
     */
    public function sumByPage(string $site, int $connection, int $from, int $to): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE);
        $queryBuilder->select('p.page_id', 'p.language_id');
        foreach (PageMetrics::COUNTS as $column) {
            $queryBuilder->addSelectLiteral($queryBuilder->expr()->sum('d.' . $column, $column));
        }
        $result = $queryBuilder->from(self::TABLE, 'd')
            ->join(
                'd',
                UrlPageMapper::TABLE,
                'p',
                (string)$queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('p.url', $queryBuilder->quoteIdentifier('d.url')),
                    $queryBuilder->expr()->eq('p.site', $queryBuilder->createNamedParameter($site)),
                ),
            )
            ->where(
                $queryBuilder->expr()->eq('d.connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('d.day', $queryBuilder->createNamedParameter($from, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte('d.day', $queryBuilder->createNamedParameter($to, Connection::PARAM_INT)),
                // URLs not on the site (language_id -1) have no page either
                $queryBuilder->expr()->gt('p.page_id', $queryBuilder->createNamedParameter(UrlPageMapper::NO_PAGE, Connection::PARAM_INT)),
            )
            ->groupBy('p.page_id', 'p.language_id')
            ->executeQuery();

        $sums = [];
        while ($row = $result->fetchAssociative()) {
            foreach (PageMetrics::COUNTS as $column) {
                $sums[(int)$row['page_id']][(int)$row['language_id']][$column] = (int)$row[$column];
            }
        }
        return $sums;
    }

    /**
     * @param list<int> $urlUids
     * @return array<int, string> display URL by URL uid
     */
    public function displayUrls(array $urlUids): array
    {
        $urls = [];
        foreach (array_chunk($urlUids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->queryBuilder(self::URL_TABLE);
            $result = $queryBuilder->select('uid', 'url')
                ->from(self::URL_TABLE)
                ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $urls[(int)$row['uid']] = (string)$row['url'];
            }
        }
        return $urls;
    }

    /**
     * @param list<string> $hashes URL hashes (UrlDayRows::hash())
     * @return array<string, array{uid: int, url: string}> uid and display URL of the known ones, by URL hash
     */
    public function findUrls(int $connection, array $hashes): array
    {
        $urls = [];
        foreach (array_chunk($hashes, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->queryBuilder(self::URL_TABLE);
            $result = $queryBuilder->select('uid', 'url_hash', 'url')
                ->from(self::URL_TABLE)
                ->where(
                    $queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                    $queryBuilder->expr()->in('url_hash', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_STR_ARRAY)),
                )
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $urls[(string)$row['url_hash']] = ['uid' => (int)$row['uid'], 'url' => (string)$row['url']];
            }
        }
        return $urls;
    }

    private function newestDay(int $connection): int
    {
        $queryBuilder = $this->queryBuilder(self::TABLE);
        return (int)$queryBuilder->selectLiteral($queryBuilder->expr()->max('day'))
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    private function sumQuery(int $connection, int $from, int $to): QueryBuilder
    {
        $queryBuilder = $this->queryBuilder(self::TABLE);
        $queryBuilder->select('url');
        foreach (PageMetrics::COUNTS as $column) {
            $queryBuilder->addSelectLiteral($queryBuilder->expr()->sum($column, $column));
        }
        return $queryBuilder->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('day', $queryBuilder->createNamedParameter($from, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte('day', $queryBuilder->createNamedParameter($to, Connection::PARAM_INT)),
            )
            ->groupBy('url');
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function fetchSums(QueryBuilder $queryBuilder): array
    {
        $sums = [];
        $result = $queryBuilder->executeQuery();
        while ($row = $result->fetchAssociative()) {
            foreach (PageMetrics::COUNTS as $column) {
                $sums[(int)$row['url']][$column] = (int)$row[$column];
            }
        }
        return $sums;
    }

    private function queryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder;
    }
}
