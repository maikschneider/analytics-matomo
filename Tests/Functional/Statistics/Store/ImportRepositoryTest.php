<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Statistics\Store\Coverage;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDay;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ImportRepositoryTest extends FunctionalTestCase
{
    private const NOW = '2026-11-23 14:30:00';

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];

    #[Test]
    public function markImportedStoresTheDay(): void
    {
        $this->markImported(1, 20261120, 1000, self::day(10, 0, 2));

        self::assertSame([['connection' => 1, 'day' => 20261120, 'imported_at' => 1000, 'total_hits' => 10, 'summary_hits' => 0, 'row_count' => 2]], $this->importRows());
    }

    #[Test]
    public function markImportedUpdatesAnImportedDay(): void
    {
        $this->markImported(1, 20261120, 1000, self::day(10, 0, 2));

        $this->markImported(1, 20261120, 2000, self::day(12, 3, 3));

        self::assertSame([['connection' => 1, 'day' => 20261120, 'imported_at' => 2000, 'total_hits' => 15, 'summary_hits' => 3, 'row_count' => 3]], $this->importRows());
    }

    #[Test]
    public function markImportedWithUnchangedValuesKeepsTheDay(): void
    {
        $this->markImported(1, 20261120, 2000, self::day(12, 3, 3));

        // MySQL reports no affected rows: the insert that follows hits the primary key
        $database = $this->getConnectionPool()->getConnectionForTable(ImportRepository::TABLE);
        $database->transactional(fn (Connection $database) => $this->repository()->markImported($database, 1, 20261120, 2000, self::day(12, 3, 3)));

        self::assertSame([['connection' => 1, 'day' => 20261120, 'imported_at' => 2000, 'total_hits' => 15, 'summary_hits' => 3, 'row_count' => 3]], $this->importRows());
    }

    #[Test]
    public function markImportedRunsInTheTransactionOfTheCaller(): void
    {
        try {
            $this->getConnectionPool()->getConnectionForTable(ImportRepository::TABLE)->transactional(function (Connection $database): never {
                $this->repository()->markImported($database, 1, 20261120, 1000, self::day(1));
                throw new \RuntimeException('Import failed', 1);
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->importRows());
    }

    #[Test]
    public function coverageRejectsARangeEndingBeforeItStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1794500004);

        $this->repository()->coverage(1, 20261123, 20261122, self::now());
    }

    #[Test]
    public function coverageOfAConnectionWithoutImports(): void
    {
        $coverage = $this->repository()->coverage(1, 20261117, 20261123, self::now());

        self::assertEquals(new Coverage(false, false, null, null, false, 0, 0), $coverage);
    }

    #[Test]
    public function coverageOfACompleteRangeBeforeToday(): void
    {
        $repository = $this->repository();
        $this->markDays(1, 20261110, 20261123, self::timestamp('2026-11-23 14:00:00'));
        $this->markImported(1, 20261116, self::timestamp('2026-11-17 03:00:00'), self::day(10, 4, 2));
        $this->markImported(1, 20261122, self::timestamp('2026-11-23 02:10:00'), self::day(7, 0, 1));
        $this->markDays(2, 20261101, 20261123, self::timestamp('2026-11-23 14:00:00'));

        $coverage = $repository->coverage(1, 20261116, 20261122, self::now());

        self::assertTrue($coverage->hasStore);
        self::assertTrue($coverage->isComplete);
        self::assertSame(20261110, $coverage->oldestDay);
        // The import of the newest day of the range
        self::assertSame('2026-11-23 02:10', $coverage->asOf?->format('Y-m-d H:i'));
        self::assertFalse($coverage->isStale);
        // 5 days × 1 hit + 14 + 7
        self::assertSame(26, $coverage->totalHits);
        self::assertSame(4, $coverage->summaryHits);
        self::assertTrue($coverage->isTruncated());
    }

    #[Test]
    public function coverageWithAGapIsNotComplete(): void
    {
        $repository = $this->repository();
        $this->markDays(1, 20261116, 20261118, self::timestamp('2026-11-23 14:00:00'));
        $this->markDays(1, 20261120, 20261122, self::timestamp('2026-11-23 14:00:00'));

        $coverage = $repository->coverage(1, 20261116, 20261122, self::now());

        self::assertTrue($coverage->hasStore);
        self::assertFalse($coverage->isComplete);
        self::assertSame(6, $coverage->totalHits);
    }

    #[Test]
    public function coverageOfARangeBeforeTheOldestDay(): void
    {
        $this->markDays(1, 20261120, 20261123, self::timestamp('2026-11-23 14:00:00'));

        $coverage = $this->repository()->coverage(1, 20261001, 20261031, self::now());

        self::assertTrue($coverage->hasStore);
        self::assertFalse($coverage->isComplete);
        self::assertSame(20261120, $coverage->oldestDay);
        self::assertNull($coverage->asOf);
        self::assertFalse($coverage->isStale);
    }

    #[Test]
    public function coverageWithTodayImportedRecentlyIsNotStale(): void
    {
        $this->markDays(1, 20261117, 20261123, self::timestamp('2026-11-23 12:31:00'));

        $coverage = $this->repository()->coverage(1, 20261117, 20261123, self::now());

        self::assertTrue($coverage->isComplete);
        self::assertFalse($coverage->isStale);
        self::assertSame('12:31', $coverage->asOf?->format('H:i'));
    }

    #[Test]
    public function coverageWithTodayImportedMoreThanTwoHoursAgoIsStale(): void
    {
        $this->markDays(1, 20261117, 20261123, self::timestamp('2026-11-23 12:29:00'));

        $coverage = $this->repository()->coverage(1, 20261117, 20261123, self::now());

        self::assertTrue($coverage->isComplete);
        self::assertTrue($coverage->isStale);
        self::assertSame('12:29', $coverage->asOf?->format('H:i'));
    }

    #[Test]
    public function coverageWithTodayMissingIsStale(): void
    {
        $this->markDays(1, 20261117, 20261122, self::timestamp('2026-11-23 03:00:00'));

        $coverage = $this->repository()->coverage(1, 20261117, 20261123, self::now());

        self::assertFalse($coverage->isComplete);
        self::assertTrue($coverage->isStale);
        self::assertSame('2026-11-23 03:00', $coverage->asOf?->format('Y-m-d H:i'));
    }

    #[Test]
    public function notFinalDaysAreTodayAndYesterdayUntilImportedTwoHoursAfterTheirEnd(): void
    {
        $repository = $this->repository();
        // Nothing imported yet
        self::assertSame([20261123, 20261122], $repository->notFinalDays(1, self::now()));

        $this->markImported(1, 20261123, self::timestamp('2026-11-23 14:00:00'), self::day(1));
        $this->markImported(1, 20261122, self::timestamp('2026-11-23 01:59:59'), self::day(1));
        $this->markImported(1, 20261121, self::timestamp('2026-11-21 23:00:00'), self::day(1));
        // Today is never final; yesterday was imported before 02:00. Older days
        // (20261121, imported before its end) are left to missingDays() and --from/--to
        self::assertSame([20261123, 20261122], $repository->notFinalDays(1, self::now()));

        $this->markImported(1, 20261122, self::timestamp('2026-11-23 02:00:00'), self::day(1));
        self::assertSame([20261123], $repository->notFinalDays(1, self::now()));
        // Another connection
        self::assertSame([20261123, 20261122], $repository->notFinalDays(2, self::now()));
    }

    #[Test]
    public function notFinalDaysUseTheTimezoneOfTheClock(): void
    {
        $repository = $this->repository();
        // 00:30 in Berlin is the day before in UTC
        $now = new \DateTimeImmutable('2026-11-23 00:30:00', new \DateTimeZone('Europe/Berlin'));
        $this->markImported(1, 20261122, $now->getTimestamp(), self::day(1));

        self::assertSame([20261123, 20261122], $repository->notFinalDays(1, $now));
        self::assertSame([20261122, 20261121], $repository->notFinalDays(1, $now->setTimezone(new \DateTimeZone('UTC'))));
    }

    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function daylightSavingTimeChanges(): iterable
    {
        // Yesterday is final two hours after its end, in seconds, not on the wall clock
        yield 'spring, before the change' => ['2026-03-29 01:30', 20260329, 20260328, '2026-03-29 01:00:00'];
        yield 'spring, after the change' => ['2026-03-29 23:30', 20260329, 20260328, '2026-03-29 01:00:00'];
        yield 'spring, day after the 23 hour day' => ['2026-03-30 01:30', 20260330, 20260329, '2026-03-30 00:00:00'];
        yield 'autumn, before the change' => ['2026-10-25 01:30', 20261025, 20261024, '2026-10-25 00:00:00'];
        yield 'autumn, after the change' => ['2026-10-25 23:30', 20261025, 20261024, '2026-10-25 00:00:00'];
        yield 'autumn, day after the 25 hour day' => ['2026-10-26 01:30', 20261026, 20261025, '2026-10-26 01:00:00'];
    }

    #[Test]
    #[DataProvider('daylightSavingTimeChanges')]
    public function notFinalDaysAcrossDaylightSavingTimeChanges(string $now, int $today, int $yesterday, string $finalFromUtc): void
    {
        $repository = $this->repository();
        $now = new \DateTimeImmutable($now, new \DateTimeZone('Europe/Berlin'));
        $finalFrom = (new \DateTimeImmutable($finalFromUtc, new \DateTimeZone('UTC')))->getTimestamp();
        self::assertSame([$today, $yesterday], $repository->notFinalDays(1, $now));

        $this->markImported(1, $today, $now->getTimestamp(), self::day(1));
        $this->markImported(1, $yesterday, $finalFrom - 1, self::day(1));
        self::assertSame([$today, $yesterday], $repository->notFinalDays(1, $now));

        $this->markImported(1, $yesterday, $finalFrom, self::day(1));
        self::assertSame([$today], $repository->notFinalDays(1, $now));
    }

    #[Test]
    public function missingDaysAreListedNewestFirst(): void
    {
        $repository = $this->repository();
        $this->markDays(1, 20261117, 20261118, 1000);
        $this->markDays(1, 20261121, 20261121, 1000);
        $this->markDays(2, 20261101, 20261130, 1000);

        self::assertSame([20261123, 20261122, 20261120, 20261119, 20261116], $repository->missingDays(1, 20261116, 20261123));
        self::assertSame([], $repository->missingDays(2, 20261116, 20261123));
        self::assertSame([20261202, 20261201], $repository->missingDays(2, 20261130, 20261202));
    }

    #[Test]
    public function purgeBeforeDeletesOldDaysAndOrphanedUrls(): void
    {
        $repository = $this->repository();
        $old = $this->replaceDay(1, 20261001, self::urls(['https://example.org/old', 'https://example.org/']));
        $kept = $this->replaceDay(1, 20261120, self::urls(['https://example.org/', 'https://example.org/new']));
        $other = $this->replaceDay(2, 20261001, self::urls(['https://example.org/old']));
        $this->markImported(1, 20261001, 1000, self::day(1));
        $this->markImported(1, 20261120, 1000, self::day(1));
        $this->markImported(2, 20261001, 1000, self::day(1));
        $mapping = $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_url_page');
        foreach (array_unique([...array_values($old), ...array_values($kept), ...array_values($other)]) as $url) {
            foreach (['main', 'shop'] as $site) {
                $mapping->insert('tx_analyticsmatomo_url_page', ['site' => $site, 'url' => $url, 'language_id' => 0, 'page_id' => 1]);
            }
        }

        $repository->purgeBefore(1, 20261101);

        self::assertSame([20261120], $this->column('tx_analyticsmatomo_import', 'day', 1));
        self::assertSame([20261120], array_values(array_unique($this->column('tx_analyticsmatomo_url_day', 'day', 1))));
        $urls = [$kept[UrlDayRows::hash('https://example.org/')], $kept[UrlDayRows::hash('https://example.org/new')]];
        sort($urls);
        self::assertSame($urls, $this->column('tx_analyticsmatomo_url', 'uid', 1));
        self::assertNotContains($old[UrlDayRows::hash('https://example.org/old')], $this->column('tx_analyticsmatomo_url_page', 'url'));
        self::assertCount(6, $this->column('tx_analyticsmatomo_url_page', 'url'));
        // Other connections keep their days
        self::assertSame([20261001], $this->column('tx_analyticsmatomo_import', 'day', 2));
        self::assertSame([$other[UrlDayRows::hash('https://example.org/old')]], $this->column('tx_analyticsmatomo_url', 'uid', 2));
    }

    #[Test]
    public function overviewOfAConnectionWithoutImportsIsNull(): void
    {
        $this->markImported(2, 20261120, 1000, self::day(1));

        self::assertNull($this->repository()->overview(1));
    }

    #[Test]
    public function overviewHasTheStoredRangeLastImportAndTruncatedDays(): void
    {
        $this->markImported(1, 20261118, 3000, self::day(10, 2));
        $this->markImported(1, 20261120, 2000, self::day(10));
        $this->markImported(1, 20261119, 1000, self::day(10, 1));
        $this->markImported(2, 20261121, 9000, self::day(10, 1));

        $overview = $this->repository()->overview(1);

        self::assertNotNull($overview);
        self::assertSame([20261118, 20261120, 3000, 2], [$overview->oldestDay, $overview->newestDay, $overview->lastImportedAt, $overview->truncatedDays]);
    }

    private function markDays(int $connection, int $from, int $to, int $importedAt): void
    {
        for ($date = new \DateTimeImmutable((string)$from); (int)$date->format('Ymd') <= $to; $date = $date->modify('+1 day')) {
            $this->markImported($connection, (int)$date->format('Ymd'), $importedAt, self::day(1));
        }
    }

    /**
     * @return array<string, int> uid by URL hash
     */
    private function replaceDay(int $connection, int $day, UrlDay $urlDay): array
    {
        $urlDays = new UrlDayRepository($this->getConnectionPool());
        $uids = $urlDays->addUrls($connection, $day, $urlDay);
        $this->getConnectionPool()->getConnectionForTable(UrlDayRepository::TABLE)->transactional(
            static fn (Connection $database) => $urlDays->replaceDayFacts($database, $connection, $day, $urlDay, $uids),
        );
        return $uids;
    }

    private function markImported(int $connection, int $day, int $importedAt, UrlDay $urlDay): void
    {
        $this->repository()->markImported($this->getConnectionPool()->getConnectionForTable(ImportRepository::TABLE), $connection, $day, $importedAt, $urlDay);
    }

    /**
     * @return list<array<string, int>>
     */
    private function importRows(): array
    {
        return array_map(
            static fn (array $row): array => array_map(intval(...), $row),
            $this->getConnectionPool()->getConnectionForTable(ImportRepository::TABLE)->select(['*'], ImportRepository::TABLE)->fetchAllAssociative(),
        );
    }

    /**
     * @return list<int>
     */
    private function column(string $table, string $column, ?int $connection = null): array
    {
        $values = array_map(intval(...), $this->getConnectionPool()->getConnectionForTable($table)
            ->select([$column], $table, $connection === null ? [] : ['connection' => $connection], [], [$column => 'ASC'])
            ->fetchFirstColumn());
        return $values;
    }

    private static function day(int $hits, int $summaryHits = 0, int $rows = 1): UrlDay
    {
        $urlDay = UrlDayRows::fromReport(array_map(static fn (int $i): array => ['url' => 'https://example.org/' . $i, 'nb_hits' => 0], range(1, $rows)));
        return new UrlDay($urlDay->rows, $hits + $summaryHits, $summaryHits);
    }

    /**
     * @param list<string> $urls
     */
    private static function urls(array $urls): UrlDay
    {
        return UrlDayRows::fromReport(array_map(static fn (string $url): array => ['url' => $url, 'nb_hits' => 1], $urls));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Berlin'));
    }

    private static function timestamp(string $time): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    private function repository(): ImportRepository
    {
        return new ImportRepository($this->getConnectionPool());
    }
}
