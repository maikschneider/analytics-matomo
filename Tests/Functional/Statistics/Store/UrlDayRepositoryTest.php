<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDay;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UrlDayRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];

    #[Test]
    public function importStoresTheUrlsAndTheirCounts(): void
    {
        $uids = $this->replaceDay(1, 20261120, self::day([
            'https://example.org/' => [5, 4, 30, 3, 2, 1],
            'https://example.org/news' => [2, 2, 10, 1, 1, 1],
        ]));

        self::assertSame([UrlDayRows::hash('https://example.org/'), UrlDayRows::hash('https://example.org/news')], array_keys($uids));
        self::assertSame([
            $uids[UrlDayRows::hash('https://example.org/')] => ['nb_hits' => 5, 'nb_visits' => 4, 'sum_time_spent' => 30, 'entry_nb_visits' => 3, 'entry_bounce_count' => 2, 'exit_nb_visits' => 1],
            $uids[UrlDayRows::hash('https://example.org/news')] => ['nb_hits' => 2, 'nb_visits' => 2, 'sum_time_spent' => 10, 'entry_nb_visits' => 1, 'entry_bounce_count' => 1, 'exit_nb_visits' => 1],
        ], $this->repository()->sumByUrl(1, 20261120, 20261120));
        self::assertSame([
            $uids[UrlDayRows::hash('https://example.org/')] => 'https://example.org/',
            $uids[UrlDayRows::hash('https://example.org/news')] => 'https://example.org/news',
        ], $this->repository()->displayUrls(array_values($uids)));
    }

    #[Test]
    public function replaceDayTwiceReplacesTheCountsOfThatDay(): void
    {
        $first = $this->replaceDay(1, 20261120, self::day([
            'https://example.org/' => [5],
            'https://example.org/old' => [1],
        ]));

        $second = $this->replaceDay(1, 20261120, self::day([
            'https://example.org/' => [7],
            'https://example.org/new' => [2],
        ]));

        // Known URLs keep their uid
        self::assertSame($first[UrlDayRows::hash('https://example.org/')], $second[UrlDayRows::hash('https://example.org/')]);
        self::assertSame([
            $second[UrlDayRows::hash('https://example.org/')] => 7,
            $second[UrlDayRows::hash('https://example.org/new')] => 2,
        ], array_map(static fn (array $counts): int => $counts['nb_hits'], $this->repository()->sumByUrl(1, 20261120, 20261120)));
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_url_day')->count('*', 'tx_analyticsmatomo_url_day', []));
        // The URL of the replaced row stays in the dictionary until the purge
        self::assertSame(3, $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_url')->count('*', 'tx_analyticsmatomo_url', []));
    }

    #[Test]
    public function replaceDayKeepsOtherDays(): void
    {
        $uids = $this->replaceDay(1, 20261119, self::day(['https://example.org/' => [3]]));
        $this->replaceDay(1, 20261120, self::day(['https://example.org/' => [4]]));
        $this->replaceDay(1, 20261121, self::day(['https://example.org/' => [5]]));

        $this->replaceDay(1, 20261120, self::day([]));

        self::assertSame([$uids[UrlDayRows::hash('https://example.org/')] => 8], self::hits($this->repository()->sumByUrl(1, 20261119, 20261121)));
        self::assertSame([$uids[UrlDayRows::hash('https://example.org/')] => 3], self::hits($this->repository()->sumByUrl(1, 20261119, 20261120)));
        self::assertSame([], $this->repository()->sumByUrl(1, 20261120, 20261120));
    }

    #[Test]
    public function connectionsDoNotMix(): void
    {
        $one = $this->replaceDay(1, 20261120, self::day(['https://example.org/' => [5], 'https://example.org/a' => [1]]));
        $two = $this->replaceDay(2, 20261120, self::day(['https://example.org/' => [9]]));
        $this->replaceDay(2, 20261120, self::day(['https://example.org/' => [8]]));

        // The same URL has a uid per connection
        self::assertNotSame($one[UrlDayRows::hash('https://example.org/')], $two[UrlDayRows::hash('https://example.org/')]);
        self::assertSame([$one[UrlDayRows::hash('https://example.org/')] => 5, $one[UrlDayRows::hash('https://example.org/a')] => 1], self::hits($this->repository()->sumByUrl(1, 20261101, 20261130)));
        self::assertSame([$two[UrlDayRows::hash('https://example.org/')] => 8], self::hits($this->repository()->sumByUrl(2, 20261101, 20261130)));
    }

    #[Test]
    public function sumByUrlForSumsTheGivenUrlsOnly(): void
    {
        $uids = $this->replaceDay(1, 20261119, self::day(['https://example.org/' => [3, 1], 'https://example.org/a' => [2], 'https://example.org/b' => [1]]));
        $this->replaceDay(1, 20261120, self::day(['https://example.org/' => [4, 2], 'https://example.org/b' => [6]]));
        $other = $this->replaceDay(2, 20261120, self::day(['https://example.org/c' => [9]]));

        $sums = $this->repository()->sumByUrlFor(1, 20261119, 20261120, [$uids[UrlDayRows::hash('https://example.org/')], $uids[UrlDayRows::hash('https://example.org/b')], $other[UrlDayRows::hash('https://example.org/c')]]);

        self::assertSame([$uids[UrlDayRows::hash('https://example.org/')] => 7, $uids[UrlDayRows::hash('https://example.org/b')] => 7], self::hits($sums));
        self::assertSame(3, $sums[$uids[UrlDayRows::hash('https://example.org/')]]['nb_visits']);
        self::assertSame([], $this->repository()->sumByUrlFor(1, 20261119, 20261120, []));
    }

    #[Test]
    public function displayUrlFollowsTheNewestDay(): void
    {
        $uids = $this->replaceDay(1, 20261120, self::day(['https://example.org/a' => [3]]));
        $uid = $uids[UrlDayRows::hash('https://example.org/a')];

        // A backfilled older day does not change it
        $this->replaceDay(1, 20261119, self::day(['http://example.org/a' => [3]]));
        self::assertSame([$uid => 'https://example.org/a'], $this->repository()->displayUrls([$uid]));

        $this->replaceDay(1, 20261121, self::day(['https://example.org/a?utm_source=x' => [3]]));
        self::assertSame([$uid => 'https://example.org/a?utm_source=x'], $this->repository()->displayUrls([$uid]));

        // Re-importing the newest day counts as newest
        $this->replaceDay(1, 20261121, self::day(['https://example.org/a' => [3]]));
        self::assertSame([$uid => 'https://example.org/a'], $this->repository()->displayUrls([$uid]));
        self::assertSame([], $this->repository()->displayUrls([]));
    }

    #[Test]
    public function replaceDayStoresLargeDaysInChunks(): void
    {
        $urls = [];
        for ($i = 0; $i < 2500; $i++) {
            $urls['https://example.org/page-' . $i] = [1];
        }
        $this->replaceDay(1, 20261120, self::day(array_slice($urls, 0, 1200, true)));

        $uids = $this->replaceDay(1, 20261120, self::day($urls));

        self::assertCount(2500, $uids);
        self::assertCount(2500, array_unique($uids));
        self::assertCount(2500, $this->repository()->sumByUrl(1, 20261120, 20261120));
        self::assertCount(2500, $this->repository()->sumByUrlFor(1, 20261120, 20261120, array_values($uids)));
        self::assertCount(2500, $this->repository()->displayUrls(array_values($uids)));
    }

    #[Test]
    public function addUrlsReusesAUrlAlreadyInTheDictionary(): void
    {
        $database = $this->getConnectionPool()->getConnectionForTable(UrlDayRepository::URL_TABLE);
        $database->insert(UrlDayRepository::URL_TABLE, ['connection' => 1, 'url_hash' => UrlDayRows::hash('https://example.org/a'), 'url' => 'https://example.org/a']);
        $existing = (int)$database->lastInsertId();

        $uids = $this->repository()->addUrls(1, 20261120, self::day(['https://example.org/a' => [1], 'https://example.org/b' => [1]]));

        self::assertSame($existing, $uids[UrlDayRows::hash('https://example.org/a')]);
        self::assertNotSame($existing, $uids[UrlDayRows::hash('https://example.org/b')]);
        self::assertSame(2, $database->count('*', UrlDayRepository::URL_TABLE, []));
    }

    #[Test]
    public function insertUrlsSkipsUrlsAnotherProcessAddedMeanwhile(): void
    {
        $database = $this->getConnectionPool()->getConnectionForTable(UrlDayRepository::URL_TABLE);
        $hashes = array_map(static fn (string $url): string => (string)UrlDayRows::hash($url), ['https://example.org/a', 'https://example.org/b', 'https://example.org/c']);
        // Another import added /b between the select and the insert of addUrls()
        $database->insert(UrlDayRepository::URL_TABLE, ['connection' => 1, 'url_hash' => $hashes[1], 'url' => 'https://example.org/b']);
        $existing = (int)$database->lastInsertId();

        $this->repository()->insertUrls(1, array_combine($hashes, ['https://example.org/a', 'https://example.org/b?new=1', 'https://example.org/c']));

        $rows = $database->select(['uid', 'url_hash', 'url'], UrlDayRepository::URL_TABLE, ['connection' => 1], [], ['url' => 'ASC'])->fetchAllAssociative();
        self::assertSame(['https://example.org/a', 'https://example.org/b', 'https://example.org/c'], array_column($rows, 'url'));
        self::assertSame($existing, (int)$rows[1]['uid']);
        $uids = $this->repository()->addUrls(1, 20261120, self::day(['https://example.org/a' => [1], 'https://example.org/b' => [1], 'https://example.org/c' => [1]]));
        self::assertSame($existing, $uids[$hashes[1]]);
    }

    #[Test]
    public function replaceDayFactsRunsInTheTransactionOfTheCaller(): void
    {
        $urlDay = self::day(['https://example.org/' => [5]]);
        $uids = $this->repository()->addUrls(1, 20261120, $urlDay);

        try {
            $this->getConnectionPool()->getConnectionForTable(UrlDayRepository::TABLE)->transactional(function (Connection $database) use ($urlDay, $uids): never {
                $this->repository()->replaceDayFacts($database, 1, 20261120, $urlDay, $uids);
                throw new \RuntimeException('Import failed', 1);
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->repository()->sumByUrl(1, 20261120, 20261120));
        // The dictionary was written before, in autocommit
        self::assertSame(array_values($uids), array_keys($this->repository()->displayUrls(array_values($uids))));
    }

    #[Test]
    public function replaceDayFactsRequiresTheUidOfEveryUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1794500005);

        $this->repository()->replaceDayFacts(
            $this->getConnectionPool()->getConnectionForTable(UrlDayRepository::TABLE),
            1,
            20261120,
            self::day(['https://example.org/' => [5], 'https://example.org/a' => [1]]),
            [UrlDayRows::hash('https://example.org/') => 1],
        );
    }

    /**
     * Adds the URLs, then replaces the counts in a transaction, as the importer does.
     *
     * @return array<string, int> uid by URL hash
     */
    private function replaceDay(int $connection, int $day, UrlDay $urlDay): array
    {
        $uids = $this->repository()->addUrls($connection, $day, $urlDay);
        $this->getConnectionPool()->getConnectionForTable(UrlDayRepository::TABLE)->transactional(
            fn (Connection $database) => $this->repository()->replaceDayFacts($database, $connection, $day, $urlDay, $uids),
        );
        return $uids;
    }

    /**
     * @param array<string, list<int>> $counts by URL, in the order of PageMetrics::COUNTS
     */
    private static function day(array $counts): UrlDay
    {
        $rows = [];
        foreach ($counts as $url => $values) {
            $rows[] = ['label' => $url, 'url' => $url] + array_combine(
                ['nb_hits', 'nb_visits', 'sum_time_spent', 'entry_nb_visits', 'entry_bounce_count', 'exit_nb_visits'],
                array_pad($values, 6, 0),
            );
        }
        return UrlDayRows::fromReport($rows);
    }

    /**
     * @param array<int, array<string, int>> $sums
     * @return array<int, int>
     */
    private static function hits(array $sums): array
    {
        return array_map(static fn (array $counts): int => $counts['nb_hits'], $sums);
    }

    private function repository(): UrlDayRepository
    {
        return new UrlDayRepository($this->getConnectionPool());
    }
}
