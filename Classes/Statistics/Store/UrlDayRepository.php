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
     * Replaces the counts of the day in one transaction; URLs new to the
     * connection are added to the dictionary. The display URL of known URLs
     * follows the newest imported day, so a backfill of older days does not
     * bring back an outdated variant.
     *
     * @param int $day Ymd
     * @return array<string, int> uid of each URL of the day, by URL hash
     */
    public function replaceDay(int $connection, int $day, UrlDay $urlDay): array
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)->transactional(function (Connection $database) use ($connection, $day, $urlDay): array {
            $isNewestDay = $day >= $this->newestDay($connection);
            $database->delete(self::TABLE, ['connection' => $connection, 'day' => $day], [Connection::PARAM_INT, Connection::PARAM_INT]);
            $uids = $this->addUrls($connection, $urlDay, $isNewestDay);

            $columns = ['connection', 'day', 'url', ...PageMetrics::COUNTS];
            $types = array_fill(0, count($columns), Connection::PARAM_INT);
            foreach (array_chunk($urlDay->rows, self::CHUNK_SIZE, true) as $chunk) {
                $data = [];
                foreach ($chunk as $hash => $row) {
                    $data[] = [$connection, $day, $uids[$hash], ...array_values($row->counts)];
                }
                $database->bulkInsert(self::TABLE, $data, $columns, $types);
            }
            return $uids;
        });
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
     * @return array<string, int> uid by URL hash
     */
    private function addUrls(int $connection, UrlDay $urlDay, bool $updateDisplayUrls): array
    {
        $database = $this->connectionPool->getConnectionForTable(self::URL_TABLE);
        $hashes = array_map(strval(...), array_keys($urlDay->rows));
        $known = $this->findUrls($connection, $hashes);

        if ($updateDisplayUrls) {
            foreach ($known as $hash => $url) {
                if ($url['url'] !== $urlDay->rows[$hash]->url) {
                    $database->update(self::URL_TABLE, ['url' => $urlDay->rows[$hash]->url], ['uid' => $url['uid']], [Connection::PARAM_STR, Connection::PARAM_INT]);
                }
            }
        }

        $missing = array_values(array_diff($hashes, array_keys($known)));
        if ($missing !== []) {
            foreach (array_chunk($missing, self::CHUNK_SIZE) as $chunk) {
                $data = array_map(static fn (string $hash): array => [$connection, $hash, $urlDay->rows[$hash]->url], $chunk);
                try {
                    $database->bulkInsert(self::URL_TABLE, $data, ['connection', 'url_hash', 'url'], [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR]);
                } catch (UniqueConstraintViolationException) {
                    // Another process added some of them meanwhile; the statement failed as a whole
                    $this->insertEach($connection, $data);
                }
            }
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
     * @param list<array{int, string, string}> $data
     */
    private function insertEach(int $connection, array $data): void
    {
        $database = $this->connectionPool->getConnectionForTable(self::URL_TABLE);
        foreach ($data as [$connectionUid, $hash, $url]) {
            try {
                $database->insert(self::URL_TABLE, ['connection' => $connectionUid, 'url_hash' => $hash, 'url' => $url], [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR]);
            } catch (UniqueConstraintViolationException) {
            }
        }
    }

    /**
     * @param list<string> $hashes
     * @return array<string, array{uid: int, url: string}> by URL hash
     */
    private function findUrls(int $connection, array $hashes): array
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
