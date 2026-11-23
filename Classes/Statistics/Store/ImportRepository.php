<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Which days of a connection are imported, when, and with which report totals.
 * Days are integers (Ymd) in the timezone of the clock passed in.
 *
 * Callers that write (markImported(), purgeBefore()) must hold the import lock
 * of the connection: the writes are not safe against a concurrent import.
 */
final readonly class ImportRepository
{
    public const TABLE = 'tx_analyticsmatomo_import';
    private const URL_PAGE_TABLE = 'tx_analyticsmatomo_url_page';

    /**
     * Today's import is stale after this many seconds.
     */
    public const STALE_AFTER = 7200;

    /**
     * A day is final once imported this many seconds after its end: Matomo
     * archives the last visits of a day a while after midnight.
     */
    public const FINAL_AFTER = 7200;

    private const CHUNK_SIZE = 1000;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Runs on the given database connection, so that it can share the
     * transaction that replaces the counts of the day (UrlDayRepository::replaceDayFacts()).
     */
    public function markImported(Connection $database, int $connection, int $day, int $importedAt, UrlDay $urlDay): void
    {
        $values = ['imported_at' => $importedAt, 'total_hits' => $urlDay->totalHits, 'summary_hits' => $urlDay->summaryHits, 'row_count' => count($urlDay->rows)];
        $identifier = ['connection' => $connection, 'day' => $day];
        $types = array_fill(0, 6, Connection::PARAM_INT);
        // MySQL counts unchanged rows as not affected; the insert then fails on
        // the primary key, which does not abort a transaction there. Other
        // platforms count matched rows, so under the import lock they do not get here.
        if ($database->update(self::TABLE, $values, $identifier, $types) > 0) {
            return;
        }
        try {
            $database->insert(self::TABLE, $identifier + $values, $types);
        } catch (UniqueConstraintViolationException) {
            $database->update(self::TABLE, $values, $identifier, $types);
        }
    }

    /**
     * @param int $from first day of the range (Ymd)
     * @param int $to last day of the range (Ymd)
     */
    public function coverage(int $connection, int $from, int $to, \DateTimeImmutable $now): Coverage
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('The range ends (' . $to . ') before it starts (' . $from . ')', 1794500004);
        }
        $queryBuilder = $this->queryBuilder(self::TABLE);
        $oldestDay = $queryBuilder->selectLiteral($queryBuilder->expr()->min('day'))
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        if ($oldestDay === null || $oldestDay === false) {
            return new Coverage(false, false, null, null, false, 0, 0);
        }

        $days = [];
        foreach ($this->rangeQuery($connection, $from, $to)->select('day', 'imported_at', 'total_hits', 'summary_hits')->executeQuery()->iterateAssociative() as $row) {
            $days[(int)$row['day']] = array_map(intval(...), $row);
        }
        krsort($days);
        $newest = $days === [] ? null : reset($days);
        $today = Day::fromDate($now);
        $isStale = $from <= $today && $today <= $to
            && (!isset($days[$today]) || $days[$today]['imported_at'] < $now->getTimestamp() - self::STALE_AFTER);

        return new Coverage(
            hasStore: true,
            isComplete: count($days) === count(Day::range($from, $to)),
            oldestDay: (int)$oldestDay,
            asOf: $newest === null ? null : (new \DateTimeImmutable('@' . $newest['imported_at']))->setTimezone($now->getTimezone()),
            isStale: $isStale,
            totalHits: array_sum(array_column($days, 'total_hits')),
            summaryHits: array_sum(array_column($days, 'summary_hits')),
        );
    }

    /**
     * Today and yesterday, unless imported at least FINAL_AFTER seconds after
     * the end of the day (today never is). Newest first.
     *
     * @return list<int>
     */
    public function notFinalDays(int $connection, \DateTimeImmutable $now): array
    {
        $today = Day::toDate(Day::fromDate($now), $now->getTimezone());
        $candidates = [Day::fromDate($today), Day::fromDate($today->modify('-1 day'))];
        $importedAt = [];
        $queryBuilder = $this->queryBuilder(self::TABLE);
        $result = $queryBuilder->select('day', 'imported_at')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('day', $queryBuilder->createNamedParameter($candidates, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $importedAt[(int)$row['day']] = (int)$row['imported_at'];
        }

        return array_values(array_filter(
            $candidates,
            static fn (int $day): bool => ($importedAt[$day] ?? 0) < Day::toDate($day, $now->getTimezone())->modify('+1 day')->getTimestamp() + self::FINAL_AFTER,
        ));
    }

    /**
     * The newest imported day (Ymd), null if none.
     */
    public function newestDay(int $connection): ?int
    {
        $queryBuilder = $this->queryBuilder(self::TABLE);
        $day = $queryBuilder->selectLiteral($queryBuilder->expr()->max('day'))
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        return $day === null || $day === false ? null : (int)$day;
    }

    /**
     * Days of the range without import, newest first.
     *
     * @return list<int>
     */
    public function missingDays(int $connection, int $from, int $to): array
    {
        $imported = array_map(intval(...), $this->rangeQuery($connection, $from, $to)->select('day')->executeQuery()->fetchFirstColumn());
        return array_values(array_diff(Day::range($from, $to), $imported));
    }

    /**
     * Deletes the days before $day of the connection, then the URLs no day
     * points to anymore and their page mappings.
     */
    public function purgeBefore(int $connection, int $day): void
    {
        foreach ([self::TABLE, UrlDayRepository::TABLE] as $table) {
            $queryBuilder = $this->queryBuilder($table);
            $queryBuilder->delete($table)
                ->where(
                    $queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                    $queryBuilder->expr()->lt('day', $queryBuilder->createNamedParameter($day, Connection::PARAM_INT)),
                )
                ->executeStatement();
        }

        $queryBuilder = $this->queryBuilder(UrlDayRepository::URL_TABLE);
        $orphans = array_map(intval(...), $queryBuilder->select('u.uid')
            ->from(UrlDayRepository::URL_TABLE, 'u')
            ->leftJoin(
                'u',
                UrlDayRepository::TABLE,
                'd',
                (string)$queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('d.connection', $queryBuilder->quoteIdentifier('u.connection')),
                    $queryBuilder->expr()->eq('d.url', $queryBuilder->quoteIdentifier('u.uid')),
                ),
            )
            ->where(
                $queryBuilder->expr()->eq('u.connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                $queryBuilder->expr()->isNull('d.url'),
            )
            ->executeQuery()
            ->fetchFirstColumn());

        foreach (array_chunk($orphans, self::CHUNK_SIZE) as $chunk) {
            foreach ([self::URL_PAGE_TABLE => 'url', UrlDayRepository::URL_TABLE => 'uid'] as $table => $column) {
                $queryBuilder = $this->queryBuilder($table);
                $queryBuilder->delete($table)
                    ->where($queryBuilder->expr()->in($column, $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                    ->executeStatement();
            }
        }
    }

    private function rangeQuery(int $connection, int $from, int $to): QueryBuilder
    {
        $queryBuilder = $this->queryBuilder(self::TABLE);
        return $queryBuilder->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('day', $queryBuilder->createNamedParameter($from, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte('day', $queryBuilder->createNamedParameter($to, Connection::PARAM_INT)),
            );
    }

    private function queryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder;
    }
}
