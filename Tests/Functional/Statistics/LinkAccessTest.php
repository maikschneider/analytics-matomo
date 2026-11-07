<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics;

use MaikSchneider\AnalyticsMatomo\Statistics\LinkAccess;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LinkAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AccessUsers.csv');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function links(): iterable
    {
        yield 'unreadable relative page' => ['/board', false];
        yield 'unreadable relative language' => ['/de/ueber-uns', false];
        yield 'readable relative page' => ['/about', true];
        yield 'malformed' => ['http:///board', false];
        yield 'unresolved single label host' => ['intranet/secret-page', false];
        yield 'readable file' => ['/fileadmin/price-list.pdf', true];
        yield 'external link' => ['https://external.example.net/video', true];
        yield 'other site file' => ['//shop.example.org/fileadmin/catalogue.pdf', false];
    }

    #[Test]
    #[DataProvider('links')]
    public function relativeAndUnresolvedLabelsCannotBypassPageAccess(string $url, bool $visible): void
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('main');
        self::assertSame($visible, $this->get(LinkAccess::class)->isVisible($site, $url, $this->setUpBackendUser(5)));
    }

    #[Test]
    public function hostlessSelectedSitesAuthorizeAbsoluteAndSchemelessUrlsToo(): void
    {
        $main = $this->get(SiteFinder::class)->getSiteByIdentifier('main');
        $site = new Site('main', 1, ['base' => '/'] + $main->getConfiguration());
        foreach (['https://example.com/board', 'example.com/board', 'https://example.com/de/ueber-uns'] as $url) {
            self::assertFalse($this->get(LinkAccess::class)->isVisible($site, $url, $this->setUpBackendUser(5)), $url);
        }
    }

    #[Test]
    public function explicitHostsAndLongerSiteBasesWinOverTheSelectedSite(): void
    {
        $main = $this->get(SiteFinder::class)->getSiteByIdentifier('main');
        foreach (['/', 'https://example.com/'] as $base) {
            $selected = new Site('main', 1, ['base' => $base] + $main->getConfiguration());
            $shop = new Site('shop', 10, ['base' => 'https://example.com/shop/'] + $main->getConfiguration());
            $finder = $this->createMock(SiteFinder::class);
            $finder->method('getAllSites')->willReturn(['main' => $selected, 'shop' => $shop]);
            $access = new LinkAccess($this->get(PageUrlMapper::class), $finder);
            self::assertFalse($access->isVisible($selected, 'https://example.com/shop/fileadmin/catalogue.pdf', $this->setUpBackendUser(5)), $base);
        }
    }
}
