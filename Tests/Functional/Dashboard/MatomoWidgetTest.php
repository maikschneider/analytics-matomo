<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Dashboard;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Controller\DashboardSelectionController;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use MaikSchneider\AnalyticsMatomo\Dashboard\Widget\MatomoWidget;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MatomoWidgetTest extends FunctionalTestCase
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

    /**
     * @var list<RequestInterface>
     */
    private array $sentRequests = [];

    private string $fixture = '';

    private int $status = 200;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $this->sentRequests[] = $request;
            return new FulfilledPromise(new Response($this->status, [], (string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $this->fixture . '.json')));
        };
    }

    protected function tearDown(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN');
        parent::tearDown();
    }

    #[Test]
    public function performanceShowsSiteTiles(): void
    {
        $html = $this->render(1, 'analyticsMatomoPerformance', 'SitePerformance');

        self::assertStringContainsString('tx-analytics-matomo-metric--visits', $html);
        self::assertStringContainsString('72.0 %', $html);
        self::assertStringContainsString('+6', $html);
        self::assertStringContainsString('Share of visits that left the site after one page.', $html);
        self::assertStringContainsString('module=CoreHome', $html);
        // Admins choose between both configured sites
        self::assertStringContainsString('<option value="shop"', $html);
    }

    #[Test]
    public function trafficProvidesTheLineChart(): void
    {
        $widget = $this->widget(1, 'analyticsMatomoTraffic');
        $this->fixture = 'Traffic';

        $html = $widget->renderWidgetContent();
        $chart = $widget->getEventData()['graphConfig'];

        self::assertStringContainsString('<canvas', $html);
        self::assertSame('line', $chart['type']);
        self::assertSame(['27.10.', '28.10.', '29.10.', '30.10.', '31.10.', '01.11.', '02.11.'], $chart['data']['labels']);
        self::assertSame([6, 1, 2, 8, 1, 7, 4], $chart['data']['datasets'][0]['data']);
    }

    #[Test]
    public function topPagesLinksPagesOfTheSelectedSiteOnly(): void
    {
        $html = $this->render(1, 'analyticsMatomoTopPages', 'TopPagesMainSite');

        self::assertStringContainsString('>Root</a>', $html);
        self::assertStringContainsString('>About</a>', $html);
        self::assertStringContainsString('flags-de', $html);
        self::assertStringContainsString('<span title="https://example.com/videos.php">/videos.php</span>', $html);
        self::assertStringNotContainsString('title="https://shop.example.org', $html);
        self::assertStringNotContainsString('virtual-drums', $html);
        // 40 of 100 page views, 50 before
        self::assertStringContainsString('40.0 %', $html);
        self::assertStringContainsString('−20.0 %', $html);
    }

    #[Test]
    public function topPagesListsOnlyPagesAndLanguagesTheEditorMayAccess(): void
    {
        $html = $this->render(5, 'analyticsMatomoTopPages', 'TopPagesMainSite');

        self::assertStringContainsString('>About</a>', $html);
        // Editor 5 may only edit the default language and may not see pages without a page record
        self::assertStringNotContainsString('ueber-uns', $html);
        self::assertStringNotContainsString('videos.php', $html);
    }

    #[Test]
    public function widgetsLinkToTheirModuleReportForUsersWithModuleAccess(): void
    {
        $links = [
            'analyticsMatomoPerformance' => ['SitePerformance', '/module/analytics-matomo/visitors?token=', 'report=overview'],
            'analyticsMatomoTraffic' => ['Traffic', '/module/analytics-matomo/visitors?token=', 'report=overview'],
            'analyticsMatomoTopPages' => ['TopPagesMainSite', '/module/analytics-matomo/behaviour?token=', 'report=pages'],
        ];
        foreach ($links as $identifier => [$fixture, $route, $report]) {
            $html = $this->render(1, $identifier, $fixture);
            self::assertMatchesRegularExpression('#<a class="widget-cta" href="[^"]*' . preg_quote($route, '#') . '[^"]*' . $report . '"#', $html, $identifier);
            self::assertStringNotContainsString($route, $this->render(5, $identifier, $fixture), $identifier);
        }

        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['groupMods' => 'analytics_matomo,analytics_matomo_behaviour'], ['uid' => 1]);
        self::assertStringContainsString('/module/analytics-matomo/behaviour?token=', $this->render(5, 'analyticsMatomoTopPages', 'TopPagesMainSite'));
        self::assertStringNotContainsString('/module/analytics-matomo/visitors?token=', $this->render(5, 'analyticsMatomoPerformance', 'SitePerformance'));
    }

    #[Test]
    public function onlyTheEntryPointWidgetsAreRegistered(): void
    {
        $identifiers = array_filter(array_keys($this->get(WidgetRegistry::class)->getAllWidgets()), static fn (string $identifier): bool => str_starts_with($identifier, 'analyticsMatomo'));

        self::assertSame(['analyticsMatomoPerformance', 'analyticsMatomoTraffic', 'analyticsMatomoTopPages'], array_values($identifiers));
    }

    #[Test]
    public function showsAHintWithoutVisits(): void
    {
        self::assertStringContainsString('No visits in this period.', $this->render(1, 'analyticsMatomoTopPages', 'EmptyTopPages'));
    }

    #[Test]
    public function showsMatomoErrorsToAdminsOnly(): void
    {
        $this->status = 401;

        self::assertStringContainsString('access for the website id = 1', $this->render(1, 'analyticsMatomoTraffic', 'ErrorNoViewAccess'));
        self::assertStringNotContainsString('access for the website id = 1', $this->render(5, 'analyticsMatomoTraffic', 'ErrorNoViewAccess'));
        self::assertStringContainsString('The statistics could not be loaded from Matomo.', $this->render(5, 'analyticsMatomoTraffic', 'ErrorNoViewAccess'));
    }

    #[Test]
    public function showsNothingWithoutSiteStatisticsPermission(): void
    {
        $identifiers = ['analyticsMatomoPerformance', 'analyticsMatomoTraffic', 'analyticsMatomoTopPages'];
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_visitors',
            'availableWidgets' => implode(',', $identifiers),
        ], ['uid' => 1]);
        foreach ($identifiers as $identifier) {
            $html = $this->render(4, $identifier, 'SitePerformance');
            self::assertStringContainsString('You are not allowed to view site statistics.', $html);
            self::assertSame([], $this->sentRequests);
        }
    }

    #[Test]
    public function demoSitesShowGeneratedDataWithoutAskingMatomo(): void
    {
        $user = $this->setUpBackendUser(1);
        $this->get(DashboardSelectionResolver::class)->save($user, 'trial', '7');

        $performance = $this->render(1, 'analyticsMatomoPerformance', 'ErrorNoViewAccess');
        $widget = $this->widget(1, 'analyticsMatomoTopPages');
        $topPages = $widget->renderWidgetContent();
        $footer = $widget->renderWidgetContent();

        self::assertSame([], $this->sentRequests);
        self::assertStringContainsString('tx-analytics-matomo-metric--visits', $performance);
        self::assertStringNotContainsString('module=CoreHome', $performance);
        self::assertStringContainsString('Demo data', $footer);
        self::assertStringContainsString('>About the trial</a>', $topPages);
    }

    #[Test]
    public function customRangeSurvivesASiteChangeSubmittedFromTheWidget(): void
    {
        $resolver = $this->get(DashboardSelectionResolver::class);
        $resolver->save($this->setUpBackendUser(1), 'trial', 'custom', '2026-09-01', '2026-09-17');
        $html = $this->render(1, 'analyticsMatomoPerformance', 'SitePerformance');
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $fields = ['site' => 'shop', 'period' => 'custom'];
        foreach ($xpath->query('//form[@data-analytics-matomo-selection]//input[@type="hidden"]') ?: [] as $input) {
            self::assertInstanceOf(\DOMElement::class, $input);
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $response = $this->get(DashboardSelectionController::class)($this->request()->withParsedBody($fields));
        self::assertSame(200, $response->getStatusCode());
        $selection = $resolver->resolve($this->setUpBackendUser(1));
        self::assertNotNull($selection);
        self::assertSame('shop', $selection->site->getIdentifier());
        self::assertSame('custom', $selection->period->preset);
        self::assertSame('2026-09-01,2026-09-17', $selection->period->toMatomoDate());
    }

    #[Test]
    public function selectionIsStoredForAllowedSitesOnly(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(5);
        $controller = $this->get(DashboardSelectionController::class);

        self::assertSame(403, $controller($this->request()->withParsedBody(['site' => 'shop', 'period' => '30']))->getStatusCode());
        self::assertSame(200, $controller($this->request()->withParsedBody(['site' => 'main', 'period' => 'lastMonth']))->getStatusCode());
        self::assertSame(200, $controller($this->request()->withParsedBody(['compare' => '1']))->getStatusCode());
        self::assertSame(
            ['site' => 'main', 'period' => 'lastMonth', 'from' => '', 'to' => '', 'compare' => true],
            $GLOBALS['BE_USER']->uc['analytics_matomo']['dashboard'],
        );
    }

    #[Test]
    public function selectionAjaxRejectsEveryPayloadWithoutSiteStatisticsPermission(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_visitors',
        ], ['uid' => 1]);
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(4);
        $controller = $this->get(DashboardSelectionController::class);
        $stored = $GLOBALS['BE_USER']->uc;
        foreach ([[], ['period' => '30'], ['compare' => '1'], ['site' => 'main']] as $body) {
            $response = $controller($this->request()->withParsedBody($body));
            self::assertSame(403, $response->getStatusCode());
            self::assertSame(['success' => false], json_decode((string)$response->getBody(), true));
            self::assertSame($stored, $GLOBALS['BE_USER']->uc);
        }
        self::assertSame([], $this->sentRequests);
    }

    private function render(int $backendUserId, string $identifier, string $fixture): string
    {
        $this->sentRequests = [];
        $widget = $this->widget($backendUserId, $identifier);
        $this->fixture = $fixture;
        return $widget->renderWidgetContent();
    }

    private function widget(int $backendUserId, string $identifier): MatomoWidget
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser($backendUserId);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $widget = $this->get(WidgetRegistry::class)->getAvailableWidget($this->request(), $identifier);
        self::assertInstanceOf(MatomoWidget::class, $widget);
        return $widget;
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest('https://example.com/typo3/', 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }
}
