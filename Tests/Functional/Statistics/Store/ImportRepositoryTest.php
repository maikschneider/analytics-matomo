<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Statistics\Store\Coverage;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDay;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ImportRepositoryTest extends FunctionalTestCase
{
    private const NOW = '2026-11-23 14:30:00';

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];

    #[Test]
    public function markImportedStoresTheDayAndReplacesIt(): void
    {
        $repository = $this->repository();

        $repository->markImported(1, 20261120, 1000, self::day(10, 0, 2));
        $repository->markImported(1, 20261120, 2000, self::day(12, 3, 3));
        // Unchanged values must not insert a second row (MySQL reports no affected rows)
        $repository->markImported(1, 20261120, 2000, self::day(12, 3, 3));

        $rows = $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_import')->select(['*'], 'tx_analyticsmatomo_import')->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame(
            ['connection' => 1, 'day' => 20261120, 'imported_at' => 2000, 'total_hits' => 15, 'summary_hits' => 3, 'row_count' => 3],
            array_map(intval(...), $rows[0]),
        );
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
        $repository->markImported(1, 20261116, self::timestamp('2026-11-17 03:00:00'), self::day(10, 4, 2));
        $repository->markImported(1, 20261122, self::timestamp('2026-11-23 02:10:00'), self::day(7, 0, 1));
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

        $repository->markImported(1, 20261123, self::timestamp('2026-11-23 14:00:00'), self::day(1));
        $repository->markImported(1, 20261122, self::timestamp('2026-11-23 01:59:59'), self::day(1));
        $repository->markImported(1, 20261121, self::timestamp('2026-11-21 23:00:00'), self::day(1));
        // Today is never final; yesterday was imported before 02:00. Older days
        // (20261121, imported before its end) are left to missingDays() and --from/--to
        self::assertSame([20261123, 20261122], $repository->notFinalDays(1, self::now()));

        $repository->markImported(1, 20261122, self::timestamp('2026-11-23 02:00:00'), self::day(1));
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
        $repository->markImported(1, 20261122, $now->getTimestamp(), self::day(1));

        self::assertSame([20261123, 20261122], $repository->notFinalDays(1, $now));
        self::assertSame([20261122, 20261121], $repository->notFinalDays(1, $now->setTimezone(new \DateTimeZone('UTC'))));
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
        $urlDays = new UrlDayRepository($this->getConnectionPool());
        $repository = $this->repository();
        $old = $urlDays->replaceDay(1, 20261001, self::urls(['https://example.org/old', 'https://example.org/']));
        $kept = $urlDays->replaceDay(1, 20261120, self::urls(['https://example.org/', 'https://example.org/new']));
        $other = $urlDays->replaceDay(2, 20261001, self::urls(['https://example.org/old']));
        $repository->markImported(1, 20261001, 1000, self::day(1));
        $repository->markImported(1, 20261120, 1000, self::day(1));
        $repository->markImported(2, 20261001, 1000, self::day(1));
        $mapping = $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_url_page');
        foreach (array_unique([...array_values($old), ...array_values($kept), ...array_values($other)]) as $url) {
            foreach (['main', 'shop'] as $site) {
                $mapping->insert('tx_analyticsmatomo_url_page', ['site' => $site, 'url' => $url, 'language_id' => 0, 'page_id' => 1]);
            }
        }

        $repository->purgeBefore(1, 20261101);

        self::assertSame([20261120], $this->column('tx_analyticsmatomo_import', 'day', 1));
        self::assertSame([20261120], array_values(array_unique($this->column('tx_analyticsmatomo_url_day', 'day', 1))));
        $urls = [$kept[sha1('example.org/')], $kept[sha1('example.org/new')]];
        sort($urls);
        self::assertSame($urls, $this->column('tx_analyticsmatomo_url', 'uid', 1));
        self::assertNotContains($old[sha1('example.org/old')], $this->column('tx_analyticsmatomo_url_page', 'url'));
        self::assertCount(6, $this->column('tx_analyticsmatomo_url_page', 'url'));
        // Other connections keep their days
        self::assertSame([20261001], $this->column('tx_analyticsmatomo_import', 'day', 2));
        self::assertSame([$other[sha1('example.org/old')]], $this->column('tx_analyticsmatomo_url', 'uid', 2));
    }

    private function markDays(int $connection, int $from, int $to, int $importedAt): void
    {
        for ($date = new \DateTimeImmutable((string)$from); (int)$date->format('Ymd') <= $to; $date = $date->modify('+1 day')) {
            $this->repository()->markImported($connection, (int)$date->format('Ymd'), $importedAt, self::day(1));
        }
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
