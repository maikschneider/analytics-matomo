<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Controller\StatisticsBarController;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use MaikSchneider\AnalyticsMatomo\Dashboard\SiteStatisticsService;
use MaikSchneider\AnalyticsMatomo\Dashboard\Widget\MatomoWidget;
use MaikSchneider\AnalyticsMatomo\Matomo\DemoPageUrls;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Dashboard;
use TYPO3\CMS\Dashboard\DashboardInitializationService;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The instance of `ddev init-typo3`: its sites, pages and backend group, with demo data.
 */
final class DemoInstanceTest extends FunctionalTestCase
{
    private const EDITOR = 2;

    protected array $coreExtensionsToLoad = [
        'dashboard',
    ];

    protected array $testExtensionsToLoad = [
        'maikschneider/analytics-matomo',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/config/sites' => 'typo3conf/sites',
    ];

    /**
     * @var list<RequestInterface>
     */
    private array $sentRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DemoInstance/Database.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/DemoInstanceUsers.csv');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): never {
            $this->sentRequests[] = $request;
            throw new \RuntimeException('The demo sites must not ask Matomo', 1794030001);
        };
    }

    #[Test]
    public function pageStatisticsDifferByPageLanguageAndSite(): void
    {
        $views = [];
        foreach ([[1, 0], [6, 0], [17, 0], [17, 1], [11, 2], [31, 0], [31, 1]] as [$pageId, $languageId]) {
            self::assertSame(1, preg_match('/tx-analytics-matomo-value">([^<]+)</', $this->statisticsBar($pageId, $languageId), $value));
            $views[] = $value[1] ?? '';
        }

        self::assertNotContains('', $views);
        self::assertCount(7, array_unique($views));
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function demoPagesAreTheVisiblePagesInEveryLanguageAVisitorCanSee(): void
    {
        $cycles = $this->get(DemoPageUrls::class)->get(1);
        self::assertContains('https://analytics-matomo.ddev.site/', $cycles);
        self::assertContains('https://analytics-matomo.ddev.site/bikes/e-bikes/cargo/long-john-2026', $cycles);
        self::assertContains('https://analytics-matomo.ddev.site/de/%C3%BCber-uns', $cycles);
        // French falls back to English, German is translated page by page
        self::assertContains('https://analytics-matomo.ddev.site/fr/bikes', $cycles);
        self::assertContains('https://analytics-matomo.ddev.site/fr/circuits', $cycles);
        self::assertNotContains('https://analytics-matomo.ddev.site/de/bikes/e-bikes/cargo/long-john-2026', $cycles);
        self::assertNotContains('https://analytics-matomo.ddev.site/summer-sale', $cycles);
        self::assertNotContains('https://analytics-matomo.ddev.site/storage', $cycles);
        self::assertNotContains('https://analytics-matomo.ddev.site/shop', $cycles);
        self::assertNotContains('https://analytics-matomo.ddev.site/404', $cycles);
        self::assertNotContains('https://analytics-matomo.ddev.site/customer-area', $cycles);

        self::assertContains('https://rental.analytics-matomo.ddev.site/mietraeder/tagesmiete/', $this->get(DemoPageUrls::class)->get(30));
        self::assertSame([], $this->get(DemoPageUrls::class)->get(2));
    }

    #[Test]
    public function foldersAndShortcutsHaveNoStatistics(): void
    {
        self::assertSame('', $this->statisticsBar(24, 0));
        self::assertSame('', $this->statisticsBar(19, 0));
    }

    #[Test]
    public function topPagesAreTheVisiblePagesOfTheSelectedSite(): void
    {
        $cycles = $this->topPages('cycles');
        self::assertStringContainsString('>Elbe Cycles</a>', $cycles);
        self::assertStringNotContainsString('title="https://rental.analytics-matomo', $cycles);

        $rental = $this->topPages('rental');
        self::assertStringContainsString('>Velo Verleih Dresden</a>', $rental);
        self::assertStringContainsString('title="https://rental.analytics-matomo.ddev.site/mietraeder/"', $rental);
        self::assertStringNotContainsString('title="https://analytics-matomo.ddev.site/', $rental);
        // Every top page is a page of the site, linked to the Page module
        self::assertStringNotContainsString('<span title="https://', $cycles . $rental);
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function everySiteHasItsOwnTraffic(): void
    {
        $period = Period::lastDays(30, new \DateTimeImmutable('2026-11-02'));
        $visits = [];
        foreach (['cycles', 'rental'] as $identifier) {
            $configuration = $this->get(MatomoConfigurationResolver::class)->resolve($this->get(SiteFinder::class)->getSiteByIdentifier($identifier));
            self::assertNotNull($configuration);
            self::assertTrue($configuration->demo);
            $visits[] = $this->get(SiteStatisticsService::class)->getPerformance($configuration, $period)->current->visits;
        }

        self::assertGreaterThan(0, min($visits));
        self::assertNotSame($visits[0], $visits[1]);
    }

    #[Test]
    public function adminAndEditorGetTheMatomoDashboard(): void
    {
        foreach ([1, self::EDITOR] as $backendUserId) {
            $GLOBALS['BE_USER'] = $this->setUpBackendUser($backendUserId);
            $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
            $dashboards = $this->get(DashboardInitializationService::class)->getDashboardsForUser($GLOBALS['BE_USER']);
            $dashboard = reset($dashboards);
            self::assertInstanceOf(Dashboard::class, $dashboard);
            $widgets = array_column($dashboard->getWidgetConfig(), 'identifier');

            self::assertSame(['analyticsMatomoPerformance', 'analyticsMatomoTraffic', 'analyticsMatomoTopPages'], $widgets);
        }
        self::assertSame(['cycles', 'live', 'rental'], array_keys($this->get(DashboardSelectionResolver::class)->getSites($this->setUpBackendUser(self::EDITOR))));
    }

    #[Test]
    public function editorsCanAccessTheParentModuleAndEverySection(): void
    {
        $user = $this->setUpBackendUser(self::EDITOR);
        $modules = $this->get(\TYPO3\CMS\Backend\Module\ModuleProvider::class);

        foreach (['analytics_matomo', 'analytics_matomo_visitors', 'analytics_matomo_behaviour', 'analytics_matomo_acquisition', 'analytics_matomo_goals', 'analytics_matomo_page'] as $identifier) {
            self::assertNotNull($modules->getModule($identifier, $user), $identifier);
        }
    }

    private function statisticsBar(int $pageId, int $languageId): string
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(self::EDITOR);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $request = (new ServerRequest('https://analytics-matomo.ddev.site/typo3/ajax/analytics-matomo/statistics-bar'))
            ->withQueryParams(['pageId' => (string)$pageId, 'languageId' => (string)$languageId, 'days' => '30'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $data = json_decode((string)$this->get(StatisticsBarController::class)($request)->getBody(), true);
        self::assertIsArray($data);
        self::assertIsString($data['html']);
        return $data['html'];
    }

    private function topPages(string $siteIdentifier): string
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(self::EDITOR);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $this->get(DashboardSelectionResolver::class)->save($GLOBALS['BE_USER'], $siteIdentifier, '30');
        $request = (new ServerRequest('https://analytics-matomo.ddev.site/typo3/', 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $widget = $this->get(WidgetRegistry::class)->getAvailableWidget($request, 'analyticsMatomoTopPages');
        self::assertInstanceOf(MatomoWidget::class, $widget);
        return $widget->renderWidgetContent();
    }
}
