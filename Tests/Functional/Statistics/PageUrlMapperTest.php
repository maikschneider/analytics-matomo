<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics;

use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageUrlMapperTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'dashboard',
    ];

    protected array $testExtensionsToLoad = [
        'maikschneider/analytics-matomo',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
    }

    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function pageUrls(): iterable
    {
        yield 'default language' => ['main', 'https://example.com/about', 2, 0];
        yield 'site root' => ['main', 'https://example.com/', 1, 0];
        yield 'translated page' => ['main', 'https://example.com/de/ueber-uns', 2, 1];
        yield 'http instead of https' => ['main', 'http://example.com/about', 2, 0];
        yield 'query string' => ['main', 'https://example.com/about?utm_source=newsletter', 2, 0];
        yield 'cHash and plugin arguments' => ['main', 'https://example.com/about?tx_news_pi1%5Bnews%5D=5&cHash=0123456789abcdef', 2, 0];
        yield 'label without scheme' => ['main', 'example.com/about', 2, 0];
        yield 'double slashes' => ['main', 'https://example.com//de//ueber-uns', 2, 1];
        yield 'trailing slash added' => ['main', 'https://example.com/about/', 2, 0];
        yield 'umlaut in the slug' => ['main', 'https://example.com/sächsische-schweiz', 7, 0];
        yield 'percent-encoded umlaut' => ['main', 'https://example.com/s%C3%A4chsische-schweiz', 7, 0];
        yield 'hidden page' => ['main', 'https://example.com/summer-sale', 5, 0];
        yield 'hidden translation' => ['main', 'https://example.com/de/sommerschlussverkauf', 5, 1];
        yield 'slug with trailing slash' => ['shop', 'https://shop.example.org/products/', 11, 0];
        yield 'trailing slash missing' => ['shop', 'https://shop.example.org/products', 11, 0];
        yield 'language on its own domain' => ['shop', 'https://shop.example.de/produkte/', 11, 1];
        yield 'untranslated page shown in fallback language' => ['shop', 'https://shop.example.de/imprint', 13, 1];
        yield 'page id parameter' => ['main', 'https://example.com/?id=2&utm_source=newsletter', 2, 0];
        yield 'site base without host' => ['trial', 'https://www.trial.example.net/about', 31, 0];
    }

    #[Test]
    public function matchIgnoresMalformedUrls(): void
    {
        self::assertNull($this->get(PageUrlMapper::class)->match($this->site('main'), 'http:///about'));
    }

    #[Test]
    #[DataProvider('pageUrls')]
    public function matchFindsPageAndLanguage(string $siteIdentifier, string $url, int $pageId, int $languageId): void
    {
        $match = $this->get(PageUrlMapper::class)->match($this->site($siteIdentifier), $url);

        self::assertNotNull($match);
        self::assertSame($pageId, $match->pageId);
        self::assertSame($languageId, $match->language->getLanguageId());
    }

    #[Test]
    public function matchKeepsUrlsOfTheSiteWithoutPage(): void
    {
        $match = $this->get(PageUrlMapper::class)->match($this->site('main'), 'https://example.com/videos.php');

        self::assertNotNull($match);
        self::assertNull($match->pageId);
        self::assertSame(0, $match->language->getLanguageId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignUrls(): iterable
    {
        yield 'other site' => ['https://shop.example.org/products/'];
        yield 'unknown domain' => ['https://www.virtual-drums.com/'];
        yield 'www subdomain' => ['https://www.example.com/about'];
        yield 'no url' => ['about'];
        yield 'path only' => ['/about'];
    }

    #[Test]
    #[DataProvider('foreignUrls')]
    public function matchIgnoresUrlsOfOtherSites(string $url): void
    {
        self::assertNull($this->get(PageUrlMapper::class)->match($this->site('main'), $url));
    }

    #[Test]
    public function mapReportKeepsTheRowsOfTheSite(): void
    {
        $report = $this->get(PageUrlMapper::class)->mapReport($this->site('main'), $this->fixture('TopPagesMainSite'));

        self::assertFalse($report->isTruncated);
        self::assertSame(
            ['https://example.com/', 'http://example.com/about', 'https://example.com/de/ueber-uns', 'https://example.com/videos.php'],
            array_map(static fn (PageUrlRow $row): mixed => $row->row['url'], $report->rows),
        );
        self::assertSame(20, $report->rowsOfPage(2, 0)[0]['nb_hits'] ?? null);
        // Missing from a complete report: the page had no views
        self::assertSame([], $report->rowsOfPage(5, 0));
    }

    #[Test]
    public function mapReportReturnsUnknownForPagesMatomoCutOff(): void
    {
        $report = $this->get(PageUrlMapper::class)->mapReport($this->site('main'), $this->fixture('SyntheticTruncatedPageUrls'));

        self::assertTrue($report->isTruncated);
        self::assertNotSame([], $report->rowsOfPage(2, 0));
        self::assertNull($report->rowsOfPage(5, 0));
    }

    private function site(string $identifier): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier($identifier);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixture(string $name): array
    {
        $results = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name . '.json'), true);
        self::assertIsArray($results);
        self::assertIsArray($results[0]);
        self::assertTrue(array_is_list($results[0]));
        /** @var list<array<string, mixed>> */
        return $results[0];
    }
}
