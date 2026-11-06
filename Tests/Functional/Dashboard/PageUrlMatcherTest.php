<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Dashboard;

use MaikSchneider\AnalyticsMatomo\Dashboard\PageUrlMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageUrlMatcherTest extends FunctionalTestCase
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
        yield 'slug with trailing slash' => ['shop', 'https://shop.example.org/products/', 11, 0];
        yield 'trailing slash missing' => ['shop', 'https://shop.example.org/products', 11, 0];
        yield 'language on its own domain' => ['shop', 'https://shop.example.de/produkte/', 11, 1];
        yield 'untranslated page shown in fallback language' => ['shop', 'https://shop.example.de/imprint', 13, 1];
    }

    #[Test]
    #[DataProvider('pageUrls')]
    public function matchFindsPageAndLanguage(string $siteIdentifier, string $url, int $pageId, int $languageId): void
    {
        $match = (new PageUrlMatcher())->match($this->get(SiteFinder::class)->getSiteByIdentifier($siteIdentifier), $url);

        self::assertNotNull($match);
        self::assertSame($pageId, $match->pageId);
        self::assertSame($languageId, $match->language->getLanguageId());
    }

    #[Test]
    public function matchKeepsUrlsOfTheSiteWithoutPage(): void
    {
        $match = (new PageUrlMatcher())->match($this->get(SiteFinder::class)->getSiteByIdentifier('main'), 'https://example.com/videos.php');

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
    }

    #[Test]
    #[DataProvider('foreignUrls')]
    public function matchIgnoresUrlsOfOtherSites(string $url): void
    {
        self::assertNull((new PageUrlMatcher())->match($this->get(SiteFinder::class)->getSiteByIdentifier('main'), $url));
    }
}
