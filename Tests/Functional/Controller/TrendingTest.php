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

final class TrendingTest extends FunctionalTestCase
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
        $this->fixtures = $this->fixture('SyntheticTrending');
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

    #[Test]
    public function trendingPagesListRisersAndFallersAboveTheThresholds(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'trending'], 'behaviour')->getBody();

        self::assertSame(['2026-10-27,2026-11-02', '2026-10-20,2026-10-26'], array_column($this->calls, 'date'));
        self::assertStringContainsString('<h2>Rising pages</h2>', $html);
        self::assertStringContainsString('<h2>Falling pages</h2>', $html);
        [$rising, $falling] = explode('<h2>Falling pages</h2>', $html);
        self::assertMatchesRegularExpression('#<td>/</td>\s*<td>23</td>\s*<td>31</td>\s*<td>\+8</td>\s*<td>\+34.8 %</td>.*<td>/de/ueber-uns</td>\s*<td>0</td>\s*<td>6</td>\s*<td>\+6</td>\s*<td>–</td>#s', $rising);
        self::assertMatchesRegularExpression('#<td>/s%C3%A4chsische-schweiz</td>\s*<td>20</td>\s*<td>10</td>\s*<td>−10</td>\s*<td>−50.0 %</td>.*<td>/storage</td>\s*<td>5</td>\s*<td>0</td>\s*<td>−5</td>#s', $falling);
        // Below the minimum difference and below the minimum growth
        self::assertStringNotContainsString('<td>/about</td>', $html);
        self::assertStringNotContainsString('<td>/summer-sale</td>', $html);
    }

    #[Test]
    public function truncatedReportsClaimNoNewOrVanishedPages(): void
    {
        $this->fixtures['previous'][] = ['label' => -1, 'nb_hits' => 50, 'is_summary' => true];
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'trending'], 'behaviour')->getBody();

        self::assertStringContainsString('<td>/</td>', $html);
        self::assertStringNotContainsString('<td>/de/ueber-uns</td>', $html);
        self::assertStringNotContainsString('<td>/storage</td>', $html);
    }

    #[Test]
    public function impactThresholdIncludesViewsInArchiveSummaryRows(): void
    {
        $this->fixtures['current'][] = ['label' => -1, 'nb_hits' => 9900, 'is_summary' => true];
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'trending'], 'behaviour')->getBody();

        self::assertStringNotContainsString('<td>/</td>', $html);
        self::assertStringNotContainsString('<td>/s%C3%A4chsische-schweiz</td>', $html);
        self::assertStringContainsString('No page changed by at least', $html);
    }

    #[Test]
    public function recordedReportsShowTheEmptyStateWithoutFallingPages(): void
    {
        $recorded = (string)json_encode($this->fixture('Trending'));
        $this->fixtures = json_decode(str_replace('www.virtual-drums.com', 'example.com', $recorded), true);
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'trending'], 'behaviour')->getBody();

        [$rising, $falling] = explode('<h2>Falling pages</h2>', $html);
        self::assertMatchesRegularExpression('#<td>/</td>\s*<td>23</td>\s*<td>31</td>\s*<td>\+8</td>\s*<td>\+34.8 %</td>#', $rising);
        self::assertStringContainsString('No page changed by at least 20 % and 3 views against the previous period.', $falling);
    }

    #[Test]
    public function trendingPagesShowEditorsOnlyPagesTheyMaySee(): void
    {
        $html = (string)$this->render(3, ['id' => '1', 'report' => 'trending'], 'behaviour')->getBody();

        self::assertStringContainsString('<td>/</td>', $html);
        self::assertStringNotContainsString('<td>/de/ueber-uns</td>', $html);
        self::assertStringNotContainsString('<td>/storage</td>', $html);
    }

    #[Test]
    public function pageDetailsHintAtATrendingPage(): void
    {
        $this->fixtures['page'] = $this->fixture('PageDetails');
        $this->fixtures['siteViews'] = 200;
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0'], 'page')->getBody();

        self::assertStringContainsString('data-trend="up"', $html);
        self::assertStringContainsString('Rising: +8 views compared with the previous period', $html);
    }

    #[Test]
    public function pageDetailsStaySilentBelowTheImpactThreshold(): void
    {
        $this->fixtures['page'] = $this->fixture('PageDetails');
        $this->fixtures['siteViews'] = 1000;
        $html = (string)$this->render(1, ['id' => '2', 'language' => '0'], 'page')->getBody();

        self::assertStringContainsString('<h2>Speed</h2>', $html);
        self::assertStringNotContainsString('data-trend=', $html);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function answer(array $parameters): mixed
    {
        $previous = str_starts_with($parameters['date'] ?? '', '2026-10-20');
        return match ($parameters['method']) {
            'Actions.getPageUrls' => $this->filter($parameters, $this->fixtures[$previous ? 'previous' : 'current']),
            'Actions.getPageUrl' => $this->fixtures['page'][($parameters['period'] === 'range' ? 0 : 1) + ($previous ? 2 : 0)],
            'API.getProcessedReport' => ['reportTotal' => ['nb_hits' => $this->fixtures['siteViews']]],
            'Goals.getGoals' => [],
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
