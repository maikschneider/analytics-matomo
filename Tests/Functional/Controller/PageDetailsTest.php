<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageDetailsTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<array<string, string>> parameters of every sub-request sent to Matomo */
    private array $calls = [];
    private int $requests = 0;
    /** @var array<string, mixed> recorded answers by name */
    private array $fixtures = [];
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_page',
        ], ['uid' => 1]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $pageDetails = $this->fixture('PageDetails');
        $this->fixtures = [
            'range' => $pageDetails[0], 'day' => $pageDetails[1], 'rangePrevious' => $pageDetails[2], 'dayPrevious' => $pageDetails[3],
            'pageRow' => $this->fixture('PageSpeed'),
        ];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $this->requests++;
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

    #[Test]
    public function rendersTilesAndChartOfThePageUrlInOneRequest(): void
    {
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0'], 'page')->getBody();

        self::assertStringContainsString('https://example.com/about', $html);
        self::assertStringContainsString('tx-analytics-matomo-metric--views', $html);
        self::assertStringContainsString('tx-analytics-matomo-metric--exitRate', $html);
        self::assertStringContainsString('76.0 %', $html);
        self::assertStringNotContainsString('tx-analytics-matomo-trend--', $html);
        $chart = $this->chartData($html);
        self::assertSame(5, $chart['metrics']['views']['current'][0]);
        self::assertSame(1, $this->requests);
        $pageCalls = array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'Actions.getPageUrl'));
        self::assertCount(2, $pageCalls);
        self::assertSame('https://example.com/about', $pageCalls[0]['pageUrl']);
        self::assertSame('range', $pageCalls[0]['period']);
        self::assertSame('2026-10-27,2026-11-02', $pageCalls[0]['date']);
        self::assertSame('day', $pageCalls[1]['period']);
        self::assertSame('3', $pageCalls[0]['idSite']);

        $this->render(1, ['id' => '2', 'language' => '0'], 'page');
        self::assertSame(1, $this->requests);
    }

    #[Test]
    public function comparisonAddsPreviousPeriodAndTileChanges(): void
    {
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0', 'compare' => '1'], 'page')->getBody();

        self::assertStringContainsString('tx-analytics-matomo-trend--positive', $html);
        self::assertStringContainsString('+8', $html);
        self::assertSame(6, $this->chartData($html)['metrics']['views']['previous'][0]);
        self::assertContains('2026-10-20,2026-10-26', array_column($this->calls, 'date'));
    }

    #[Test]
    public function usesTheUrlOfTheSelectedLanguageAndSite(): void
    {
        $this->render(1, ['id' => '2', 'language' => '1'], 'page');
        $this->render(1, ['id' => '11', 'language' => '1'], 'page');

        $urls = array_values(array_unique(array_column(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'Actions.getPageUrl'), 'pageUrl')));
        self::assertSame(['https://example.com/de/ueber-uns', 'https://shop.example.de/produkte/'], $urls);
        self::assertSame('7', $this->calls[count($this->calls) - 1]['idSite']);
    }

    #[Test]
    public function hiddenPagesUseTheirOwnUrl(): void
    {
        $this->render(1, ['id' => '5', 'language' => '0'], 'page');

        self::assertSame('https://example.com/summer-sale', $this->calls[0]['pageUrl']);
    }

    #[Test]
    public function explainsWhyNoStatisticsAreShown(): void
    {
        self::assertStringContainsString('Select a page in the page tree', (string)$this->render(1, ['site' => 'main'], 'page')->getBody());
        self::assertStringContainsString('has no frontend URL', (string)$this->render(1, ['id' => '4'], 'page')->getBody());
        self::assertSame(0, $this->requests);
    }

    #[Test]
    public function editorsNeedTheSectionButNotTheBarPermission(): void
    {
        $html = (string)$this->render(4, ['id' => '2', 'language' => '0'], 'page')->getBody();

        self::assertStringContainsString('tx-analytics-matomo-metric--views', $html);
        self::assertStringNotContainsString('value="-1"', $html);
        self::assertStringNotContainsString('All languages', $html);
    }

    #[Test]
    public function showsPageLoadAndServerTimeWithTheirSampleCount(): void
    {
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0'], 'page')->getBody();

        self::assertStringContainsString('3.38 s', $html);
        self::assertStringContainsString('0.28 s', $html);
        self::assertStringContainsString('30 measured page views', $html);
        self::assertSame(1, $this->requests);
        $rowCalls = array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'Actions.getPageUrls'));
        self::assertCount(1, $rowCalls);
        self::assertSame(['1', 'url', '^https?://example\.com/about$', '-1', 'range'], [
            $rowCalls[0]['flat'], $rowCalls[0]['filter_column'], $rowCalls[0]['filter_pattern'], $rowCalls[0]['filter_limit'], $rowCalls[0]['period'],
        ]);
    }

    #[Test]
    public function hidesSpeedAveragesBelowTheMinimumSampleCount(): void
    {
        $this->fixtures['pageRow'][0]['nb_hits_with_time_network'] = 4;
        $this->fixtures['pageRow'][0]['nb_hits_with_time_server'] = 4;
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0'], 'page')->getBody();

        self::assertStringNotContainsString('3.38 s', $html);
        self::assertStringNotContainsString('0.28 s', $html);
        self::assertStringContainsString('Fewer than 5 measured page views', $html);
    }

    #[Test]
    public function comparesTimeOnPageWithTheEstimatedReadingTime(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReadingTime.csv');
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0'], 'page')->getBody();

        // 2 + 150 header and text words, 1 + 20 for all languages, the hidden element left out: 173 words at 220 per minute
        self::assertStringContainsString('data-reading="estimate"', $html);
        self::assertStringContainsString('0:00:47', $html);
        self::assertStringContainsString('173 words', $html);
        self::assertStringContainsString('12.8 %', $html);
    }

    #[Test]
    public function readingSpeedIsASiteSetting(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReadingTime.csv');
        $html = (string)$this->render(1, ['id' => '11', 'language' => '0'], 'page')->getBody();

        // 120 words at the shop's 60 words per minute
        self::assertStringContainsString('120 words', $html);
        self::assertStringContainsString('0:02:00', $html);
    }

    #[Test]
    public function showsNoReadingTimeForPagesWithLittleText(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReadingTime.csv');
        $html = (string)$this->render(1, ['id' => '2', 'language' => '1'], 'page')->getBody();

        self::assertStringContainsString('tx-analytics-matomo-metric--views', $html);
        self::assertStringNotContainsString('data-reading=', $html);
    }

    #[Test]
    public function takesThePeriodFromTheBarLink(): void
    {
        $html = (string)$this->render(1, ['id' => '2', 'language' => '1', 'period' => '14'], 'page')->getBody();

        self::assertCount(14, $this->chartData($html)['labels']);
        self::assertStringContainsString('https://example.com/de/ueber-uns', $html);
        self::assertSame('2026-10-20,2026-11-02', $this->calls[0]['date']);
    }

    #[Test]
    public function demoSitesRenderPageDetailsWithoutHttp(): void
    {
        $html = (string)$this->render(1, ['id' => '31', 'language' => '0', 'compare' => '1'], 'page')->getBody();

        self::assertStringContainsString('/about', $html);
        self::assertGreaterThan(0, $this->chartData($html)['metrics']['views']['current'][0]);
        self::assertSame(2, substr_count($html, 'measured page views</small>'));
        self::assertSame(0, $this->requests);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function answer(array $parameters): mixed
    {
        $previous = str_starts_with($parameters['date'] ?? '', '2026-10-20');
        return match ($parameters['method']) {
            'Actions.getPageUrl' => $this->fixtures[($parameters['period'] === 'range' ? 'range' : 'day') . ($previous ? 'Previous' : '')],
            'Actions.getPageUrls' => $this->fixtures['pageRow'],
            default => self::fail('Unexpected Matomo call ' . $parameters['method']),
        };
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @return array<string, mixed> */
    private function chartData(string $html): array
    {
        self::assertSame(1, preg_match('/data-evolution-chart="([^"]+)"/', $html, $matches));
        return json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }
}
