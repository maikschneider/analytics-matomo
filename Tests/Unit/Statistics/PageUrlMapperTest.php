<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMatch;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlReport;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageUrlMapperTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'unchanged' => ['https://example.com/about', 'https://example.com/about'];
        yield 'label without scheme' => ['example.com/about', 'https://example.com/about'];
        yield 'protocol-relative' => ['//example.com/about', 'https://example.com/about'];
        yield 'double slashes' => ['https://example.com//bikes///e-bikes', 'https://example.com/bikes/e-bikes'];
        yield 'trailing slash kept' => ['https://example.com/products/', 'https://example.com/products/'];
        yield 'cHash and plugin arguments' => ['https://example.com/news?tx_news_pi1%5Bnews%5D=5&cHash=abc', 'https://example.com/news'];
        yield 'tracking parameters' => ['https://example.com/about?utm_source=newsletter&mtm_campaign=spring', 'https://example.com/about'];
        yield 'page id kept' => ['https://example.com/?id=2&utm_source=newsletter', 'https://example.com/?id=2'];
        yield 'fragment' => ['https://example.com/about#team', 'https://example.com/about'];
        yield 'surrounding whitespace' => [' https://example.com/about ', 'https://example.com/about'];
        yield 'path only' => ['/about//team?cHash=abc', '/about/team'];
    }

    #[Test]
    #[DataProvider('urls')]
    public function normalizeKeepsWhatIdentifiesThePage(string $url, string $expected): void
    {
        self::assertSame($expected, PageUrlMapper::normalize($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'others row' => ['Others'];
        yield 'summary row' => ['-1'];
        yield 'host missing' => ['http:///about'];
    }

    #[Test]
    #[DataProvider('noUrls')]
    public function normalizeRejectsWhatIsNotAUrl(string $url): void
    {
        self::assertNull(PageUrlMapper::normalize($url));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function rows(): iterable
    {
        yield 'page row' => [['label' => '/about', 'url' => 'https://example.com/about', 'nb_hits' => 3], false];
        yield 'flattened summary row' => [['label' => '/bikes/ - Others', 'nb_hits' => 12, 'is_summary' => true], true];
        yield 'summary row of a report that is not flat' => [['label' => -1, 'nb_hits' => 40], true];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('rows')]
    public function isSummaryRowDetectsTheRowOfTheCutOffRows(array $row, bool $expected): void
    {
        self::assertSame($expected, PageUrlMapper::isSummaryRow($row));
    }

    #[Test]
    public function reportKnowsPagesThatAreMissingFromACompleteReport(): void
    {
        $report = new PageUrlReport([$this->row(2, 0, 7)], false);

        self::assertSame([['nb_hits' => 7]], $report->rowsOfPage(2, 0));
        self::assertSame([], $report->rowsOfPage(2, 1));
        self::assertSame([], $report->rowsOfPage(3, 0));
    }

    #[Test]
    public function reportReturnsUnknownForMissingPagesWhenMatomoCutOffRows(): void
    {
        $report = new PageUrlReport([$this->row(2, 0, 7)], true);

        self::assertSame([['nb_hits' => 7]], $report->rowsOfPage(2, 0));
        self::assertNull($report->rowsOfPage(3, 0));
    }

    #[Test]
    public function reportCollectsEveryUrlOfAPage(): void
    {
        $report = new PageUrlReport([$this->row(2, 0, 7), $this->row(2, 0, 3), $this->row(2, 1, 1)], false);

        self::assertSame([['nb_hits' => 7], ['nb_hits' => 3]], $report->rowsOfPage(2, 0));
    }

    private function row(int $pageId, int $languageId, int $hits): PageUrlRow
    {
        return new PageUrlRow(['nb_hits' => $hits], new PageUrlMatch(new SiteLanguage($languageId, 'en_US', new Uri('/'), []), $pageId));
    }
}
