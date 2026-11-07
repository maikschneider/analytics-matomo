<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Controller\ReportModuleController;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Middleware\BackendModuleValidator;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReportModuleControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<RequestInterface> */
    private array $sentRequests = [];
    private string $fixture = 'SitePerformance';
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
            $this->sentRequests[] = $request;
            $content = (string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $this->fixture . '.json');
            if ($this->fixture === 'SyntheticProcessedPages') {
                parse_str((string)$request->getBody(), $body);
                self::assertIsArray($body['urls']);
                $content = (string)json_encode(array_slice(json_decode($content, true), 0, count($body['urls'])));
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
    public function adminSeesSectionsAndGlobalControls(): void
    {
        $response = $this->render(1, ['site' => 'trial']);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        foreach (['Visitors', 'Behaviour', 'Acquisition', 'Goals', 'Page details', 'Report page', 'Site', 'Language', 'All languages', 'Period', 'Compare', 'type="date"', 'value="custom"'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringContainsString('site=shop', $html);
        self::assertStringNotContainsString('site=unconfigured', $html);
    }

    #[Test]
    public function allSectionsRenderTheirOwnReportPages(): void
    {
        foreach (['visitors' => 'Overview', 'behaviour' => 'Pages', 'acquisition' => 'All channels', 'goals' => 'Goals', 'page' => 'Page details'] as $section => $title) {
            $response = $this->render(1, ['site' => 'trial'], $section);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<h1>' . $title . '</h1>', (string)$response->getBody());
        }
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function overviewReusesSitePerformanceAndOnlyShowsChangesWhenComparing(): void
    {
        $withoutComparison = (string)$this->render(1)->getBody();
        $withComparison = (string)$this->render(1, ['compare' => '1'])->getBody();

        self::assertStringContainsString('72.0 %', $withoutComparison);
        self::assertStringNotContainsString('<small>+6</small>', $withoutComparison);
        self::assertStringContainsString('<small>+6</small>', $withComparison);
        self::assertCount(1, $this->sentRequests);
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
        $this->render(1, ['site' => 'trial', 'period' => 'lastMonth', 'compare' => '1']);
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
    public function missingSitePermissionShowsAnEmptyStateWithoutContactingMatomo(): void
    {
        $html = (string)$this->render(4)->getBody();

        self::assertStringContainsString('You are not allowed to view site statistics.', $html);
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
    public function inaccessibleSiteParameterDoesNotFallBackToAnotherSite(): void
    {
        $html = (string)$this->render(5, ['site' => 'shop'])->getBody();

        self::assertStringContainsString('No accessible site with a Matomo connection is configured.', $html);
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function reportAndLanguageAreModuleDataAndRestrictedToTheCurrentSite(): void
    {
        $this->fixture = 'ProcessedDevices';
        $html = (string)$this->render(1, ['site' => 'main', 'report' => 'devices', 'language' => '1'])->getBody();

        self::assertStringContainsString('data-language="1"', $html);
        self::assertStringContainsString('<h1>Devices</h1>', $html);
        self::assertStringContainsString('Deutsch', $html);
        $stored = $this->setUpBackendUser(1)->getModuleData('analytics_matomo_visitors');
        self::assertSame('devices', $stored['report']);
        self::assertSame(1, $stored['language']);

        $this->fixture = 'SitePerformance';
        $html = (string)$this->render(5, ['language' => '1'])->getBody();
        self::assertStringContainsString('data-language="-1"', $html);
        self::assertStringNotContainsString('language=1', $html);
        self::assertStringContainsString('language=0', $html);
        self::assertStringNotContainsString('site=shop', $html);
    }

    #[Test]
    public function invalidReportFallsBackToTheSectionDefault(): void
    {
        $html = (string)$this->render(1, ['site' => 'trial', 'report' => 'missing'], 'behaviour')->getBody();

        self::assertStringContainsString('<h1>Pages</h1>', $html);
        self::assertSame('pages', $this->setUpBackendUser(1)->getModuleData('analytics_matomo_behaviour')['report']);
    }

    #[Test]
    public function customPeriodMenuOpensTheRangeFormWithoutResettingTheSelection(): void
    {
        $html = (string)$this->render(1, ['site' => 'trial', 'period' => 'lastMonth'])->getBody();

        self::assertStringContainsString('href="#matomo-custom-range"', $html);
        self::assertStringNotContainsString('period=custom', $html);
    }

    #[Test]
    public function matomoErrorDetailsAreOnlyShownToAdmins(): void
    {
        $this->status = 401;
        $this->fixture = 'ErrorNoViewAccess';
        $admin = (string)$this->render(1)->getBody();
        $editor = (string)$this->render(5)->getBody();

        self::assertStringContainsString('The statistics could not be loaded from Matomo.', $admin);
        self::assertStringContainsString('access for the website id = 1', $admin);
        self::assertStringContainsString('The statistics could not be loaded from Matomo.', $editor);
        self::assertStringNotContainsString('access for the website id = 1', $editor);
    }

    #[Test]
    public function demoReportPagesRenderTablesWithoutHttp(): void
    {
        foreach (['visitors' => 'devices', 'behaviour' => 'pages', 'acquisition' => 'channels'] as $section => $report) {
            $html = (string)$this->render(1, ['site' => 'trial', 'report' => $report], $section)->getBody();
            self::assertStringContainsString('class="table', $html);
            self::assertStringContainsString('<td>', $html);
        }
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function processedReportsAreFetchedInOneBulkAndReusedForTableControls(): void
    {
        $this->fixture = 'ProcessedDevices';
        $html = (string)$this->render(1, ['site' => 'main', 'report' => 'devices'])->getBody();
        self::assertStringContainsString('Device type', $html);
        self::assertStringContainsString('Desktop', $html);
        self::assertStringContainsString('recorded as a new visit', $html);
        $this->render(1, ['q' => 'Desktop', 'sort' => 'nb_visits', 'dir' => 'asc', 'page' => '2']);
        self::assertCount(1, $this->sentRequests);
        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertSame('API.getBulkRequest', $body['method']);
        self::assertSame('en', $body['language']);
        self::assertIsArray($body['urls']);
        self::assertCount(1, $body['urls']);
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
    public function compareAddsPreviousSubRequestAndMatchesRowsByUrl(): void
    {
        $this->fixture = 'SyntheticProcessedPages';
        $html = $this->tableHtml(1, ['site' => 'main', 'compare' => '1']);
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
        $admin = (string)$this->render(1, ['site' => 'main', 'report' => 'devices'])->getBody();
        $editor = (string)$this->render(5, ['site' => 'main', 'report' => 'devices'])->getBody();
        self::assertTrue(str_contains($admin, 'access for the website id = 1'));
        self::assertFalse(str_contains($editor, 'access for the website id = 1'));
        self::assertTrue(str_contains($editor, 'The statistics could not be loaded from Matomo.'));
        $this->fixture = 'ProcessedDevices';
        $this->status = 200;
        $recovered = (string)$this->render(1, ['site' => 'main', 'report' => 'devices'])->getBody();
        self::assertTrue(str_contains($recovered, 'Desktop'));
        self::assertCount(3, $this->sentRequests);
    }

    #[Test]
    public function recordedPagesAndChannelsUseTheSameTable(): void
    {
        foreach (['ProcessedPages' => ['behaviour', 'pages', 'Page URL'], 'ProcessedChannels' => ['acquisition', 'channels', 'Channel Type']] as $fixture => [$section, $report, $column]) {
            $this->fixture = $fixture;
            $html = (string)$this->render(1, ['site' => 'main', 'report' => $report], $section)->getBody();
            self::assertTrue(str_contains($html, 'class="table'));
            self::assertTrue(str_contains($html, $column));
        }
    }

    #[Test]
    public function backendLanguageIsSentToMatomoAndSeparatesCachedReports(): void
    {
        $this->fixture = 'ProcessedDevices';
        $this->render(1, ['site' => 'main', 'report' => 'devices']);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['lang' => 'de'], ['uid' => 1]);
        $this->render(1, ['site' => 'main', 'report' => 'devices']);
        self::assertCount(2, $this->sentRequests);
        parse_str((string)$this->sentRequests[1]->getBody(), $body);
        self::assertSame('de', $body['language']);
    }

    #[Test]
    public function matomoLabelsAndDocumentationAreEscapedByFluid(): void
    {
        $this->fixture = 'SyntheticProcessedUnsafe';
        $html = (string)$this->render(1, ['site' => 'main', 'report' => 'devices'])->getBody();
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
        $html = (string)$this->render(1, ['site' => 'main', 'report' => 'devices'])->getBody();
        self::assertTrue(str_contains($html, 'unexpected processed report'));
        $this->fixture = 'ProcessedDevices';
        $html = (string)$this->render(1, ['site' => 'main', 'report' => 'devices'])->getBody();
        self::assertTrue(str_contains($html, 'Desktop'));
        self::assertCount(2, $this->sentRequests);
    }

    /** @param array<string, string> $query */
    private function tableHtml(int $user, array $query): string
    {
        $html = (string)$this->render($user, $query + ['report' => 'pages'], 'behaviour')->getBody();
        return substr($html, (int)strpos($html, '<h2>'));
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $body
     */
    private function render(int $userId, array $query = [], string $section = 'visitors', array $body = []): ResponseInterface
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser($userId);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $route = $this->get(Router::class)->getRoute('analytics_matomo_' . $section);
        $request = (new ServerRequest('https://example.com/typo3/module/analytics-matomo/' . $section, $body === [] ? 'GET' : 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([
                'HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php', 'SCRIPT_FILENAME' => '/var/www/html/public/typo3/index.php',
            ]))
            ->withAttribute('route', $route)
            ->withQueryParams($query)
            ->withParsedBody($body);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $controller = $this->get(ReportModuleController::class);
        $handler = new class($controller) implements RequestHandlerInterface {
            public function __construct(private ReportModuleController $controller)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->controller->handleRequest($request);
            }
        };
        return $this->get(BackendModuleValidator::class)->process($request, $handler);
    }
}
