<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Who sees what in Goals, Trending, Page details, Transitions, Outlinks and
 * Downloads: the admin (1), an English-only editor of the main site (5), an
 * English-only editor of the main and the shop site (6) and an editor of the
 * shop site only (7). Every view also gets a row of a page no editor may read
 * (/board) and links into the shop site.
 */
final class FeatureAccessTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    private const ADMIN = 1;
    private const MAIN_EDITOR = 5;
    private const MAIN_AND_SHOP_EDITOR = 6;
    private const SHOP_EDITOR = 7;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<array<string, string>> parameters of every sub-request sent to Matomo */
    private array $calls = [];
    /** @var array<string, mixed> */
    private array $fixtures = [];
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AccessUsers.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_goals,analytics_matomo_behaviour,analytics_matomo_page',
        ], ['uid' => 1]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));

        $board = ['label' => '/board', 'url' => 'https://example.com/board'];
        $this->fixtures = $this->fixture('Goals');
        $this->fixtures['goal1EntryPages'] = $this->fixture('SyntheticGoalEntryPages');
        $this->fixtures['goal1EntryPages'][] = $board + ['entry_nb_visits' => 9, 'goals' => ['idgoal=1' => ['nb_conversions_entry' => 3]]];
        $this->fixtures += $this->fixture('SyntheticTrending');
        $this->fixtures['current'][] = $board + ['nb_hits' => 30];
        $this->fixtures['page'] = $this->fixture('PageDetails');
        $this->fixtures['links'] = $this->fixture('SyntheticProcessedLinks')[0];
        $transitions = $this->fixture('SyntheticTransitions')[0];
        $transitions['followingPages'][] = ['label' => 'example.com/board', 'referrals' => 4];
        foreach ($this->fixtures['links']['reportMetadata'] as $index => $link) {
            $transitions['outlinks'][] = ['label' => $link['url'], 'referrals' => 10 + $index];
        }
        $transitions['downloads'] = array_map(static fn (array $row): array => ['label' => $row['label'], 'referrals' => (int)$row['nb_hits']], $this->fixtures['links']['reportData']);
        $transitions['referrers'][] = ['label' => 'From websites', 'shortName' => 'website', 'visits' => 5, 'details' => [
            ['label' => 'https://shop.example.org/imprint', 'referrals' => 3],
            ['label' => 'https://external.example.net/', 'referrals' => 2],
        ]];
        $this->fixtures['transitions'] = $transitions;

        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $results = [];
            foreach ($body['urls'] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->calls[] = $parameters;
                $results[] = $this->answer($parameters);
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

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function featureViews(): array
    {
        return [
            'goals overview' => [['site' => 'main', 'report' => 'overview'], 'goals'],
            'goal page' => [['site' => 'main', 'report' => 'goal1'], 'goals'],
            'trending' => [['site' => 'main', 'report' => 'trending'], 'behaviour'],
            'outlinks' => [['site' => 'main', 'report' => 'outlinks'], 'behaviour'],
            'downloads' => [['site' => 'main', 'report' => 'downloads'], 'behaviour'],
            'page details' => [['id' => '2', 'language' => '0'], 'page'],
            'transitions' => [['id' => '2', 'language' => '0', 'transitions' => '1'], 'page'],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('featureViews')]
    public function editorsWithoutAccessToTheSiteGetNoStatisticsOfIt(array $query, string $section): void
    {
        // Warm every cache first, so a denial cannot come from an empty cache
        $this->render(self::ADMIN, $query, $section);
        $admin = count($this->calls);

        // Without the module validator, which refuses pages outside the user's permissions before the controller
        $html = (string)$this->render(self::SHOP_EDITOR, $query, $section, validate: false)->getBody();

        self::assertGreaterThan(0, $admin);
        self::assertStringContainsString('No accessible site with a Matomo connection', $html);
        foreach (['example.com', 'View Photos', 'tx-analytics-matomo-metric', 'data-transitions', '<table'] as $leak) {
            self::assertStringNotContainsString($leak, $html, $leak);
        }
        self::assertCount($admin, $this->calls, 'no Matomo request for a denied site');
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('featureViews')]
    public function editorsOfTheSiteGetTheView(array $query, string $section): void
    {
        $html = (string)$this->render(self::MAIN_EDITOR, $query, $section)->getBody();

        self::assertStringNotContainsString('No accessible site with a Matomo connection', $html);
        self::assertStringNotContainsString('could not be loaded from Matomo', $html);
        self::assertStringContainsString(match (true) {
            isset($query['transitions']) => 'data-transitions',
            $section === 'page' => 'tx-analytics-matomo-metric--views',
            $section === 'goals' && $query['report'] === 'overview' => 'tx-analytics-matomo-metric--conversions',
            $section === 'goals' => 'Conversions by entry page',
            $query['report'] === 'trending' => '<h2>Rising pages</h2>',
            default => '<table',
        }, $html);
    }

    #[Test]
    public function websiteReferrersRespectAccessToTheirTargetSite(): void
    {
        $admin = (string)$this->render(self::ADMIN, ['id' => '2', 'transitions' => '1'], 'page')->getBody();
        $editor = (string)$this->render(self::MAIN_EDITOR, ['id' => '2', 'transitions' => '1'], 'page')->getBody();
        [$admin] = explode('<h3>Outlinks</h3>', $admin);
        [$editor] = explode('<h3>Outlinks</h3>', $editor);
        self::assertStringContainsString('https://shop.example.org/imprint', $admin);
        self::assertStringNotContainsString('https://shop.example.org/imprint', $editor);
        self::assertStringContainsString('https://external.example.net/', $editor);
    }

    #[Test]
    public function shopEditorsGetTheShopSite(): void
    {
        $html = (string)$this->render(self::SHOP_EDITOR, ['site' => 'shop', 'report' => 'outlinks'], 'behaviour')->getBody();

        self::assertStringContainsString('<h2>Outlinks</h2>', $html);
        self::assertSame(['7'], array_values(array_unique(array_column($this->calls, 'idSite'))));
    }

    #[Test]
    public function goalEntryPagesAndTrendingHidePagesTheEditorMayNotRead(): void
    {
        $views = [
            ['goals', ['site' => 'main', 'report' => 'goal1'], '<td>/about</td>'],
            ['behaviour', ['site' => 'main', 'report' => 'trending'], '<td>/</td>'],
        ];
        foreach ($views as [$section, $query, $readable]) {
            $admin = (string)$this->render(self::ADMIN, $query, $section)->getBody();
            // Served from the cache the admin filled
            $editor = (string)$this->render(self::MAIN_EDITOR, $query, $section)->getBody();

            self::assertStringContainsString('<td>/board</td>', $admin, $section);
            self::assertStringNotContainsString('/board', $editor, $section);
            self::assertStringContainsString($readable, $editor, $section);
        }
    }

    #[Test]
    public function theBackendRefusesPageDetailsOfUnreadablePages(): void
    {
        foreach ([[self::SHOP_EDITOR, '2'], [self::MAIN_EDITOR, '8']] as [$user, $page]) {
            try {
                $this->render($user, ['id' => $page, 'language' => '0', 'transitions' => '1'], 'page');
                self::fail('Page ' . $page . ' was rendered for user ' . $user);
            } catch (\RuntimeException $exception) {
                self::assertSame('You don\'t have access to this page', $exception->getMessage());
            }
        }
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function pageDetailsOfAPageTheEditorMayNotReadShowNothing(): void
    {
        $admin = (string)$this->render(self::ADMIN, ['id' => '8', 'language' => '0', 'transitions' => '1'], 'page')->getBody();
        $editor = (string)$this->render(self::MAIN_EDITOR, ['id' => '8', 'language' => '0', 'transitions' => '1'], 'page', validate: false)->getBody();

        self::assertStringContainsString('https://example.com/board', $admin);
        self::assertStringContainsString('data-transitions', $admin);
        self::assertStringNotContainsString('https://example.com/board', $editor);
        self::assertStringNotContainsString('tx-analytics-matomo-metric', $editor);
        self::assertStringNotContainsString('data-transitions', $editor);
    }

    /**
     * @return array<string, array{int, list<string>, list<string>}>
     */
    public static function linkVisibility(): array
    {
        $external = 'www.dailymotion.com/video/xxss8_virtual-drums-batterie-virtuelle';
        $mainFile = 'example.com/fileadmin/price-list.pdf';
        $shopPage = 'shop.example.org/imprint';
        $shopFile = 'shop.example.org/fileadmin/catalogue.pdf';
        $board = 'example.com/board';
        $german = 'example.com/de/ueber-uns';
        return [
            'admin' => [self::ADMIN, [$external, $mainFile, $shopPage, $shopFile, $board, $german], []],
            'main editor' => [self::MAIN_EDITOR, [$external, $mainFile], [$shopPage, $shopFile, $board, $german]],
            'main and shop editor' => [self::MAIN_AND_SHOP_EDITOR, [$external, $mainFile, $shopPage, $shopFile], [$board, $german]],
        ];
    }

    /**
     * @param list<string> $visible
     * @param list<string> $hidden
     */
    #[Test]
    #[DataProvider('linkVisibility')]
    public function outlinksAndDownloadsShowOnlyLinksIntoSitesAndPagesTheUserMaySee(int $user, array $visible, array $hidden): void
    {
        // The admin fills the caches; editors read their own filtered view from them
        foreach (['outlinks', 'downloads'] as $report) {
            $this->render(self::ADMIN, ['site' => 'main', 'report' => $report], 'behaviour');
        }
        $this->render(self::ADMIN, ['id' => '2', 'language' => '0', 'transitions' => '1'], 'page');
        $views = [
            'outlinks' => (string)$this->render($user, ['site' => 'main', 'report' => 'outlinks'], 'behaviour')->getBody(),
            'downloads' => (string)$this->render($user, ['site' => 'main', 'report' => 'downloads'], 'behaviour')->getBody(),
        ];
        $page = (string)$this->render($user, ['id' => '2', 'language' => '0', 'transitions' => '1'], 'page')->getBody();
        [, $transitions] = explode('<h3>Outlinks</h3>', $page, 2) + [1 => ''];
        [$views['transitions outlinks'], $views['transitions downloads']] = explode('<h3>Downloads</h3>', $transitions, 2) + [1 => ''];

        foreach ($views as $view => $html) {
            foreach ($visible as $link) {
                self::assertStringContainsString($link, $html, $view . ': ' . $link);
            }
            foreach ($hidden as $link) {
                self::assertStringNotContainsString($link, $html, $view . ': ' . $link);
            }
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function answer(array $parameters): mixed
    {
        $previous = str_starts_with($parameters['date'] ?? '', '2026-10-20');
        $goal = isset($parameters['idGoal']) ? 'goal' . $parameters['idGoal'] : 'overview';
        return match ($parameters['method']) {
            'Goals.getGoals' => $this->fixtures['goals'],
            'Goals.get' => $this->fixtures[$goal . ($parameters['period'] === 'day' ? 'Days' : '')] ?? self::fail('No fixture for ' . $goal),
            'Referrers.getReferrerType' => $this->fixtures[$goal . 'Channels'],
            'Actions.getEntryPageUrls' => $this->fixtures[$goal . 'EntryPages'],
            'Actions.getPageUrls' => $this->filter($parameters, $this->fixtures[$previous ? 'previous' : 'current']),
            'Actions.getPageUrl' => $this->fixtures['page'][($parameters['period'] === 'range' ? 0 : 1) + ($previous ? 2 : 0)],
            'API.getProcessedReport' => in_array($parameters['apiAction'], ['getOutlinks', 'getDownloads'], true)
                ? $this->fixtures['links']
                : ['reportTotal' => ['nb_hits' => 200]],
            'Transitions.getTransitionsForAction' => $this->fixtures['transitions'],
            default => self::fail('Unexpected Matomo call ' . $parameters['method']),
        };
    }

    /**
     * Matomo's pattern filter of the page details row
     *
     * @param array<string, string> $parameters
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function filter(array $parameters, array $rows): array
    {
        if (!isset($parameters['filter_pattern'])) {
            return $rows;
        }
        return array_values(array_filter($rows, static fn (array $row): bool => preg_match('~' . $parameters['filter_pattern'] . '~', (string)$row['url']) === 1));
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}
