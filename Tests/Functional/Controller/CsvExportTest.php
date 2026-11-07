<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CsvExportTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    private int $requests = 0;
    private string $fixture = 'SyntheticProcessedPages';
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_visitors,analytics_matomo_behaviour,analytics_matomo_acquisition',
        ], ['uid' => 1]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $this->requests++;
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $results = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $this->fixture . '.json'), true);
            $answers = [];
            foreach (array_keys(array_values($body['urls'])) as $index) {
                $answers[] = $results[$index % count($results)];
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($answers)));
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
    public function everyTableLinksToItsExportWithTheCurrentSearchAndSort(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'pages', 'q' => 'main', 'sort' => 'nb_hits', 'dir' => 'asc'], 'behaviour')->getBody();

        self::assertSame(1, preg_match('#<a class="btn btn-default ms-auto" href="([^"]+)"[^>]*>.*?CSV#s', $html, $matches));
        $url = html_entity_decode($matches[1] ?? '');
        self::assertStringContainsString('export=0', $url);
        self::assertStringContainsString('q=main', $url);
        self::assertStringContainsString('dir=asc', $url);
    }

    #[Test]
    public function exportHasTheVisibleRowsWithTranslatedHeadersAndRawNumbers(): void
    {
        $this->render(1, ['id' => '1', 'report' => 'pages'], 'behaviour');
        $response = $this->render(1, ['id' => '1', 'report' => 'pages', 'export' => '0', 'sort' => 'nb_hits', 'dir' => 'asc'], 'behaviour');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('#^attachment; filename="matomo-main-behaviour-pages-2026-10-27-2026-11-02\.csv"$#', $response->getHeaderLine('Content-Disposition'));
        $rows = $this->csv($response);
        self::assertSame(['Page URL', 'Page views', 'URL'], $rows[0]);
        $labels = array_column(array_slice($rows, 1), 0);
        // Admins see URLs without a page, but never another site's
        self::assertSame(['hidden-german', 'unknown-page', 'restricted-page', 'child-page', 'hidden-page', 'main-german', 'main-about'], $labels);
        self::assertContains(['main-about', '30', 'https://example.com/about'], $rows);
        // Served from the cached report
        self::assertSame(1, $this->requests);
    }

    #[Test]
    public function exportFollowsLanguageSubtreeSearchAndPageAccess(): void
    {
        $german = $this->csv($this->render(1, ['id' => '1', 'report' => 'pages', 'language' => '1', 'export' => '0'], 'behaviour'));
        self::assertSame(['main-german', 'hidden-german'], array_column(array_slice($german, 1), 0));

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => 8, 'pid' => 2, 'title' => 'Child', 'slug' => '/about/child', 'perms_everybody' => 1]);
        $subtree = $this->csv($this->render(1, ['id' => '2', 'report' => 'pages', 'language' => '-1', 'export' => '0'], 'behaviour'));
        self::assertSame(['main-about', 'main-german', 'child-page'], array_column(array_slice($subtree, 1), 0));

        $search = $this->csv($this->render(1, ['id' => '1', 'report' => 'pages', 'q' => 'hidden', 'export' => '0'], 'behaviour'));
        self::assertSame(['hidden-page', 'hidden-german'], array_column(array_slice($search, 1), 0));

        $editor = $this->csv($this->render(5, ['id' => '1', 'report' => 'pages', 'language' => '-1', 'export' => '0'], 'behaviour'));
        self::assertSame(['main-about', 'hidden-page', 'child-page'], array_column(array_slice($editor, 1), 0));
    }

    #[Test]
    public function exportPicksTheTableOfThePageAndKeepsRawRatios(): void
    {
        $this->fixture = 'ProcessedChannels';
        $rows = $this->csv($this->render(1, ['id' => '1', 'report' => 'channels', 'export' => '0'], 'acquisition'));

        self::assertSame('Channel Type', $rows[0][0]);
        self::assertContains('Visits (%)', $rows[0]);
        self::assertNotContains('URL', $rows[0]);
        $search = $rows[array_search('Search Engines', array_column($rows, 0), true)];
        self::assertSame('241403', $search[1]);
        self::assertSame('0.551', $search[array_search('Visits (%)', $rows[0], true)]);
        self::assertSame('0.74', $search[array_search('Bounce Rate', $rows[0], true)]);
    }

    #[Test]
    public function unknownTableRendersTheModuleInstead(): void
    {
        $response = $this->render(1, ['id' => '1', 'report' => 'pages', 'export' => '5'], 'behaviour');

        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('main-about', (string)$response->getBody());
    }

    #[Test]
    public function demoReportsAreExportedWithoutHttp(): void
    {
        $rows = $this->csv($this->render(1, ['id' => '30', 'report' => 'devices', 'export' => '0'], 'visitors'));

        self::assertSame('Device type', $rows[0][0]);
        self::assertGreaterThan(2, count($rows));
        self::assertSame(0, $this->requests);
    }

    /** @return list<list<string>> */
    private function csv(ResponseInterface $response): array
    {
        $content = (string)$response->getBody();
        self::assertStringStartsWith("\u{FEFF}", $content);
        $rows = [];
        foreach (explode("\n", trim(substr($content, 3))) as $line) {
            $rows[] = array_map(strval(...), str_getcsv($line, ',', '"', ''));
        }
        return $rows;
    }
}
