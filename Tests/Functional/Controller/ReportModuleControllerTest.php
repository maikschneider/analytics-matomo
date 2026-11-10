<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use MaikSchneider\AnalyticsMatomo\Module\ReportCatalog;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReportModuleControllerTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard', 'reports'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<RequestInterface> */
    private array $sentRequests = [];
    private string $fixture = 'SyntheticOverview';
    /** @var list<array{string, string}> reports the mocked Matomo does not offer: module, action */
    private array $missingReports = [];
    private int $status = 200;
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_visitors',
        ], ['uid' => 1]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $segments = $this->answerWithoutSegments($request);
            if ($segments !== null) {
                return $segments;
            }
            $this->sentRequests[] = $request;
            $content = (string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $this->fixture . '.json');
            if ($this->fixture === 'SyntheticOverview') {
                parse_str((string)$request->getBody(), $body);
                self::assertIsArray($body['urls']);
                $fixture = json_decode($content, true);
                $results = [];
                foreach ($body['urls'] as $url) {
                    parse_str($url, $parameters);
                    $key = $parameters['method'] === 'Live.getCounters' ? 'live' : ($parameters['period'] === 'range' ? 'range' : 'evolution');
                    if (($parameters['date'] ?? '') === '2026-10-20,2026-10-26') {
                        $key .= 'Previous';
                    }
                    $results[] = $fixture[$key];
                }
                $content = (string)json_encode($results);
            }
            if (str_starts_with($this->fixture, 'SyntheticProcessed') || str_starts_with($this->fixture, 'Processed')) {
                // One result per sub-request; single-report fixtures repeat for every report of a page
                parse_str((string)$request->getBody(), $body);
                self::assertIsArray($body['urls']);
                $results = json_decode($content, true);
                // Recorded answers for a present and a missing report
                $metadata = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/MetadataAvailability.json'), true);
                $answers = [];
                foreach (array_values($body['urls']) as $index => $url) {
                    parse_str($url, $parameters);
                    $answers[] = ($parameters['method'] ?? '') === 'API.getMetadata'
                        ? $metadata[in_array([$parameters['apiModule'] ?? '', $parameters['apiAction'] ?? ''], $this->missingReports, true) ? 1 : 0]
                        : $results[$index % count($results)];
                }
                $content = (string)json_encode($answers);
            }
            return new FulfilledPromise(new Response($this->status, [], $content));
        };
    }

    protected function tearDown(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN');
        if ($this->previousRequest === null) {
            unset($GLOBALS['TYPO3_REQUEST']);
        } else {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        }
        parent::tearDown();
    }

    #[Test]
    public function overviewShowsCachedAnomaliesAndLinksAdminsToConnectionHealth(): void
    {
        $configuration = new \MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration('https://matomo.example.com/', 3, 'secret-token');
        $this->get(\MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache::class)->set(['anomalies', $configuration->getCacheIdentifier(), '2026-11-02'], ['alerts' => [
            ['metric' => 'visits', 'date' => '2026-11-01', 'current' => 50.0, 'baseline' => 100.0, 'change' => -50.0],
        ]]);
        $html = (string)$this->render(1, ['id' => '1'])->getBody();
        self::assertStringContainsString('data-matomo-anomaly', $html);
        self::assertStringContainsString('−50', $html);
        self::assertStringContainsString('2026-11-01', $html);
        self::assertStringContainsString('Connection health', $html);
        $shop = (string)$this->render(1, ['id' => '10'])->getBody();
        self::assertStringNotContainsString('data-matomo-anomaly', $shop);
    }

    #[Test]
    public function adminSeesSectionsAndGlobalControls(): void
    {
        $response = $this->render(1, ['id' => '30']);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        foreach (['Visitors', 'Behaviour', 'Acquisition', 'Goals', 'Page details', 'Report page', 'Language', 'All languages', 'Period', 'Compare', 'data-date-type="date"', 'value="custom"'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringNotContainsString('site=shop', $html);
        self::assertStringNotContainsString('site=unconfigured', $html);
    }

    #[Test]
    public function allSectionsRenderTheirOwnReportPages(): void
    {
        foreach (['visitors' => 'Overview', 'behaviour' => 'Pages', 'acquisition' => 'All channels', 'goals' => 'Overview', 'page' => 'Page details'] as $section => $title) {
            $response = $this->render(1, ['id' => '30'], $section);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<h1>' . $title . '</h1>', (string)$response->getBody());
        }
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function overviewRendersTilesChartAndSeparatelyCachedLiveCounters(): void
    {
        $html = (string)$this->render(1, ['id' => '1'])->getBody();
        self::assertStringContainsString('tx-analytics-matomo-metric--visitors', $html);
        self::assertMatchesRegularExpression('#<script[^>]*src="[^"]*/evolution-chart\.js#', $html);
        self::assertStringContainsString('72.0 %', $html);
        self::assertStringContainsString('Last 30 minutes', $html);
        self::assertStringContainsString('data-counter="visits">9<', $html);
        self::assertStringContainsString('data-counter="actions">21<', $html);
        self::assertStringContainsString('data-counter="visitors">–<', $html);
        self::assertStringNotContainsString('tx-analytics-matomo-trend--', $html);
        $chart = $this->chartData($html);
        self::assertSame(12, $chart['metrics']['visits']['current'][0]);
        self::assertNull($chart['metrics']['visits']['current'][2]);
        self::assertArrayNotHasKey('previous', $chart['metrics']['visits']);
        $this->render(1, ['id' => '1']);
        self::assertCount(2, $this->sentRequests);
        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertIsArray($body['urls']);
        self::assertCount(2, $body['urls']);
        parse_str($body['urls'][1], $evolution);
        self::assertSame('API.get', $evolution['method']);
        self::assertSame('day', $evolution['period']);
        self::assertSame('2026-10-27,2026-11-02', $evolution['date']);
        parse_str((string)$this->sentRequests[1]->getBody(), $body);
        parse_str($body['urls'][0], $live);
        self::assertSame('Live.getCounters', $live['method']);
        self::assertSame('30', $live['lastMinutes']);
    }

    #[Test]
    public function overviewComparisonAddsPreviousSeriesAndTileChanges(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'compare' => '1'])->getBody();
        self::assertStringContainsString('tx-analytics-matomo-trend--positive', $html);
        self::assertStringContainsString('+6', $html);
        $chart = $this->chartData($html);
        self::assertSame(8, $chart['metrics']['visits']['previous'][0]);
        self::assertSame('2026-10-20', $chart['previousLabels'][0]);
        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertIsArray($body['urls']);
        self::assertCount(4, $body['urls']);
        parse_str($body['urls'][3], $previous);
        self::assertSame('2026-10-20,2026-10-26', $previous['date']);
    }

    #[Test]
    public function demoOverviewRendersEveryGranularityWithoutHttp(): void
    {
        foreach (['7' => 'day', '90' => 'week', 'custom' => 'month'] as $preset => $granularity) {
            $html = (string)$this->render(1, ['id' => '30', 'period' => (string)$preset, 'from' => '2024-01-01', 'to' => '2026-11-02', 'compare' => '1'])->getBody();
            $chart = $this->chartData($html);
            self::assertSame($granularity, $chart['granularity']);
            self::assertGreaterThan(0, $chart['metrics']['visits']['current'][0]);
            self::assertGreaterThan(0, $chart['metrics']['visits']['previous'][0]);
            self::assertStringContainsString('data-counter="visits">', $html);
        }
        self::assertSame([], $this->sentRequests);
    }

    /** @return array<string, mixed> */
    private function chartData(string $html): array
    {
        self::assertSame(1, preg_match('/data-evolution-chart="([^"]+)"/', $html, $matches));
        self::assertArrayHasKey(1, $matches);
        return json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function reportSectionsAndChartLabelUseCoreLayout(): void
    {
        foreach (['visitors' => '30', 'page' => '31'] as $section => $id) {
            $html = (string)$this->render(1, ['id' => $id], $section)->getBody();
            $document = new \DOMDocument();
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            self::assertSame(0, (int)$xpath->evaluate('count(//h2[not(ancestor::*[contains(@class,"card")])])'));
            self::assertSame(1, (int)$xpath->evaluate('count(//label[contains(@class,"text-nowrap") and @for="matomo-chart-metric"])'));
            self::assertSame(0, (int)$xpath->evaluate('count(//table[not(ancestor::div[contains(@class,"table-fit")])])'));
        }
    }

    #[Test]
    public function emptyReportsShowAnInfoboxInsteadOfAnEmptyTable(): void
    {
        $html = (string)$this->render(1, ['id' => '30', 'report' => 'pages', 'q' => 'nothing-matches'], 'behaviour')->getBody();
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        self::assertSame(0, (int)$xpath->evaluate('count(//table)'));
        self::assertSame(0, (int)$xpath->evaluate('count(//nav)'));
        self::assertGreaterThan(0, (int)$xpath->evaluate('count(//*[contains(@class,"callout-info")])'));
        self::assertStringContainsString('No matching rows.', $html);
    }

    #[Test]
    public function reportTablesUseCoreControlsAndAccessibleSortState(): void
    {
        $html = (string)$this->render(1, ['id' => '30', 'report' => 'pages', 'sort' => 'label', 'dir' => 'asc'], 'behaviour')->getBody();
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        self::assertGreaterThan(0, (int)$xpath->evaluate('count(//section[contains(@class,"card")]//input[@type="search" and contains(@class,"form-control")])'));
        self::assertSame(1, (int)$xpath->evaluate('count(//label/span[contains(@class,"text-nowrap")])'));
        self::assertGreaterThan(0, (int)$xpath->evaluate('count(//th[@aria-sort="ascending"]//*[@data-identifier="actions-sort-amount-up"])'));
        self::assertGreaterThan(0, (int)$xpath->evaluate('count(//nav//ul[contains(@class,"pagination")])'));
        foreach (['actions-search', 'actions-document-info', 'actions-document-open', 'actions-page-open', 'actions-view'] as $icon) {
            self::assertStringContainsString('data-identifier="' . $icon . '"', $html);
        }
    }

    #[Test]
    public function customRangeUsesCoreStyledDatePickers(): void
    {
        $html = (string)$this->render(1, ['id' => '30'])->getBody();
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        self::assertSame(2, (int)$xpath->evaluate('count(//input[@data-date-type="date" and @type="text" and contains(@class,"form-control")])'));
        self::assertSame(0, (int)$xpath->evaluate('count(//input[@type="date"])'));
        self::assertStringContainsString('/module-filters.js', $html);
        self::assertSame(1, (int)$xpath->evaluate('count(//button[@type="submit"]//*[@data-identifier="actions-check"])'));
    }

    #[Test]
    public function missingTreeSelectionNeverFallsBackToTheStoredOrSubmittedSite(): void
    {
        $this->get(DashboardSelectionResolver::class)->save($this->setUpBackendUser(1), 'trial');
        foreach (['visitors', 'behaviour', 'acquisition', 'goals', 'page'] as $section) {
            $html = (string)$this->render(1, ['site' => 'main'], $section)->getBody();
            self::assertStringContainsString('Select a page in the page tree', $html);
            self::assertStringNotContainsString('data-site=', $html);
            self::assertStringNotContainsString('site=shop', $html);
        }
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function pageWithoutSiteShowsTheTreeHint(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => 40, 'pid' => 0, 'title' => 'No site']);
        $html = (string)$this->render(1, ['id' => '40', 'site' => 'main'])->getBody();
        self::assertStringContainsString('Select a page in the page tree', $html);
        self::assertStringNotContainsString('data-site=', $html);
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function pageTreePicksAndSharesTheSiteInsteadOfTheStoredSite(): void
    {
        $this->get(DashboardSelectionResolver::class)->save($this->setUpBackendUser(1), 'trial');
        $html = (string)$this->render(1, ['id' => '11', 'site' => 'main'])->getBody();

        self::assertStringContainsString('data-site="shop"', $html);
        self::assertStringContainsString('data-page="11"', $html);
        self::assertStringNotContainsString('site=main', $html);
        self::assertStringContainsString('id=11', $html);
        self::assertSame('shop', $this->get(DashboardSelectionResolver::class)->resolve($this->setUpBackendUser(1))?->site->getIdentifier());
    }

    #[Test]
    public function periodAndCompareAreSharedWithTheWidgetSelection(): void
    {
        $this->render(1, ['id' => '30', 'period' => 'lastMonth', 'compare' => '1']);
        $selection = $this->get(DashboardSelectionResolver::class)->resolve($this->setUpBackendUser(1));

        self::assertSame('2026-10-01,2026-10-31', $selection?->period->toMatomoDate());
        self::assertTrue($selection->compare);
    }

    #[Test]
    public function customRangeCanBeSubmittedWithoutLosingTheTreeContext(): void
    {
        $html = (string)$this->render(1, ['id' => '31'], body: ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-17'])->getBody();
        $selection = $this->get(DashboardSelectionResolver::class)->resolve($this->setUpBackendUser(1));

        self::assertSame('2026-09-01,2026-09-17', $selection?->period->toMatomoDate());
        self::assertStringContainsString('id=31', $html);
    }

    #[Test]
    public function editorCannotRequestASectionNotGrantedToTheirGroup(): void
    {
        $user = $this->setUpBackendUser(5);
        $provider = $this->get(ModuleProvider::class);
        self::assertNotNull($provider->getModule('analytics_matomo', $user));
        self::assertTrue($provider->accessGranted('analytics_matomo_visitors', $user));
        self::assertFalse($provider->accessGranted('analytics_matomo_behaviour', $user));

        $response = $this->render(5, [], 'behaviour');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/module/analytics-matomo?', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('/behaviour', $response->getHeaderLine('Location'));
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function moduleAccessAloneAllowsReportsAndSharedSelection(): void
    {
        $response = $this->render(4, ['id' => '1', 'period' => 'lastMonth', 'compare' => '1']);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('tx-analytics-matomo-metric--visitors', $html);
        self::assertStringContainsString('data-site="main"', $html);
        self::assertStringNotContainsString('site=shop', $html);
        self::assertCount(2, $this->sentRequests);
        $user = $this->setUpBackendUser(4);
        self::assertSame('lastMonth', $user->uc['analytics_matomo']['dashboard']['period']);
        self::assertTrue($user->uc['analytics_matomo']['dashboard']['compare']);
        self::assertNull($this->get(DashboardSelectionResolver::class)->resolve($user));
    }

    #[Test]
    public function controllerItselfDeniesEverySectionWithoutModuleAccess(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['groupMods' => 'analytics_matomo'], ['uid' => 1]);
        foreach (['visitors', 'behaviour', 'acquisition', 'goals', 'page'] as $section) {
            $response = $this->render(5, ['id' => '30'], $section, validate: false);
            self::assertSame(403, $response->getStatusCode());
        }
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function controllerRequiresParentModuleEvenWhenSectionIsGranted(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo_visitors',
        ], ['uid' => 1]);

        self::assertSame(403, $this->render(5, validate: false)->getStatusCode());
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function noModuleAccessAndNoSiteOptionExposeNoReports(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => '',
        ], ['uid' => 1]);
        self::assertSame(403, $this->render(4, validate: false)->getStatusCode());
        self::assertNull($this->get(DashboardSelectionResolver::class)->resolve($this->setUpBackendUser(4)));
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function moduleOnlyUserCannotSelectAnInaccessibleSite(): void
    {
        $html = (string)$this->render(4, ['id' => '10'], validate: false)->getBody();

        self::assertStringContainsString('No accessible site with a Matomo connection is configured.', $html);
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function unconfiguredTreeSiteDoesNotFallBackToAnotherSite(): void
    {
        $html = (string)$this->render(1, ['id' => '20'])->getBody();

        self::assertStringContainsString('No accessible site with a Matomo connection is configured.', $html);
        self::assertStringNotContainsString('data-site="main"', $html);
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function inaccessibleTreeSiteDoesNotFallBackToAnotherSite(): void
    {
        $html = (string)$this->render(5, ['id' => '10'], validate: false)->getBody();

        self::assertStringContainsString('No accessible site with a Matomo connection is configured.', $html);
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function reportAndLanguageAreModuleDataAndRestrictedToTheCurrentSite(): void
    {
        $this->fixture = 'ProcessedVisitorsDevices';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices', 'language' => '1'])->getBody();

        self::assertStringContainsString('data-language="1"', $html);
        self::assertStringContainsString('<h1>Devices</h1>', $html);
        self::assertStringContainsString('Deutsch', $html);
        $stored = $this->setUpBackendUser(1)->getModuleData('analytics_matomo_visitors');
        self::assertSame('devices', $stored['report']);
        self::assertSame(1, $stored['language']);

        $this->fixture = 'SyntheticOverview';
        $html = (string)$this->render(5, ['id' => '1', 'language' => '1'])->getBody();
        self::assertStringContainsString('data-language="-1"', $html);
        self::assertStringNotContainsString('language=1', $html);
        self::assertStringContainsString('language=0', $html);
        self::assertStringNotContainsString('site=shop', $html);
    }

    #[Test]
    public function invalidReportFallsBackToTheSectionDefault(): void
    {
        $html = (string)$this->render(1, ['id' => '30', 'report' => 'missing'], 'behaviour')->getBody();

        self::assertStringContainsString('<h1>Pages</h1>', $html);
        self::assertSame('pages', $this->setUpBackendUser(1)->getModuleData('analytics_matomo_behaviour')['report']);
    }

    #[Test]
    public function customPeriodMenuOpensTheRangeFormWithoutResettingTheSelection(): void
    {
        $html = (string)$this->render(1, ['id' => '30', 'period' => 'lastMonth'])->getBody();

        self::assertStringContainsString('Period: Last month', $html);
        self::assertStringContainsString('href="#matomo-custom-range"', $html);
        self::assertStringNotContainsString('period=custom', $html);
        self::assertMatchesRegularExpression('#<form id="matomo-custom-range"[^>]* hidden#', $html);

        $html = (string)$this->render(1, ['id' => '30'], body: ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-17'])->getBody();

        self::assertDoesNotMatchRegularExpression('#<form id="matomo-custom-range"[^>]* hidden#', $html);
        self::assertStringContainsString('value="2026-09-01"', $html);
    }

    #[Test]
    public function matomoErrorDetailsAreOnlyShownToAdmins(): void
    {
        $this->status = 401;
        $this->fixture = 'ErrorNoViewAccess';
        $admin = (string)$this->render(1, ['id' => '1'])->getBody();
        $editor = (string)$this->render(5, ['id' => '1'])->getBody();

        self::assertStringContainsString('The statistics could not be loaded from Matomo.', $admin);
        self::assertStringContainsString('access for the website id = 1', $admin);
        self::assertStringContainsString('The statistics could not be loaded from Matomo.', $editor);
        self::assertStringNotContainsString('access for the website id = 1', $editor);
        self::assertStringNotContainsString('data-evolution-chart=', $editor);
        self::assertDoesNotMatchRegularExpression('#<script[^>]*src="[^"]*/evolution-chart\.js#', $editor);
        $this->status = 200;
        $this->fixture = 'SyntheticOverview';
        $recovered = (string)$this->render(1, ['id' => '1'])->getBody();
        self::assertStringContainsString('data-evolution-chart=', $recovered);
        self::assertCount(4, $this->sentRequests);
    }

    #[Test]
    public function visitorsReportPagesRenderEveryRecordedReport(): void
    {
        $pages = [
            'locations' => ['Country' => 'United States', 'Region' => 'Shanghai Shi, China', 'City' => 'Shanghai, Shanghai Shi, China', 'Web-browser language' => 'English'],
            'devices' => ['Device type' => 'Desktop', 'Device brand' => 'Unknown', 'Device model' => 'Generic Desktop', 'Screen Resolution' => '800x600'],
            'software' => ['Operating System families' => 'Windows', 'Browsers' => 'Chrome', 'Browser engines' => 'Blink (Chrome, Opera)'],
            'times' => ['Visits per local time' => '00', 'Visits per hour in the site&#039;s timezone' => '00', 'Visits by day of the week' => 'Monday'],
            'engagement' => ['Returning Visits' => 'New Visits', 'Length of visits' => '0–10s', 'Pages per visit' => '1 page', 'Visits by visit number' => '1 visit', 'Visits by days since last visit' => 'New visits'],
        ];
        foreach ($pages as $report => $tables) {
            $this->fixture = 'ProcessedVisitors' . ucfirst($report);
            $html = (string)$this->render(1, ['id' => '1', 'report' => $report])->getBody();
            foreach ($tables as $title => $firstLabel) {
                self::assertStringContainsString('<h2>' . $title . '</h2>', $html, $report);
                self::assertMatchesRegularExpression('#<td>(<span [^>]*data-identifier="flags-[a-z]+".*?</span> )?' . preg_quote($firstLabel, '#') . '</td>#s', $html, $report);
            }
            self::assertSame(count($tables), substr_count($html, '<h2>'), $report);
        }
        self::assertCount(5, $this->sentRequests);
    }

    #[Test]
    public function dimensionReportsArePerMatomoSiteAndCoverAllLanguages(): void
    {
        $this->fixture = 'ProcessedVisitorsLocations';
        $german = (string)$this->render(1, ['id' => '1', 'report' => 'locations', 'language' => '1'])->getBody();
        $default = (string)$this->render(1, ['id' => '1', 'language' => '0'])->getBody();
        $this->render(1, ['id' => '10', 'language' => '1']);

        self::assertStringContainsString('</span> United States</td>', $german);
        self::assertSame(substr($german, (int)strpos($german, '<h2>')), substr($default, (int)strpos($default, '<h2>')));
        self::assertCount(2, $this->sentRequests);
        $sites = [];
        foreach ($this->sentRequests as $request) {
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            parse_str($body['urls'][0], $subRequest);
            $sites[] = $subRequest['idSite'];
        }
        self::assertSame(['3', '7'], $sites);
    }

    #[Test]
    public function locationReportsShowTheCoreFlagOfTheCountryBeforeTheName(): void
    {
        $this->fixture = 'ProcessedVisitorsLocations';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'locations'])->getBody();

        self::assertMatchesRegularExpression('#<td><span [^>]*data-identifier="flags-us"[^>]*aria-hidden="true".*?</span> United States</td>#s', $html);
        self::assertMatchesRegularExpression('#<td><span [^>]*data-identifier="flags-cn".*?</span> Shanghai Shi, China</td>#s', $html);
        self::assertMatchesRegularExpression('#<td><span [^>]*data-identifier="flags-cn".*?</span> Shanghai, Shanghai Shi, China</td>#s', $html);
        self::assertStringContainsString('<td>English</td>', $html);
        self::assertStringNotContainsString('default-not-found', $html);
        self::assertStringNotContainsString('Morpheus', $html);
    }

    #[Test]
    public function interactionReportPagesRenderRecordedReportsAndNameTheTrackingRequirement(): void
    {
        $pages = [
            'outlinks' => ['<h2>Outlinks</h2>', '<td>clubelek.insa-lyon.fr/</td>'],
            'downloads' => ['<h2>Downloads</h2>', 'enableLinkTracking'],
            'events' => ['<h2>Event Categories</h2>', '<h2>Event Actions</h2>', '<h2>Event Names</h2>', '<td>MediaVideo - pause</td>'],
            'contents' => ['<h2>Content Name</h2>', '<h2>Content Piece</h2>', 'trackAllContentImpressions'],
        ];
        foreach ($pages as $report => $snippets) {
            $this->fixture = 'ProcessedBehaviour' . ucfirst($report);
            $html = (string)$this->render(1, ['id' => '1', 'report' => $report], 'behaviour')->getBody();
            foreach ($snippets as $snippet) {
                self::assertStringContainsString($snippet, $html, $report);
            }
        }
        $this->fixture = 'ProcessedBehaviourEvents';
        $search = (string)$this->render(1, ['id' => '1', 'report' => 'events', 'q' => 'nothing-matches'], 'behaviour')->getBody();
        self::assertStringNotContainsString('trackEvent', $search);
        self::assertStringContainsString('No matching rows.', $search);
    }

    #[Test]
    public function acquisitionReportPagesRenderRecordedReports(): void
    {
        $pages = [
            'channels' => ['<h2>Channel Type</h2>', '<h2>All Channels</h2>', '<td>Keyword not defined</td>'],
            'websites' => ['<h2>Websites</h2>', '<td>developer.matomo.org</td>'],
            'searchEngines' => ['<h2>Search Engines</h2>', '<h2>Keywords</h2>', '<td>Google</td>'],
            'socialNetworks' => ['<h2>Social Networks</h2>', 'No matching rows.'],
            'aiAssistants' => ['<h2>AI Assistants</h2>', '<td>ChatGPT</td>'],
        ];
        foreach ($pages as $report => $snippets) {
            $this->fixture = 'ProcessedAcquisition' . ucfirst($report);
            $html = (string)$this->render(1, ['id' => '1', 'report' => $report], 'acquisition')->getBody();
            foreach ($snippets as $snippet) {
                self::assertStringContainsString($snippet, $html, $report);
            }
        }
        $this->fixture = 'SyntheticProcessedCampaigns';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'campaigns'], 'acquisition')->getBody();
        self::assertStringContainsString('<h2>Campaigns</h2>', $html);
        self::assertStringContainsString('<h2>Campaign keywords</h2>', $html);
        self::assertStringContainsString('<td>spring-sale - newsletter</td>', $html);
        $actions = [];
        foreach ($this->sentRequests as $request) {
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            foreach ($body['urls'] as $url) {
                self::assertIsString($url);
                parse_str($url, $subRequest);
                $actions[] = [$subRequest['apiAction'] ?? null, $subRequest['flat'] ?? null];
            }
        }
        self::assertContains(['getCampaigns', '1'], $actions);
    }

    #[Test]
    public function reportPagesAreHiddenWhenMatomoLacksTheirReport(): void
    {
        $this->fixture = 'ProcessedAcquisitionChannels';
        $this->missingReports = [['Referrers', 'getAIAssistants']];
        $html = (string)$this->render(1, ['id' => '1'], 'acquisition')->getBody();
        self::assertStringContainsString('report=campaigns', $html);
        self::assertStringNotContainsString('report=aiAssistants', $html);

        $direct = (string)$this->render(1, ['id' => '1', 'report' => 'aiAssistants'], 'acquisition')->getBody();
        self::assertStringContainsString('<h1>All channels</h1>', $direct);
        self::assertStringNotContainsString('<h2>AI Assistants</h2>', $direct);

        $this->missingReports = [['Referrers', 'getCampaigns']];
        $this->render(1, ['id' => '1'], 'acquisition');
        self::assertCount(2, $this->sentRequests, 'Availability and reports are cached');
        $this->render(1, ['id' => '10', 'report' => 'campaigns'], 'acquisition');
        self::assertCount(4, $this->sentRequests, 'Every Matomo site has its own availability');
    }

    #[Test]
    public function disabledSiteSearchShowsSetupWithoutRequestingReports(): void
    {
        $this->fixture = 'ProcessedBehaviourSiteSearch';
        $this->missingReports = [['Actions', 'getSiteSearchKeywords']];
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'siteSearch'], 'behaviour')->getBody();

        self::assertStringContainsString('Enable site search in the Matomo website settings', $html);
        self::assertStringNotContainsString('The statistics could not be loaded', $html);
        self::assertStringContainsString('<h1>Site search</h1>', $html);
        self::assertCount(1, $this->sentRequests);
        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertIsArray($body['urls']);
        foreach ($body['urls'] as $url) {
            self::assertIsString($url);
            self::assertStringContainsString('method=API.getMetadata', $url);
        }
    }

    #[Test]
    public function siteSearchNeedsItsOwnPermissionAndExplainsTheSetup(): void
    {
        $this->fixture = 'SyntheticProcessedSiteSearch';
        $admin = (string)$this->render(1, ['id' => '1', 'report' => 'siteSearch'], 'behaviour')->getBody();
        foreach (['<h2>Site Search Keywords</h2>', '<td>opening hours</td>', '<h2>Search Keywords with No Results</h2>', '<td>parking dresden</td>', '<h2>Search Categories</h2>', 'report=siteSearch'] as $snippet) {
            self::assertStringContainsString($snippet, $admin);
        }

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('be_groups');
        $connection->update('be_groups', ['groupMods' => 'analytics_matomo,analytics_matomo_behaviour'], ['uid' => 1]);
        $editor = (string)$this->render(5, ['id' => '1', 'report' => 'siteSearch'], 'behaviour')->getBody();
        self::assertStringNotContainsString('report=siteSearch', $editor);
        self::assertStringNotContainsString('opening hours', $editor);
        self::assertStringContainsString('<h1>Pages</h1>', $editor);

        $connection->update('be_groups', ['custom_options' => 'analytics_matomo:siteStatistics,analytics_matomo:siteSearch'], ['uid' => 3]);
        $granted = (string)$this->render(5, ['id' => '1', 'report' => 'siteSearch'], 'behaviour')->getBody();
        self::assertStringContainsString('<td>opening hours</td>', $granted);

        $this->fixture = 'ProcessedBehaviourSiteSearch';
        $empty = (string)$this->render(1, ['id' => '10', 'report' => 'siteSearch'], 'behaviour')->getBody();
        self::assertStringContainsString('trackSiteSearch', $empty);
    }

    #[Test]
    public function behaviourReportPagesRenderRecordedReports(): void
    {
        $pages = [
            'entryPages' => ['<h2>Entry pages</h2>', 'No matching rows.'],
            'exitPages' => ['<h2>Exit pages</h2>', 'No matching rows.'],
            'pageTitles' => ['<h2>Page titles</h2>', '<td>Virtual Drums: A 3D drum set!</td>'],
            'performance' => ['<h2>Performance overview</h2>', '<td>Avg. page load time</td>', '<td>1.56 s</td>', '<h2>Slowest pages</h2>', 'No matching rows.'],
        ];
        foreach ($pages as $report => $snippets) {
            $this->fixture = 'ProcessedBehaviour' . ucfirst($report);
            $html = (string)$this->render(1, ['id' => '1', 'report' => $report], 'behaviour')->getBody();
            foreach ($snippets as $snippet) {
                self::assertStringContainsString($snippet, $html, $report);
            }
        }
        self::assertStringNotContainsString('Web Vitals', $html);
        parse_str((string)$this->sentRequests[3]->getBody(), $body);
        self::assertIsArray($body['urls']);
        parse_str($body['urls'][1], $slowest);
        self::assertSame(['getPageUrls', '1', '1'], [$slowest['apiAction'], $slowest['flat'], $slowest['showRawMetrics']]);
    }

    #[Test]
    public function entryAndExitPagesFilterUrlsBySiteLanguageAndSubtree(): void
    {
        $this->fixture = 'SyntheticProcessedPages';
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => 8, 'pid' => 2, 'title' => 'Child', 'slug' => '/about/child', 'perms_everybody' => 1]);
        foreach (['entryPages', 'exitPages'] as $report) {
            $german = $this->tableHtml(1, ['id' => '1', 'language' => '1', 'report' => $report]);
            self::assertStringContainsString('main-german', $german, $report);
            self::assertStringNotContainsString('main-about', $german, $report);
            self::assertStringNotContainsString('shop-german', $german, $report);
            $subtree = $this->tableHtml(1, ['id' => '2', 'language' => '-1', 'report' => $report]);
            self::assertStringContainsString('child-page', $subtree, $report);
            self::assertStringNotContainsString('hidden-page', $subtree, $report);
            $shop = $this->tableHtml(1, ['id' => '10', 'language' => '1', 'report' => $report]);
            self::assertStringContainsString('shop-german', $shop, $report);
            self::assertStringNotContainsString('main-german', $shop, $report);
        }
        $actions = [];
        foreach ($this->sentRequests as $request) {
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            parse_str($body['urls'][0], $subRequest);
            self::assertIsString($subRequest['apiAction']);
            $actions[$subRequest['apiAction']] = $subRequest['flat'];
        }
        self::assertSame(['getEntryPageUrls' => '1', 'getExitPageUrls' => '1'], $actions);
    }

    #[Test]
    public function slowestPagesNeedEnoughSamplesAndRespectSiteAndLanguage(): void
    {
        $this->fixture = 'SyntheticProcessedPerformance';
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => 8, 'pid' => 2, 'title' => 'Child', 'slug' => '/about/child', 'perms_everybody' => 1]);
        $main = $this->tableHtml(1, ['id' => '1', 'report' => 'performance']);
        $slowest = substr($main, (int)strpos($main, '<h2>Slowest pages</h2>'));
        self::assertLessThan((int)strpos($slowest, 'main-about'), (int)strpos($slowest, 'main-german'));
        self::assertStringContainsString('<td>3.40 s</td>', $slowest);
        self::assertStringNotContainsString('child-page', $slowest);
        self::assertStringNotContainsString('shop-german', $slowest);
        $german = $this->tableHtml(1, ['id' => '1', 'language' => '1', 'report' => 'performance']);
        self::assertStringNotContainsString('main-about', $german);
        self::assertStringContainsString('main-german', $german);
        $shop = $this->tableHtml(1, ['id' => '10', 'report' => 'performance']);
        self::assertStringContainsString('shop-german', $shop);
    }

    #[Test]
    public function reportsWithoutDimensionRenderAsMetricList(): void
    {
        $this->fixture = 'ProcessedVisitorsEngagement';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'engagement', 'compare' => '1'])->getBody();
        $start = (int)strpos($html, '<h2>Returning Visits</h2>');
        $table = substr($html, $start, (int)strpos($html, '<h2>', $start + 1) - $start);

        self::assertStringContainsString('<td>New Visits</td>', $table);
        self::assertStringContainsString('<td>99</td>', $table);
        self::assertStringContainsString('<td>Bounce Rate for New Visits</td>', $table);
        self::assertStringContainsString('<td>82.0 %</td>', $table);
        self::assertStringContainsString('Change', $table);
        self::assertStringNotContainsString('type="search"', $table);
        self::assertStringNotContainsString('<nav', $table);
    }

    #[Test]
    public function demoReportPagesRenderTablesWithoutHttp(): void
    {
        $catalog = $this->get(ReportCatalog::class);
        foreach (['visitors', 'behaviour', 'acquisition'] as $section) {
            foreach (array_keys($catalog->getPages($section, $this->setUpBackendUser(1))) as $report) {
                $expected = count($catalog->getReports($section, $report));
                if ($expected === 0) {
                    continue;
                }
                $html = (string)$this->render(1, ['id' => '30', 'report' => $report], $section)->getBody();
                self::assertSame($expected, substr_count($html, 'class="table table-striped'), $report);
                self::assertSame($expected, preg_match_all('#<tbody>\s*<tr>#', $html), $report);
                self::assertStringNotContainsString('First', $html, $report);
            }
        }
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function processedReportsAreFetchedInOneBulkAndReusedForTableControls(): void
    {
        $this->fixture = 'ProcessedVisitorsDevices';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();
        self::assertStringContainsString('Device type', $html);
        self::assertStringContainsString('Desktop', $html);
        self::assertDoesNotMatchRegularExpression('#<script[^>]*src="[^"]*/evolution-chart\.js#', $html);
        self::assertStringContainsString('recorded as a new visit', $html);
        $this->render(1, ['id' => '1', 'q' => 'Desktop', 'sort' => 'nb_visits', 'dir' => 'asc', 'page' => '2']);
        self::assertCount(1, $this->sentRequests);
        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertSame('API.getBulkRequest', $body['method']);
        self::assertSame('en', $body['language']);
        self::assertIsArray($body['urls']);
        self::assertCount(4, $body['urls']);
        parse_str($body['urls'][0], $subRequest);
        self::assertSame('API.getProcessedReport', $subRequest['method']);
        self::assertSame('DevicesDetection', $subRequest['apiModule']);
        self::assertSame('0', $subRequest['format_metrics']);
        self::assertSame('-1', $subRequest['filter_limit']);
    }

    #[Test]
    public function urlRowsRespectSiteLanguageSubtreeAndPageAccess(): void
    {
        $this->fixture = 'SyntheticProcessedPages';
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->insert('pages', ['uid' => 8, 'pid' => 2, 'title' => 'Child', 'slug' => '/about/child', 'perms_everybody' => 1]);
        $connection->insert('pages', ['uid' => 9, 'pid' => 1, 'title' => 'Restricted', 'slug' => '/restricted', 'perms_everybody' => 0]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['groupMods' => 'analytics_matomo,analytics_matomo_visitors,analytics_matomo_behaviour'], ['uid' => 1]);

        $main = $this->tableHtml(1, ['id' => '1']);
        self::assertStringContainsString('main-about', $main);
        self::assertStringContainsString('main-german', $main);
        self::assertStringContainsString('hidden-page', $main);
        self::assertStringContainsString('unknown-page', $main);
        self::assertStringNotContainsString('shop-products', $main);
        self::assertStringContainsString('pages may be missing', $main);
        self::assertStringContainsString('/analytics-matomo/page?', $main);
        self::assertStringContainsString('/layout?', $main);
        self::assertStringContainsString('record/edit?', $main);

        $german = $this->tableHtml(1, ['id' => '1', 'language' => '1']);
        self::assertStringContainsString('main-german', $german);
        self::assertStringNotContainsString('main-about', $german);
        self::assertStringContainsString('language=1', $german);
        $subtree = $this->tableHtml(1, ['id' => '2', 'language' => '-1']);
        self::assertStringContainsString('main-about', $subtree);
        self::assertStringContainsString('child-page', $subtree);
        self::assertStringNotContainsString('hidden-page', $subtree);
        self::assertStringNotContainsString('unknown-page', $subtree);
        $shop = $this->tableHtml(1, ['id' => '10', 'language' => '1']);
        self::assertStringContainsString('shop-german', $shop);
        self::assertStringNotContainsString('main-german', $shop);
        $editor = $this->tableHtml(5, ['id' => '1', 'language' => '-1']);
        self::assertStringContainsString('main-about', $editor);
        self::assertStringContainsString('hidden-page', $editor);
        self::assertStringNotContainsString('main-german', $editor);
        self::assertStringNotContainsString('unknown-page', $editor);
        self::assertStringNotContainsString('restricted-page', $editor);
        self::assertCount(2, $this->sentRequests);
    }

    #[Test]
    public function hiddenPageRowActionsLinkToTheirFrontendSlugs(): void
    {
        $this->fixture = 'SyntheticProcessedPages';
        $html = $this->tableHtml(1, ['id' => '1']);
        self::assertStringContainsString('href="https://example.com/summer-sale"', $html);
        self::assertStringContainsString('href="https://example.com/de/sommerschlussverkauf"', $html);
    }

    #[Test]
    public function compareAddsPreviousSubRequestAndMatchesRowsByUrl(): void
    {
        $this->fixture = 'SyntheticProcessedPages';
        $html = $this->tableHtml(1, ['id' => '1', 'compare' => '1']);
        self::assertStringContainsString('Change', $html);
        self::assertStringContainsString('+50.0 %', $html);
        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertIsArray($body['urls']);
        self::assertCount(2, $body['urls']);
        parse_str($body['urls'][0], $current);
        parse_str($body['urls'][1], $previous);
        self::assertSame('1', $current['flat']);
        self::assertArrayNotHasKey('apiParameters', $current);
        self::assertSame('2026-10-27,2026-11-02', $current['date']);
        self::assertSame('2026-10-20,2026-10-26', $previous['date']);
    }

    #[Test]
    public function processedReportFailuresAreNotCachedAndDetailsStayAdminOnly(): void
    {
        $this->fixture = 'ErrorNoViewAccess';
        $this->status = 401;
        $admin = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();
        $editor = (string)$this->render(5, ['id' => '1', 'report' => 'devices'])->getBody();
        self::assertTrue(str_contains($admin, 'access for the website id = 1'));
        self::assertFalse(str_contains($editor, 'access for the website id = 1'));
        self::assertTrue(str_contains($editor, 'The statistics could not be loaded from Matomo.'));
        $this->fixture = 'ProcessedVisitorsDevices';
        $this->status = 200;
        $recovered = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();
        self::assertTrue(str_contains($recovered, 'Desktop'));
        self::assertCount(3, $this->sentRequests);
    }

    #[Test]
    public function recordedChannelsRenderTheirMetricValues(): void
    {
        $this->fixture = 'ProcessedChannels';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'channels'], 'acquisition')->getBody();
        self::assertStringContainsString('class="table', $html);
        self::assertStringContainsString('Channel Type', $html);
        self::assertStringContainsString('<td>Search Engines</td>', $html);
        self::assertStringContainsString('<td>241,403</td>', $html);
        self::assertStringContainsString('<td>55.1 %</td>', $html);
        self::assertStringContainsString('<td>0:01:45</td>', $html);
    }

    #[Test]
    public function backendLanguageIsSentToMatomoAndSeparatesCachedReports(): void
    {
        $this->fixture = 'ProcessedVisitorsDevices';
        $this->render(1, ['id' => '1', 'report' => 'devices']);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['lang' => 'de'], ['uid' => 1]);
        $this->render(1, ['id' => '1', 'report' => 'devices']);
        self::assertCount(2, $this->sentRequests);
        parse_str((string)$this->sentRequests[1]->getBody(), $body);
        self::assertSame('de', $body['language']);
    }

    #[Test]
    public function matomoLabelsAndDocumentationAreEscapedByFluid(): void
    {
        $this->fixture = 'SyntheticProcessedUnsafe';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();
        $table = substr($html, (int)strpos($html, '<h2>'));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $table);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $table);
        self::assertStringContainsString('&quot; onmouseover=&quot;alert(1)', $table);
        self::assertStringNotContainsString('<img', $table);
        self::assertStringNotContainsString('<script', $table);
    }

    #[Test]
    public function malformedProcessedReportsAreRetriedRatherThanCached(): void
    {
        $this->fixture = 'SyntheticProcessedInvalid';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();
        self::assertTrue(str_contains($html, 'unexpected processed report'));
        $this->fixture = 'ProcessedVisitorsDevices';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();
        self::assertTrue(str_contains($html, 'Desktop'));
        self::assertCount(2, $this->sentRequests);
    }

    /** @param array<string, string> $query */
    private function tableHtml(int $user, array $query): string
    {
        $html = (string)$this->render($user, $query + ['report' => 'pages'], 'behaviour')->getBody();
        return substr($html, (int)strpos($html, '<h2>'));
    }
}
