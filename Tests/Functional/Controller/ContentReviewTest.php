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

final class ContentReviewTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<array<string, string>> parameters of every sub-request sent to Matomo */
    private array $calls = [];
    /** @var array<mixed> Matomo rows of the page URL report */
    private array $rows = [];
    private string $trackedSince = '2008-01-27 01:41:53';
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('be_groups');
        $connection->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_behaviour',
            'custom_options' => 'analytics_matomo:contentReview',
        ], ['uid' => 1]);
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        foreach ([
            1 => ['2020-01-01', '2023-01-01', '2023-03-01'],
            2 => ['2020-01-01', '2024-06-01', '0'],
            3 => ['2026-01-01', '2026-10-01', '0'],
            7 => ['2022-01-01', '2022-01-01', '0'],
            10 => ['2020-01-01', '2025-01-01', '0'],
            11 => ['2020-01-01', '2026-09-01', '0'],
            12 => ['2020-01-01', '2026-09-01', '0'],
            // Created during the period: no views say nothing yet
            13 => ['2026-10-20', '2026-10-20', '0'],
        ] as $uid => [$created, $changed, $contentChanged]) {
            $pages->update('pages', [
                'crdate' => strtotime($created . ' 12:00 UTC'),
                'tstamp' => strtotime($changed . ' 12:00 UTC'),
                'SYS_LASTCHANGED' => $contentChanged === '0' ? 0 : strtotime($contentChanged . ' 12:00 UTC'),
            ], ['uid' => $uid]);
        }
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $this->rows = $this->fixture('SyntheticPageViews90Days');
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $segments = $this->answerWithoutSegments($request);
            if ($segments !== null) {
                return $segments;
            }
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $results = [];
            foreach ($body['urls'] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->calls[] = $parameters;
                $results[] = match ($parameters['method']) {
                    'Actions.getPageUrls' => $this->rows,
                    'SitesManager.getSiteFromId' => ['ts_created' => $this->trackedSince] + $this->fixture('ConnectionStatus')['SitesManager.getSiteFromId'],
                    // The default report page of users without the content review
                    'API.getProcessedReport' => ['metadata' => [], 'columns' => ['label' => 'Page URL'], 'reportData' => [], 'reportMetadata' => [], 'reportTotal' => []],
                    default => self::fail('Unexpected Matomo call ' . $parameters['method']),
                };
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
    public function listsVisiblePagesWithoutViewsAndOldPagesWithTraffic(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'review'], 'behaviour')->getBody();

        self::assertSame(['2026-08-05,2026-11-02'], array_column(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'Actions.getPageUrls'), 'date'));
        self::assertStringContainsString('<h2>Pages without views</h2>', $html);
        self::assertStringContainsString('<h2>Old pages with traffic</h2>', $html);
        [$withoutViews, $old] = explode('<h2>Old pages with traffic</h2>', $html);
        // Saxon Switzerland and the German About page had no views; folders, hidden pages and visited pages are no candidates
        self::assertMatchesRegularExpression('#<td>Saxon Switzerland</td>\s*<td>0</td>\s*<td>2022-01-01</td>#', $withoutViews);
        self::assertMatchesRegularExpression('#<td>Über uns</td>\s*<td>0</td>\s*<td>2026-10-01</td>#', $withoutViews);
        foreach (['<td>Root</td>', '<td>About</td>', '<td>Storage</td>', '<td>Summer sale</td>', '<td>Sommerschlussverkauf</td>', '<td>Shop</td>'] as $cell) {
            self::assertStringNotContainsString($cell, $withoutViews);
        }
        // Views × days since the last change; tracking parameters count for their page
        self::assertMatchesRegularExpression('#<td>Root</td>\s*<td>300</td>\s*<td>2023-03-01</td>\s*<td>1,342</td>.*<td>About</td>\s*<td>42</td>\s*<td>2024-06-01</td>\s*<td>884</td>#s', $old);
        self::assertStringNotContainsString('<td>Saxon Switzerland</td>', $old);
        self::assertStringNotContainsString('<td>Über uns</td>', $old);
        self::assertStringNotContainsString('actions-delete', $html);
    }

    #[Test]
    public function aTruncatedReportShowsUnknownInsteadOfNoViews(): void
    {
        $this->rows[] = ['label' => -1, 'nb_hits' => 900, 'is_summary' => true];
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'review'], 'behaviour')->getBody();

        [$withoutViews] = explode('<h2>Old pages with traffic</h2>', $html);
        self::assertStringContainsString('Matomo truncated this report', $withoutViews);
        self::assertMatchesRegularExpression('#<td>Saxon Switzerland</td>\s*<td>–</td>#', $withoutViews);
        self::assertStringNotContainsString('<td>0</td>', $withoutViews);
    }

    #[Test]
    public function youngTrackingCannotProvePagesWithoutViews(): void
    {
        $this->trackedSince = '2026-09-15 08:00:00';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'review'], 'behaviour')->getBody();

        self::assertStringContainsString('Matomo has tracked this site since 2026-09-15 only', $html);
        self::assertStringNotContainsString('<td>Saxon Switzerland</td>', $html);
        self::assertStringContainsString('<h2>Old pages with traffic</h2>', $html);
    }

    #[Test]
    public function otherSitesHaveTheirOwnPagesAndLanguageDomains(): void
    {
        $html = (string)$this->render(1, ['id' => '10', 'report' => 'review'], 'behaviour')->getBody();

        [$withoutViews] = explode('<h2>Old pages with traffic</h2>', $html);
        // The German shop domain had no views, the English products page had
        self::assertMatchesRegularExpression('#<td>Produkte</td>\s*<td>0</td>#', $withoutViews);
        self::assertStringNotContainsString('<td>Products</td>', $withoutViews);
        self::assertStringNotContainsString('<td>Imprint</td>', $withoutViews);
        self::assertStringNotContainsString('<td>Saxon Switzerland</td>', $html);
    }

    #[Test]
    public function pageTreeSubtreeAndLanguageNarrowTheLists(): void
    {
        $subtree = (string)$this->render(1, ['id' => '7', 'report' => 'review'], 'behaviour')->getBody();
        self::assertStringContainsString('<td>Saxon Switzerland</td>', $subtree);
        self::assertStringNotContainsString('<td>Über uns</td>', $subtree);

        $german = (string)$this->render(1, ['id' => '1', 'report' => 'review', 'language' => '1'], 'behaviour')->getBody();
        self::assertStringContainsString('<td>Über uns</td>', $german);
        self::assertStringNotContainsString('<td>Saxon Switzerland</td>', $german);
    }

    #[Test]
    public function editorsSeeOnlyPagesAndLanguagesTheyMayAccess(): void
    {
        $html = (string)$this->render(3, ['id' => '1', 'report' => 'review'], 'behaviour')->getBody();

        self::assertStringContainsString('<td>Saxon Switzerland</td>', $html);
        self::assertStringNotContainsString('<td>Über uns</td>', $html);
    }

    #[Test]
    public function theReportNeedsItsCustomOption(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['custom_options' => ''], ['uid' => 1]);
        $html = (string)$this->render(3, ['id' => '1', 'report' => 'review'], 'behaviour')->getBody();

        self::assertStringNotContainsString('report=review', $html);
        self::assertStringNotContainsString('Pages without views', $html);
        self::assertSame([], array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'SitesManager.getSiteFromId'));
    }

    #[Test]
    public function csvExportListsTheRowsWithRawValues(): void
    {
        $response = $this->render(1, ['id' => '1', 'report' => 'review', 'export' => '1'], 'behaviour');

        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $csv = (string)$response->getBody();
        self::assertStringContainsString('Root,300,' . strtotime('2023-03-01 12:00 UTC') . ',1342,402600,https://example.com/', $csv);
    }

    #[Test]
    public function recordedReportOfAnotherSiteLeavesEveryPageWithoutViews(): void
    {
        $this->rows = $this->fixture('PageViews90Days')[0];
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'review'], 'behaviour')->getBody();

        [$withoutViews, $old] = explode('<h2>Old pages with traffic</h2>', $html);
        self::assertMatchesRegularExpression('#<td>Root</td>\s*<td>0</td>#', $withoutViews);
        self::assertStringContainsString('No old page had views', $old);
    }

    #[Test]
    public function demoSitesHavePagesWithoutViews(): void
    {
        $html = (string)$this->render(1, ['id' => '30', 'report' => 'review'], 'behaviour')->getBody();

        self::assertSame([], $this->calls);
        self::assertStringContainsString('<h2>Pages without views</h2>', $html);
        self::assertStringContainsString('<h2>Old pages with traffic</h2>', $html);
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}
