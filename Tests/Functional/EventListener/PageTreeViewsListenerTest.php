<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\EventListener;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Statistics\PageViewsService;
use MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller\ModuleRequestTrait;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Backend\Context\PageContextFactory;
use TYPO3\CMS\Backend\Controller\Page\TreeController;
use TYPO3\CMS\Backend\Controller\RecordListController;
use TYPO3\CMS\Backend\Module\ModuleData;
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

final class PageTreeViewsListenerTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<array<string, string>> parameters of every sub-request sent to Matomo */
    private array $calls = [];
    /** @var array<string, mixed> */
    private array $fixtures = [];
    private bool $matomoDown = false;
    private int $failedAttempts = 0;
    private bool $fallbackAvailable = false;
    private mixed $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $this->fixtures = $this->fixture('SyntheticTrending');
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            if ($this->matomoDown) {
                $this->failedAttempts++;
                throw new ConnectException('Matomo is down', $request);
            }
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $results = [];
            foreach ($body['urls'] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->calls[] = $parameters;
                if ($parameters['method'] === 'Actions.getPageUrl') {
                    $results[] = $this->fallbackAvailable ? [['nb_hits' => 5]] : ['result' => 'error', 'message' => 'Report unavailable'];
                    continue;
                }
                self::assertSame('Actions.getPageUrls', $parameters['method']);
                $results[] = $this->fixtures[str_starts_with($parameters['date'], '2026-10-04') ? 'current' : 'previous'];
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
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
    public function viewsAreOffByDefault(): void
    {
        $items = $this->tree(1);

        self::assertSame('', $items[1]['suffix']);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function pagesShowTheirViewsOfAllLanguagesWithTrend(): void
    {
        $items = $this->tree(1, true);

        self::assertSame(['2026-10-04,2026-11-02', '2026-09-04,2026-10-03'], array_column($this->callsOfSite('3'), 'date'));
        self::assertSame(' · 31 ↑', $items[1]['suffix']);
        // English and German views of the page
        self::assertSame(' · 10 ↑', $items[2]['suffix']);
        self::assertSame(' · 10 ↓', $items[7]['suffix']);
        // Hidden pages were public before or will be
        self::assertSame(' · 40', $items[5]['suffix']);
        self::assertStringContainsString('31 views in the last 30 days, 23 in the 30 days before', $items[1]['tooltip']);
    }

    #[Test]
    public function foldersShortcutsAndLinksGetNoViews(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['doktype' => 4], ['uid' => 7]);
        $items = $this->tree(1, true);

        self::assertSame('', $items[4]['suffix']);
        self::assertSame('', $items[7]['suffix']);
    }

    #[Test]
    public function editorsCountOnlyLanguagesTheyMayAccess(): void
    {
        $items = $this->tree(3, true);

        self::assertSame(' · 4', $items[2]['suffix']);
    }

    #[Test]
    public function editorsWithoutPageStatisticsGetNoViews(): void
    {
        $items = $this->tree(4, true);

        self::assertSame('', $items[1]['suffix']);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function aTruncatedReportShowsUnknownInsteadOfNoViews(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid' => 8, 'pid' => 1, 'title' => 'Archive', 'slug' => '/archive', 'doktype' => 1, 'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 1,
        ]);
        $this->fixtures['current'][] = ['label' => -1, 'nb_hits' => 50, 'is_summary' => true];
        $items = $this->tree(1, true);

        self::assertSame(' · –', $items[8]['suffix']);
        self::assertSame(' · –', $items[1]['suffix']);
        self::assertSame(' · 10 ↑', $items[2]['suffix']);
    }

    #[Test]
    public function aTruncatedReportFallsBackToTheVisiblePagesAndCachesTheirUrls(): void
    {
        $this->fallbackAvailable = true;
        $this->fixtures['current'][] = ['label' => -1, 'nb_hits' => 50, 'is_summary' => true];
        $items = $this->tree(1, true);
        self::assertSame(' · 5', $items[1]['suffix']);
        $fallbackCalls = array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'Actions.getPageUrl'));
        self::assertNotEmpty($fallbackCalls);
        self::assertSame('https://example.com/', $fallbackCalls[0]['pageUrl']);
        $count = count($this->calls);
        $this->tree(1, true);
        self::assertCount($count, $this->calls);
    }

    #[Test]
    public function oneCachedRequestServesEveryTreeLevel(): void
    {
        $this->tree(1, true);
        $items = $this->tree(1, true, 7);

        self::assertCount(2, $this->callsOfSite('3'));
        self::assertSame(' · 10 ↓', $items[7]['suffix']);
    }

    #[Test]
    public function anUnreachableMatomoLeavesTheTreeWorkingAndIsNotAskedAgainForAWhile(): void
    {
        $this->matomoDown = true;
        $items = $this->tree(1, true);
        $this->tree(1, true);

        self::assertSame('Root', $items[1]['name']);
        self::assertSame('', $items[1]['suffix']);
        self::assertNull($this->get(PageViewsService::class)->getViews($this->siteOf(1)));
        // Once per Matomo site of the tree: the main site and the shop
        self::assertSame(2, $this->failedAttempts);
    }

    #[Test]
    public function everySiteUsesItsOwnMatomoSite(): void
    {
        $items = $this->tree(1, true, 10);

        self::assertSame(['7', '7'], array_column($this->calls, 'idSite'));
        // The report of the test fixture has no URL of the shop
        self::assertSame(' · 0', $items[11]['suffix']);
    }

    #[Test]
    public function demoSitesShowGeneratedViewsWithoutHttp(): void
    {
        $items = $this->tree(1, true, 30);

        self::assertSame([], $this->calls);
        self::assertMatchesRegularExpression('/^ · [\d,]+( [↑↓])?$/u', $items[30]['suffix']);
    }

    #[Test]
    public function recordedReportsMapToThePagesOfTheSite(): void
    {
        $recorded = json_decode(str_replace('www.virtual-drums.com', 'example.com', (string)json_encode($this->fixture('PageTreeViews'))), true);
        self::assertIsArray($recorded);
        $this->fixtures = ['current' => $recorded[0], 'previous' => $recorded[1]];
        $items = $this->tree(1, true);

        // 108 views of / plus 1 of /?hl=en-US, against 115 no trend; the other recorded URLs are no pages of the test site
        self::assertSame(' · 109', $items[1]['suffix']);
        self::assertSame(' · 0', $items[2]['suffix']);
    }

    #[Test]
    public function usersSwitchTheViewsOnInTheModuleAndTheTreeRefreshes(): void
    {
        $html = (string)$this->render(1, ['id' => '0', 'pageTreeViews' => '1'])->getBody();

        self::assertStringContainsString('data-refresh-page-tree', $html);
        self::assertMatchesRegularExpression('#data-dropdowntoggle-status="active"[^>]*href="[^"]*pageTreeViews=0"[^>]*>.*?Show views in the page tree#s', $html);
        self::assertSame(' · 31 ↑', $this->tree(1)[1]['suffix']);

        $html = (string)$this->render(1, ['id' => '0', 'pageTreeViews' => '0'])->getBody();
        self::assertStringContainsString('pageTreeViews=1', $html);
        self::assertSame('', $this->tree(1)[1]['suffix']);
        self::assertStringNotContainsString('data-refresh-page-tree', (string)$this->render(1, ['id' => '0'])->getBody());
    }

    #[Test]
    public function usersWithoutPageStatisticsGetNoSwitch(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['groupMods' => 'analytics_matomo,analytics_matomo_visitors'], ['uid' => 1]);
        $html = (string)$this->render(4, ['id' => '0', 'pageTreeViews' => '1'])->getBody();

        self::assertStringNotContainsString('pageTreeViews', $html);
        self::assertStringNotContainsString('data-refresh-page-tree', $html);
    }

    #[Test]
    public function theListModuleShowsViewsOfSubpagesLinkedToPageDetails(): void
    {
        $html = $this->recordList(1, true);

        self::assertMatchesRegularExpression('#href="[^"]*/module/analytics-matomo/page\?[^"]*id=2" [^>]*title="Matomo: 10 views in the last 30 days, 3 in the 30 days before">.*?10 ↑\s*</a>#s', $html);
        self::assertStringNotContainsString('Matomo: ', $this->recordList(1, false));
    }

    #[Test]
    public function translatedResolvedListRecordsGetNoDefaultLanguageBadge(): void
    {
        if (!class_exists(\TYPO3\CMS\Backend\Template\Components\ComponentGroup::class)) {
            self::markTestSkipped('Resolved record actions require TYPO3 14');
        }
        $user = $this->setUpBackendUser(1);
        $user->uc['analytics_matomo']['pageTreeViews'] = true;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $row = $this->get(ConnectionPool::class)->getConnectionForTable('pages')->select(['*'], 'pages', ['uid' => 3])->fetchAssociative();
        self::assertIsArray($row);
        $record = $this->get(\TYPO3\CMS\Core\Domain\RecordFactory::class)->createResolvedRecordFromDatabaseRow('pages', $row);
        $event = new \TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent(
            new \TYPO3\CMS\Backend\Template\Components\ComponentGroup('primary'),
            new \TYPO3\CMS\Backend\Template\Components\ComponentGroup('secondary'),
            $record,
            self::createStub(\TYPO3\CMS\Backend\RecordList\DatabaseRecordList::class),
            new ServerRequest('https://example.com/typo3/'),
        );
        $this->get(\Psr\EventDispatcher\EventDispatcherInterface::class)->dispatch($event);
        self::assertFalse($event->hasAction('analyticsMatomoViews'));
    }

    #[Test]
    public function theListModuleShowsNoViewsForFolders(): void
    {
        $html = $this->recordList(1, true);

        self::assertDoesNotMatchRegularExpression('#id=4" [^>]*title="Matomo: #', $html);
    }

    /**
     * The List module of the root page as the backend renders it
     */
    private function recordList(int $userId, bool $showViews): string
    {
        $user = $this->setUpBackendUser($userId);
        $user->uc['analytics_matomo']['pageTreeViews'] = $showViews;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $module = $this->get(ModuleProvider::class)->getModule('records', $user) ?? $this->get(ModuleProvider::class)->getModule('web_list', $user);
        self::assertNotNull($module);
        $route = $this->get(Router::class)->getRoute($module->getIdentifier());
        self::assertNotNull($route);
        $route = (clone $route)->setOption('_identifier', $module->getIdentifier());
        $request = (new ServerRequest('https://example.com/typo3/module/web/list?id=1'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([
                'HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php', 'SCRIPT_FILENAME' => '/var/www/html/public/typo3/index.php',
            ]))
            ->withAttribute('route', $route)
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', new ModuleData($module->getIdentifier(), $module->getDefaultModuleData()))
            ->withAttribute('site', $this->siteOf(1))
            ->withQueryParams(['id' => '1']);
        if (class_exists(PageContextFactory::class)) {
            $request = $request->withAttribute('pageContext', $this->get(PageContextFactory::class)->createFromRequest($request, 1, $user));
        }
        $previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = $request;
        try {
            return (string)$this->get(RecordListController::class)->mainAction($request)->getBody();
        } finally {
            if ($previousRequest === null) {
                unset($GLOBALS['TYPO3_REQUEST']);
            } else {
                $GLOBALS['TYPO3_REQUEST'] = $previousRequest;
            }
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function callsOfSite(string $idSite): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['idSite'] === $idSite));
    }

    /**
     * Items of the page tree as the backend loads them, by page ID
     *
     * @param bool|null $showViews null keeps the user's stored setting
     * @return array<int, array<string, mixed>>
     */
    private function tree(int $userId, ?bool $showViews = null, int $parent = 0): array
    {
        $user = $this->setUpBackendUser($userId);
        if ($showViews !== null) {
            $user->uc['analytics_matomo']['pageTreeViews'] = $showViews;
        }
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $request = (new ServerRequest('https://example.com/typo3/ajax/page/tree/fetchData'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withQueryParams($parent === 0 ? [] : ['parent' => (string)$parent, 'depth' => '0']);
        $items = json_decode((string)$this->get(TreeController::class)->fetchDataAction($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($items);
        $byPage = [];
        foreach ($items as $item) {
            $byPage[(int)$item['identifier']] = $item;
        }
        return $byPage;
    }

    private function siteOf(int $pageId): \TYPO3\CMS\Core\Site\Entity\Site
    {
        return $this->get(\TYPO3\CMS\Core\Site\SiteFinder::class)->getSiteByPageId($pageId);
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}
