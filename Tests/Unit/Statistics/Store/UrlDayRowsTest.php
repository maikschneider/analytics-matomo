<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDay;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRow;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class UrlDayRowsTest extends UnitTestCase
{
    #[Test]
    public function recordedDayKeepsEveryUrlWithItsCounts(): void
    {
        $result = json_decode((string)file_get_contents(__DIR__ . '/../../../Fixtures/Matomo/PageUrlsDay.json'), true);

        $urlDay = UrlDayRows::fromReport($result);

        self::assertSame(9, $urlDay->totalHits);
        self::assertSame(0, $urlDay->summaryHits);
        // Five rows, all different paths
        self::assertCount(5, $urlDay->rows);
        $home = $urlDay->rows[UrlDayRows::hash('https://www.virtual-drums.com/')];
        // Counts answered as strings become integers
        self::assertSame(['nb_hits' => 5, 'nb_visits' => 5, 'sum_time_spent' => 8, 'entry_nb_visits' => 5, 'entry_bounce_count' => 5, 'exit_nb_visits' => 5], $home->counts);
        self::assertSame('http://www.virtual-drums.com/', $home->url);
        // The query is reduced to the page ID: the XSS probe is stored by its path
        self::assertSame('http://www.virtual-drums.com/xss3cvar/?xss=cvar', $urlDay->rows[UrlDayRows::hash('https://www.virtual-drums.com/xss3cvar/')]->url);
    }

    #[Test]
    public function httpAndHttpsAndQueryVariantsAreMerged(): void
    {
        $urlDay = UrlDayRows::fromReport([
            self::row('http://example.org/news', 2, 1, 10, 1, 1, 1),
            self::row('https://example.org/news?utm_source=mail', 5, 4, 20, 3, 2, 2),
            self::row('https://example.org/news', 1, 1, 0, 0, 0, 1),
            self::row('https://example.org/index.php?id=5&cHash=abc', 3, 3, 3, 3, 3, 3),
            self::row('https://example.org/index.php?id=6', 1, 1, 1, 1, 1, 1),
        ]);

        self::assertSame(['example.org/news', 'example.org/index.php?id=5', 'example.org/index.php?id=6'], array_map(static fn (string $hash): string => self::keyOf($hash, [
            'example.org/news', 'example.org/index.php?id=5', 'example.org/index.php?id=6',
        ]), array_keys($urlDay->rows)));
        $news = $urlDay->rows[UrlDayRows::hash('https://example.org/news')];
        self::assertSame(['nb_hits' => 8, 'nb_visits' => 6, 'sum_time_spent' => 30, 'entry_nb_visits' => 4, 'entry_bounce_count' => 3, 'exit_nb_visits' => 4], $news->counts);
        // The busiest variant is shown, with its scheme and query
        self::assertSame('https://example.org/news?utm_source=mail', $news->url);
        self::assertSame('https://example.org/index.php?id=5&cHash=abc', $urlDay->rows[UrlDayRows::hash('https://example.org/index.php?id=5')]->url);
        self::assertSame(12, $urlDay->totalHits);
    }

    #[Test]
    public function firstVariantWinsATie(): void
    {
        $urlDay = UrlDayRows::fromReport([
            self::row('http://example.org/a', 2),
            self::row('https://example.org/a', 2),
        ]);

        self::assertSame('http://example.org/a', $urlDay->rows[UrlDayRows::hash('https://example.org/a')]->url);
        self::assertSame(4, $urlDay->rows[UrlDayRows::hash('https://example.org/a')]->counts['nb_hits']);
    }

    #[Test]
    public function summaryRowsCountInTheTotalOnly(): void
    {
        $urlDay = UrlDayRows::fromReport([
            self::row('https://example.org/', 10),
            ['label' => -1, 'nb_hits' => 7, 'nb_visits' => 7],
            ['label' => 'Others', 'is_summary' => 1, 'nb_hits' => '3', 'url' => 'https://example.org/others'],
        ]);

        self::assertSame(20, $urlDay->totalHits);
        self::assertSame(10, $urlDay->summaryHits);
        self::assertSame([UrlDayRows::hash('https://example.org/')], array_keys($urlDay->rows));
    }

    #[Test]
    public function rowsWithoutUrlCountInTheTotalOnly(): void
    {
        $urlDay = UrlDayRows::fromReport([
            self::row('https://example.org/', 1),
            ['label' => 'Page Name Not Defined', 'nb_hits' => 4],
            ['label' => 'Others', 'url' => 'Others', 'nb_hits' => 2],
            ['label' => '', 'url' => '', 'nb_hits' => 1],
        ]);

        self::assertSame(8, $urlDay->totalHits);
        self::assertSame(0, $urlDay->summaryHits);
        self::assertSame([UrlDayRows::hash('https://example.org/')], array_keys($urlDay->rows));
    }

    #[Test]
    public function fullUrlWithDotlessHostIsKept(): void
    {
        $urlDay = UrlDayRows::fromReport([
            self::row('http://intranet/page', 3),
            self::row('https://intranet/page', 1),
        ]);

        self::assertSame('http://intranet/page', $urlDay->rows[UrlDayRows::hash('https://intranet/page')]->url);
        self::assertSame(4, $urlDay->rows[UrlDayRows::hash('https://intranet/page')]->counts['nb_hits']);
    }

    #[Test]
    public function percentEncodedPathsAreMergedByTheDecodedPath(): void
    {
        $urlDay = UrlDayRows::fromReport([
            self::row('https://example.org/caf%C3%A9/men%C3%BC', 1),
            self::row('https://example.org/café/menü', 1),
            self::row('https://example.org/caf%C3%A9/men%C3%BC?ref=1', 2),
        ]);

        self::assertSame([UrlDayRows::hash('https://example.org/café/menü')], array_keys($urlDay->rows));
        // The original encoding stays a valid link
        self::assertSame('https://example.org/caf%C3%A9/men%C3%BC?ref=1', $urlDay->rows[UrlDayRows::hash('https://example.org/café/menü')]->url);
    }

    #[Test]
    public function keyDropsTheScheme(): void
    {
        self::assertSame('www.example.org/path?id=5', UrlDayRows::key('https://www.example.org//path?id=5&L=1'));
        self::assertSame('www.example.org/path?id=5', UrlDayRows::key('http://www.example.org/path?id=5'));
        self::assertSame('intranet/page', UrlDayRows::key('http://intranet/page'));
        self::assertNull(UrlDayRows::key('Others'));
        self::assertNull(UrlDayRows::key(''));
    }

    #[Test]
    public function hashIsTheSameForEveryVariantOfAUrl(): void
    {
        self::assertSame(sha1('www.example.org/path?id=5'), UrlDayRows::hash('https://www.example.org//path?id=5&L=1'));
        self::assertSame(UrlDayRows::hash('https://www.example.org/path?id=5'), UrlDayRows::hash('http://www.example.org/path?id=5&utm_source=x'));
        self::assertNull(UrlDayRows::hash('Others'));
    }

    #[Test]
    public function emptyDayHasNoRows(): void
    {
        self::assertEquals(new UrlDay([], 0, 0), UrlDayRows::fromReport([]));
    }

    #[Test]
    public function rowExposesItsCountsInTheOrderOfPageMetrics(): void
    {
        $row = UrlDayRows::fromReport([self::row('https://example.org/', 1, 2, 3, 4, 5, 6)])->rows[UrlDayRows::hash('https://example.org/')];

        self::assertInstanceOf(UrlDayRow::class, $row);
        self::assertSame(PageMetrics::COUNTS, array_keys($row->counts));
        self::assertSame([1, 2, 3, 4, 5, 6], array_values($row->counts));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedResults(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['Maintenance'];
        yield 'error object' => [['result' => 'error', 'message' => 'Archiving failed']];
        yield 'rows by date' => [['2026-11-20' => [self::row('https://example.org/', 1)]]];
        yield 'scalar row' => [[1, 2]];
        yield 'url not a string' => [[['label' => '/', 'url' => ['x'], 'nb_hits' => 1]]];
        yield 'count not numeric' => [[['label' => '/', 'url' => 'https://example.org/', 'nb_hits' => 'many']]];
    }

    #[Test]
    #[DataProvider('malformedResults')]
    public function fromReportRejectsMalformedResults(mixed $result): void
    {
        $this->expectException(MatomoApiException::class);
        $this->expectExceptionCode(1794500001);

        UrlDayRows::fromReport($result);
    }

    /**
     * @return array<string, int|string>
     */
    private static function row(string $url, int $hits, int $visits = 0, int $time = 0, int $entries = 0, int $bounces = 0, int $exits = 0): array
    {
        return [
            'label' => (string)parse_url($url, PHP_URL_PATH),
            'nb_visits' => $visits,
            'nb_hits' => $hits,
            'sum_time_spent' => $time,
            'entry_nb_visits' => (string)$entries,
            'entry_bounce_count' => (string)$bounces,
            'exit_nb_visits' => (string)$exits,
            'url' => $url,
        ];
    }

    /**
     * @param list<string> $keys
     */
    private static function keyOf(string $hash, array $keys): string
    {
        foreach ($keys as $key) {
            if (UrlDayRows::hash('https://' . $key) === $hash) {
                return $key;
            }
        }
        return $hash;
    }
}
