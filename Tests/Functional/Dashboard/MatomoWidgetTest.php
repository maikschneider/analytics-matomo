<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Dashboard;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Controller\DashboardSelectionController;
use MaikSchneider\AnalyticsMatomo\Dashboard\Widget\MatomoWidget;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
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
    public function topPagesShowsNoTitleOfPagesInLanguagesTheEditorMayNotSee(): void
    {
        $html = $this->render(5, 'analyticsMatomoTopPages', 'TopPagesMainSite');

        self::assertStringContainsString('>About</a>', $html);
        // Editor 5 may only edit the default language
        self::assertStringContainsString('<span title="https://example.com/de/ueber-uns">/de/ueber-uns</span>', $html);
    }

    #[Test]
    public function devicesSkipTypesWithoutVisitsAndProvideTheDoughnut(): void
    {
        $widget = $this->widget(1, 'analyticsMatomoDevices');
        $this->fixture = 'DevicesDetection.getType';

        $html = $widget->renderWidgetContent();

        self::assertStringContainsString('Desktop', $html);
        self::assertStringNotContainsString('Feature-Phone', $html);
        self::assertSame('doughnut', $widget->getEventData()['graphConfig']['type']);
        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertSame('en', $body['language']);
    }

    #[Test]
    public function channelComparesSharesInPercentagePoints(): void
    {
        $html = $this->render(1, 'analyticsMatomoChannel', 'Referrers.getReferrerType');

        self::assertStringContainsString('Suchmaschinen', $html);
        self::assertStringContainsString('+31.0 pp', $html);
    }

    #[Test]
    public function showsAHintWithoutVisits(): void
    {
        self::assertStringContainsString('No visits in this period.', $this->render(1, 'analyticsMatomoCountries', 'EmptyBreakdown'));
    }

    #[Test]
    public function showsMatomoErrorsToAdminsOnly(): void
    {
        $this->status = 401;

        self::assertStringContainsString('access for the website id = 1', $this->render(1, 'analyticsMatomoChannel', 'ErrorNoViewAccess'));
        self::assertStringNotContainsString('access for the website id = 1', $this->render(5, 'analyticsMatomoChannel', 'ErrorNoViewAccess'));
        self::assertStringContainsString('The statistics could not be loaded from Matomo.', $this->render(5, 'analyticsMatomoChannel', 'ErrorNoViewAccess'));
    }

    #[Test]
    public function showsNothingWithoutSiteStatisticsPermission(): void
    {
        $html = $this->render(3, 'analyticsMatomoPerformance', 'SitePerformance');

        self::assertStringContainsString('You are not allowed to view site statistics.', $html);
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function selectionIsStoredForAllowedSitesOnly(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(5);
        $controller = $this->get(DashboardSelectionController::class);

        self::assertSame(403, $controller($this->request()->withParsedBody(['site' => 'shop', 'days' => '30']))->getStatusCode());
        self::assertSame(200, $controller($this->request()->withParsedBody(['site' => 'main', 'days' => '30']))->getStatusCode());
        self::assertSame(['site' => 'main', 'days' => 30], $GLOBALS['BE_USER']->uc['analytics_matomo']['dashboard']);
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
