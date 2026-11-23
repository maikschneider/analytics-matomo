<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics\Store;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportResult;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewImporter;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlPageMapper;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageViewImporterTest extends FunctionalTestCase
{
    private const NOW = '2026-11-23 14:30:00';

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<string> date of every request, in order */
    private array $requested = [];

    /** @var list<array<string, string>> parameters of every request */
    private array $parameters = [];

    /** @var array<string, string> error message by date */
    private array $failing = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database.csv');
        $this->setNow(self::NOW);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            parse_str((string)$request->getBody(), $body);
            $results = [];
            foreach (is_array($body['urls'] ?? null) ? $body['urls'] : [] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->parameters[] = $parameters;
                $date = $parameters['date'];
                $this->requested[] = $date;
                $hits = (int)substr($date, -2);
                $results[] = isset($this->failing[$date]) ? ['result' => 'error', 'message' => $this->failing[$date]] : [
                    ['label' => '/about', 'url' => 'https://example.com/about', 'nb_hits' => $hits, 'nb_visits' => '1'],
                    ['label' => '/products/', 'url' => 'https://shop.example.org/products/', 'nb_hits' => 1],
                    ['label' => '-1', 'nb_hits' => 2],
                ];
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    #[Test]
    public function theFirstRunImportsTodayBackToTheRetentionLimitNewestFirst(): void
    {
        $result = $this->import(retentionDays: 5);

        self::assertSame(['2026-11-23', '2026-11-22', '2026-11-21', '2026-11-20', '2026-11-19'], $this->requested);
        self::assertSame([20261123, 20261122, 20261121, 20261120, 20261119], $result->importedDays);
        self::assertSame(5, $result->requests);
        self::assertSame([], $result->failedDays);
        self::assertSame(0, $result->remainingDays);
        self::assertSame([
            'method' => 'Actions.getPageUrls', 'period' => 'day', 'date' => '2026-11-23', 'flat' => '1', 'format_metrics' => '0',
            'filter_limit' => '-1', 'showColumns' => implode(',', PageMetrics::COUNTS), 'idSite' => '3',
        ], $this->parameters[0]);
        self::assertSame([20261119, 20261120, 20261121, 20261122, 20261123], $this->importedDays($result->connection));
        // The summary row counts for the day total
        self::assertSame(26, (int)$this->getConnectionPool()->getConnectionForTable(ImportRepository::TABLE)
            ->select(['total_hits'], ImportRepository::TABLE, ['connection' => $result->connection, 'day' => 20261123])->fetchOne());
        self::assertSame(23 + 22 + 21 + 20 + 19, $this->repository()->sumByPage('main', $result->connection, 20261101, 20261130)[2][0]['nb_hits'] ?? null);
    }

    #[Test]
    public function theUrlsOfADayAreMappedForEverySiteOfTheConnection(): void
    {
        $result = $this->import(sites: [$this->site('main'), $this->site('shop')], retentionDays: 2);

        self::assertSame([2 => [0 => 45]], array_map(static fn (array $languages): array => array_map(static fn (array $counts): int => $counts['nb_hits'], $languages), $this->repository()->sumByPage('main', $result->connection, 20261101, 20261130)));
        self::assertSame(2, $this->repository()->sumByPage('shop', $result->connection, 20261101, 20261130)[11][0]['nb_hits'] ?? null);
    }

    #[Test]
    public function aSiteNewToTheConnectionGetsTheStoredUrlsMappedWithoutRequests(): void
    {
        $this->import(retentionDays: 2);
        $this->requested = [];

        $result = $this->import(sites: [$this->site('main'), $this->site('shop')], budget: 0, retentionDays: 2);

        self::assertSame([], $this->requested);
        self::assertSame(2, $this->repository()->sumByPage('shop', $result->connection, 20261101, 20261130)[11][0]['nb_hits'] ?? null);
    }

    #[Test]
    public function aLaterRunImportsTheDaysThatAreNotFinalAndPurgesDaysBeyondTheRetention(): void
    {
        $this->import(retentionDays: 5);
        $this->requested = [];
        // Yesterday's import ran after the end of the day + 2 h: final. Today's did not.
        $this->setNow('2026-11-24 03:00:00');

        $result = $this->import(retentionDays: 5);

        self::assertSame(['2026-11-24', '2026-11-23'], $this->requested);
        self::assertSame([20261120, 20261121, 20261122, 20261123, 20261124], $this->importedDays($result->connection));
        self::assertSame([], $this->repository()->sumByPage('main', $result->connection, 20261119, 20261119));
    }

    #[Test]
    public function theBudgetStopsTheRunAtTheNewestDays(): void
    {
        $result = $this->import(budget: 2, retentionDays: 5);

        self::assertSame(['2026-11-23', '2026-11-22'], $this->requested);
        self::assertSame(2, $result->requests);
        self::assertSame(3, $result->remainingDays);

        $this->requested = [];
        $result = $this->import(budget: 2, retentionDays: 5);

        // Today again (not final), then the backfill goes on
        self::assertSame(['2026-11-23', '2026-11-21'], $this->requested);
        self::assertSame(2, $result->remainingDays);
    }

    #[Test]
    public function excludedDaysAreSkipped(): void
    {
        $result = $this->import(retentionDays: 3, exclude: [20261123, 20261122]);

        self::assertSame(['2026-11-21'], $this->requested);
        self::assertSame([20261121], $result->importedDays);
    }

    #[Test]
    public function aRangeImportsItsDaysAgainWithinTheRetention(): void
    {
        $this->import(retentionDays: 5);
        $this->requested = [];

        $result = $this->import(retentionDays: 5, range: ['from' => 20261101, 'to' => 20261120]);

        self::assertSame(['2026-11-20', '2026-11-19'], $this->requested);
        self::assertSame([20261120, 20261119], $result->importedDays);

        $this->requested = [];
        $this->import(retentionDays: 5, range: ['from' => 20261122, 'to' => 20261130]);
        self::assertSame(['2026-11-23', '2026-11-22'], $this->requested);
    }

    #[Test]
    public function aFailingDayIsRecordedAndTheNextDayImported(): void
    {
        $this->failing['2026-11-22'] = 'Archiving timed out';

        $result = $this->import(retentionDays: 4);

        self::assertSame(['2026-11-23', '2026-11-22', '2026-11-21', '2026-11-20'], $this->requested);
        self::assertSame([20261123, 20261121, 20261120], $result->importedDays);
        self::assertSame([20261122 => 'Matomo API error: Archiving timed out'], $result->failedDays);
        self::assertTrue($result->hasFailures());
        self::assertSame(4, $result->requests);
        self::assertSame([20261120, 20261121, 20261123], $this->importedDays($result->connection));
    }

    #[Test]
    public function anUnexpectedReportFailsTheDay(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => static fn (RequestInterface $request): FulfilledPromise => new FulfilledPromise(new Response(200, [], '[{"value": 3}]'));

        $result = $this->import(retentionDays: 1);

        self::assertSame([20261123], array_keys($result->failedDays));
        self::assertSame([], $this->importedDays($result->connection));
    }

    #[Test]
    public function demoConfigurationsAreNotImported(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->importer()->importConnection([$this->site('main')], MatomoConfiguration::demo('https://example.com/', 1), 10, 10, 10);
    }

    /**
     * @param list<Site>|null $sites
     * @param array{from: int, to: int}|null $range
     * @param list<int> $exclude
     */
    private function import(?array $sites = null, int $budget = 50, int $retentionDays = 400, ?array $range = null, array $exclude = []): ImportResult
    {
        return $this->importer()->importConnection(
            $sites ?? [$this->site('main')],
            new MatomoConfiguration('https://matomo.example.com/', 3, 'secret-token'),
            $budget,
            30,
            $retentionDays,
            $range,
            $exclude,
        );
    }

    /**
     * @return list<int>
     */
    private function importedDays(int $connection): array
    {
        return array_map(intval(...), $this->getConnectionPool()->getConnectionForTable(ImportRepository::TABLE)
            ->select(['day'], ImportRepository::TABLE, ['connection' => $connection], [], ['day' => 'ASC'])
            ->fetchFirstColumn());
    }

    private function setNow(string $now): void
    {
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable($now)));
    }

    private function site(string $identifier): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier($identifier);
    }

    private function importer(): PageViewImporter
    {
        $pool = $this->getConnectionPool();
        return new PageViewImporter(
            $this->get(MatomoClient::class),
            $this->get(Context::class),
            $pool,
            new ConnectionRepository($pool),
            new UrlDayRepository($pool),
            new ImportRepository($pool),
            new UrlPageMapper($this->get(PageUrlMapper::class), $pool),
            new NullLogger(),
        );
    }

    private function repository(): UrlDayRepository
    {
        return new UrlDayRepository($this->getConnectionPool());
    }
}
