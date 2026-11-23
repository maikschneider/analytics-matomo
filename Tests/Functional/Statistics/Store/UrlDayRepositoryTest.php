<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDay;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UrlDayRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];

    #[Test]
    public function replaceDayStoresTheUrlsAndTheirCounts(): void
    {
        $repository = $this->repository();

        $uids = $repository->replaceDay(1, 20261120, self::day([
            'https://example.org/' => [5, 4, 30, 3, 2, 1],
            'https://example.org/news' => [2, 2, 10, 1, 1, 1],
        ]));

        self::assertSame([sha1('example.org/'), sha1('example.org/news')], array_keys($uids));
        self::assertSame([
            $uids[sha1('example.org/')] => ['nb_hits' => 5, 'nb_visits' => 4, 'sum_time_spent' => 30, 'entry_nb_visits' => 3, 'entry_bounce_count' => 2, 'exit_nb_visits' => 1],
            $uids[sha1('example.org/news')] => ['nb_hits' => 2, 'nb_visits' => 2, 'sum_time_spent' => 10, 'entry_nb_visits' => 1, 'entry_bounce_count' => 1, 'exit_nb_visits' => 1],
        ], $repository->sumByUrl(1, 20261120, 20261120));
        self::assertSame([
            $uids[sha1('example.org/')] => 'https://example.org/',
            $uids[sha1('example.org/news')] => 'https://example.org/news',
        ], $repository->displayUrls(array_values($uids)));
    }

    #[Test]
    public function replaceDayTwiceReplacesTheCountsOfThatDay(): void
    {
        $repository = $this->repository();
        $first = $repository->replaceDay(1, 20261120, self::day([
            'https://example.org/' => [5],
            'https://example.org/old' => [1],
        ]));

        $second = $repository->replaceDay(1, 20261120, self::day([
            'https://example.org/' => [7],
            'https://example.org/new' => [2],
        ]));

        // Known URLs keep their uid
        self::assertSame($first[sha1('example.org/')], $second[sha1('example.org/')]);
        self::assertSame([
            $second[sha1('example.org/')] => 7,
            $second[sha1('example.org/new')] => 2,
        ], array_map(static fn (array $counts): int => $counts['nb_hits'], $repository->sumByUrl(1, 20261120, 20261120)));
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_url_day')->count('*', 'tx_analyticsmatomo_url_day', []));
        // The URL of the replaced row stays in the dictionary until the purge
        self::assertSame(3, $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_url')->count('*', 'tx_analyticsmatomo_url', []));
    }

    #[Test]
    public function replaceDayKeepsOtherDays(): void
    {
        $repository = $this->repository();
        $uids = $repository->replaceDay(1, 20261119, self::day(['https://example.org/' => [3]]));
        $repository->replaceDay(1, 20261120, self::day(['https://example.org/' => [4]]));
        $repository->replaceDay(1, 20261121, self::day(['https://example.org/' => [5]]));

        $repository->replaceDay(1, 20261120, self::day([]));

        self::assertSame([$uids[sha1('example.org/')] => 8], self::hits($repository->sumByUrl(1, 20261119, 20261121)));
        self::assertSame([$uids[sha1('example.org/')] => 3], self::hits($repository->sumByUrl(1, 20261119, 20261120)));
        self::assertSame([], $repository->sumByUrl(1, 20261120, 20261120));
    }

    #[Test]
    public function connectionsDoNotMix(): void
    {
        $repository = $this->repository();

        $one = $repository->replaceDay(1, 20261120, self::day(['https://example.org/' => [5], 'https://example.org/a' => [1]]));
        $two = $repository->replaceDay(2, 20261120, self::day(['https://example.org/' => [9]]));
        $repository->replaceDay(2, 20261120, self::day(['https://example.org/' => [8]]));

        // The same URL has a uid per connection
        self::assertNotSame($one[sha1('example.org/')], $two[sha1('example.org/')]);
        self::assertSame([$one[sha1('example.org/')] => 5, $one[sha1('example.org/a')] => 1], self::hits($repository->sumByUrl(1, 20261101, 20261130)));
        self::assertSame([$two[sha1('example.org/')] => 8], self::hits($repository->sumByUrl(2, 20261101, 20261130)));
    }

    #[Test]
    public function sumByUrlForSumsTheGivenUrlsOnly(): void
    {
        $repository = $this->repository();
        $uids = $repository->replaceDay(1, 20261119, self::day(['https://example.org/' => [3, 1], 'https://example.org/a' => [2], 'https://example.org/b' => [1]]));
        $repository->replaceDay(1, 20261120, self::day(['https://example.org/' => [4, 2], 'https://example.org/b' => [6]]));
        $other = $repository->replaceDay(2, 20261120, self::day(['https://example.org/c' => [9]]));

        $sums = $repository->sumByUrlFor(1, 20261119, 20261120, [$uids[sha1('example.org/')], $uids[sha1('example.org/b')], $other[sha1('example.org/c')]]);

        self::assertSame([$uids[sha1('example.org/')] => 7, $uids[sha1('example.org/b')] => 7], self::hits($sums));
        self::assertSame(3, $sums[$uids[sha1('example.org/')]]['nb_visits']);
        self::assertSame([], $repository->sumByUrlFor(1, 20261119, 20261120, []));
    }

    #[Test]
    public function displayUrlFollowsTheNewestDay(): void
    {
        $repository = $this->repository();
        $uids = $repository->replaceDay(1, 20261120, self::day(['https://example.org/a' => [3]]));
        $uid = $uids[sha1('example.org/a')];

        // A backfilled older day does not change it
        $repository->replaceDay(1, 20261119, self::day(['http://example.org/a' => [3]]));
        self::assertSame([$uid => 'https://example.org/a'], $repository->displayUrls([$uid]));

        $repository->replaceDay(1, 20261121, self::day(['https://example.org/a?utm_source=x' => [3]]));
        self::assertSame([$uid => 'https://example.org/a?utm_source=x'], $repository->displayUrls([$uid]));

        // Re-importing the newest day counts as newest
        $repository->replaceDay(1, 20261121, self::day(['https://example.org/a' => [3]]));
        self::assertSame([$uid => 'https://example.org/a'], $repository->displayUrls([$uid]));
        self::assertSame([], $repository->displayUrls([]));
    }

    #[Test]
    public function replaceDayStoresLargeDaysInChunks(): void
    {
        $repository = $this->repository();
        $urls = [];
        for ($i = 0; $i < 2500; $i++) {
            $urls['https://example.org/page-' . $i] = [1];
        }
        $repository->replaceDay(1, 20261120, self::day(array_slice($urls, 0, 1200, true)));

        $uids = $repository->replaceDay(1, 20261120, self::day($urls));

        self::assertCount(2500, $uids);
        self::assertCount(2500, array_unique($uids));
        self::assertCount(2500, $repository->sumByUrl(1, 20261120, 20261120));
        self::assertCount(2500, $repository->sumByUrlFor(1, 20261120, 20261120, array_values($uids)));
        self::assertCount(2500, $repository->displayUrls(array_values($uids)));
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
