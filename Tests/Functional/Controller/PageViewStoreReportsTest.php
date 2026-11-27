<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Dashboard\SiteStatisticsService;
use MaikSchneider\AnalyticsMatomo\Statistics\PageViewsService;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Tests\Functional\PageViewStoreTrait;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The whole-site URL reports read the page view store when it holds their periods.
 */
final class PageViewStoreReportsTest extends FunctionalTestCase
{
    use ModuleRequestTrait;
    use PageViewStoreTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<array<string, string>> parameters of every sub-request sent to Matomo */
    private array $calls = [];
    private int $requests = 0;
    private bool $pageReportFails = false;
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_behaviour,analytics_matomo_page',
        ], ['uid' => 1]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $segments = $this->answerWithoutSegments($request);
            if ($segments !== null) {
                return $segments;
            }
            $this->requests++;
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $results = [];
            foreach ($body['urls'] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->calls[] = $parameters;
                $results[] = $this->answer($parameters, is_string($body['token_auth'] ?? null) ? $body['token_auth'] : '');
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
    public function topPagesComeFromTheStore(): void
    {
        $this->fillStore();
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'pages'], 'behaviour')->getBody();

        self::assertSame([], $this->rangeCalls());
        self::assertContains('API.getMetadata', array_column($this->calls, 'method'));
        self::assertStringContainsString('<h2>Page URLs</h2>', $html);
        self::assertStringContainsString('Pageviews (%)', $html);
        // 5 views a day of 10 on the site, 7 days
        self::assertMatchesRegularExpression('#<td>/about</td>\s*<td>35</td>\s*<td>50.0 %</td>#', $html);
        self::assertStringNotContainsString('shop.example.org', $html);
        self::assertStringContainsString('Page views from the page view store, last import', $html);
    }

    #[Test]
    public function topPagesFromTruncatedDaysShowNoShareOfUnknownVisits(): void
    {
        $this->fillStore(truncated: true);
        // The last 30 days hold the truncated days
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'pages', 'period' => '30'], 'behaviour')->getBody();

        self::assertSame([], $this->rangeCalls());
        self::assertStringContainsString('Pageviews (%)', $html);
        self::assertStringNotContainsString('Unique Pageviews (%)', $html);
    }

    #[Test]
    public function trendingPagesComeFromTheStore(): void
    {
        $this->fillStore();
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'trending'], 'behaviour')->getBody();

        self::assertSame([], $this->rangeCalls());
        [$rising, $falling] = explode('<h2>Falling pages</h2>', $html);
        self::assertMatchesRegularExpression('#<td>/about</td>\s*<td>14</td>\s*<td>35</td>\s*<td>\+21</td>#', $rising);
        self::assertMatchesRegularExpression('#<td>/s%C3%A4chsische-schweiz</td>\s*<td>21</td>\s*<td>0</td>\s*<td>−21</td>#', $falling);
        self::assertStringContainsString('Page views from the page view store', $html);
    }

    #[Test]
    public function trendingPagesLeaveOutUrlsHiddenInSummaryRowsOfTruncatedDays(): void
    {
        $this->fillStore(truncated: true);
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'trending'], 'behaviour')->getBody();

        self::assertSame([], $this->rangeCalls());
        [$rising, $falling] = explode('<h2>Falling pages</h2>', $html);
        // The views of /about before are unknown: only 2 of them were reported, the rest hides in summary rows
        self::assertStringNotContainsString('<td>/about</td>', $rising);
        self::assertMatchesRegularExpression('#<td>/s%C3%A4chsische-schweiz</td>\s*<td>21</td>\s*<td>0</td>\s*<td>−21</td>#', $falling);
    }

    #[Test]
    public function contentReviewComesFromTheStore(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['custom_options' => 'analytics_matomo:contentReview'], ['uid' => 1]);
        $this->fillStore();
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'review'], 'behaviour')->getBody();

        self::assertSame([], $this->rangeCalls());
        [$withoutViews] = explode('<h2>Old pages with traffic</h2>', $html);
        // The root page got no views; the others did
        self::assertStringContainsString('<td>Root</td>', $withoutViews);
        self::assertStringNotContainsString('<td>About</td>', $withoutViews);
        self::assertStringNotContainsString('<td>Saxon Switzerland</td>', $withoutViews);
        self::assertStringContainsString('Page views from the page view store', $html);
    }

    #[Test]
    public function pageDetailsTakeTheirTotalsFromTheStore(): void
    {
        $this->fillStore();
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0'], 'page')->getBody();

        self::assertSame([], $this->rangeCalls(['Actions.getPageUrl', 'API.getProcessedReport']));
        // The access check, the series and goals, and the page URL report for timings and goals on its own
        self::assertSame(3, $this->requests);
        self::assertStringContainsString('Rising: +21 views compared with the previous period', $html);
        self::assertMatchesRegularExpression('#metric--views".*?<span class="tx-analytics-matomo-value">35</span>#s', $html);
        // The German translation with 1 view a day
        self::assertStringContainsString('data-languages', $html);
        self::assertMatchesRegularExpression('#Deutsch</a>\s*</th>\s*<td class="text-end">7</td>#', $html);
        self::assertStringContainsString('<h2>Speed</h2>', $html);
        self::assertStringNotContainsString('data-timings-missing', $html);
        self::assertStringContainsString('Page views from the page view store', $html);
    }

    #[Test]
    public function pageDetailsShowTheStoreNumbersWhenMatomoDoesNotAnswerThePageReport(): void
    {
        $this->fillStore();
        $this->pageReportFails = true;
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0'], 'page')->getBody();

        self::assertStringContainsString('Rising: +21 views compared with the previous period', $html);
        self::assertStringContainsString('data-timings-missing', $html);
        self::assertStringContainsString('timings and goal conversions are missing', $html);
        self::assertStringNotContainsString('<h2>Goals</h2>', $html);
    }

    #[Test]
    public function thePageTreeTakesItsViewsFromTheStore(): void
    {
        $this->fillStore();
        $views = $this->get(PageViewsService::class)->getViews($this->site('main'));

        self::assertSame([], $this->rangeCalls());
        self::assertNotNull($views);
        self::assertTrue($views['source']?->isComplete);
        // 30 days to 2 November: 23 days with 2 views, 7 with 5
        self::assertSame(['current' => 81, 'previous' => 60], PageViewsService::ofPage($views, 2, [0]));
        self::assertSame(['current' => 30, 'previous' => 30], PageViewsService::ofPage($views, 2, [1]));
    }

    #[Test]
    public function theTopPagesWidgetComesFromTheStore(): void
    {
        $this->fillStore();
        $pages = $this->get(SiteStatisticsService::class)->getTopPages($this->configuration(), Period::lastDays(7, new \DateTimeImmutable('2026-11-02')));

        self::assertSame([], $this->rangeCalls());
        self::assertSame(['https://example.com/about', 'https://shop.example.org/products/', 'https://example.com/de/ueber-uns'], array_map(static fn ($page): string => $page->url, $pages));
        self::assertSame([35, 14, 0.5], [$pages[0]->views, $pages[0]->previousViews, $pages[0]->share]);
    }

    #[Test]
    public function aStoreThatDoesNotHoldThePeriodLeavesTheReportToMatomo(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-10-30', '2026-11-02', self::currentRows()), [$this->site('main')]);
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'trending'], 'behaviour')->getBody();

        self::assertSame(['2026-10-27,2026-11-02', '2026-10-20,2026-10-26'], array_column($this->rangeCalls(), 'date'));
        self::assertStringContainsString('Page views directly from Matomo: the page view store (30.10.2026–02.11.2026) does not hold this period completely yet.', $html);
    }

    #[Test]
    public function aSiteWhoseTokenMayNotViewTheStoredMatomoSiteGetsMatomosAnswer(): void
    {
        // The shop's Matomo site is stored, but the shop's token has no view access to it
        $this->fillPageViewStore(new MatomoConfiguration('https://matomo.example.com', 7, 'secret-token'), self::everyDay('2026-10-20', '2026-11-02', [self::row('https://shop.example.org/products/', 9)]), [$this->site('shop')]);
        $html = (string)$this->render(1, ['id' => '10', 'report' => 'pages'], 'behaviour')->getBody();

        self::assertContains('API.getProcessedReport', array_column($this->calls, 'method'));
        self::assertStringNotContainsString('<td>/products/</td>', $html);
        self::assertStringNotContainsString('page view store', $html);
    }

    /**
     * @param bool $truncated Matomo truncated the reports of 20 to 25 October: /about hides in their summary rows
     */
    private function fillStore(bool $truncated = false): void
    {
        $before = [
            self::row('https://example.com/about', 2),
            self::row('https://example.com/de/ueber-uns', 1),
            self::row('https://example.com/s%C3%A4chsische-schweiz', 3),
            self::row('https://shop.example.org/products/', 2),
        ];
        $days = self::everyDay('2026-08-01', '2026-10-26', $before);
        if ($truncated) {
            $days = self::everyDay('2026-10-20', '2026-10-25', [...array_slice($before, 1), ['label' => -1, 'nb_hits' => 2]]) + $days;
        }
        $this->fillPageViewStore($this->configuration(), $days + self::everyDay('2026-10-27', '2026-11-02', self::currentRows()), [$this->site('main')]);
    }

    /**
     * @return list<array<string, int|string>>
     */
    private static function currentRows(): array
    {
        return [
            self::row('https://example.com/about', 5),
            self::row('https://example.com/de/ueber-uns', 1),
            self::row('https://shop.example.org/products/', 4),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private static function row(string $url, int $hits): array
    {
        return [
            'label' => (string)parse_url($url, PHP_URL_PATH), 'url' => $url, 'nb_hits' => $hits, 'nb_visits' => $hits,
            'sum_time_spent' => 30 * $hits, 'entry_nb_visits' => $hits, 'entry_bounce_count' => 0, 'exit_nb_visits' => $hits,
        ];
    }

    /**
     * Calls of whole-site URL reports or of the given methods over a range
     *
     * @param list<string> $methods
     * @return list<array<string, string>>
     */
    private function rangeCalls(array $methods = ['Actions.getPageUrls', 'API.getProcessedReport']): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => in_array($call['method'], $methods, true)
            && ($call['period'] ?? '') === 'range' && !isset($call['filter_pattern'])));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function answer(array $parameters, string $token): mixed
    {
        return match ($parameters['method']) {
            // Only the token of the main site may view a Matomo site, and only its own
            'SitesManager.getSiteFromId' => $token === 'secret-token' && $parameters['idSite'] === '3'
                ? ['idsite' => '3', 'timezone' => 'UTC', 'ts_created' => '2020-01-01 00:00:00']
                : ['result' => 'error', 'message' => "You can't access this resource as it requires 'view' access for the website id = " . $parameters['idSite'] . '.'],
            'API.getMetadata' => [$this->fixture('ProcessedPages')[0]['metadata']],
            'API.getProcessedReport' => ['metadata' => ['name' => 'Page URLs'], 'columns' => ['label' => 'Page URL'], 'reportData' => [], 'reportMetadata' => [], 'reportTotal' => []],
            'Actions.getPageUrl' => $parameters['period'] === 'range' ? [] : array_fill_keys(Period::lastDays(7, new \DateTimeImmutable('2026-11-02'))->dates(), []),
            'Actions.getPageUrls' => $this->pageReportFails && isset($parameters['filter_pattern'])
                ? ['result' => 'error', 'message' => 'Maximum execution time of 30 seconds exceeded']
                : [],
            'Goals.getGoals' => [],
            default => self::fail('Unexpected Matomo call ' . $parameters['method']),
        };
    }

    private function configuration(): MatomoConfiguration
    {
        return new MatomoConfiguration('https://matomo.example.com/', 3, 'secret-token');
    }

    private function site(string $identifier): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier($identifier);
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}
