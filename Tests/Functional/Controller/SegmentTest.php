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

final class SegmentTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<array<string, string>> parameters of every sub-request sent to Matomo */
    private array $calls = [];
    private string $segments = 'SyntheticSegments';
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_visitors,analytics_matomo_behaviour,analytics_matomo_page',
        ], ['uid' => 1]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
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
    public function docheaderOffersOnlyPreProcessedSegmentsOfTheSite(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();

        self::assertStringContainsString('Segment: All visits', $html);
        self::assertStringContainsString('Mobile users', $html);
        self::assertStringContainsString('Returning visitors', $html);
        self::assertStringNotContainsString('Ad-hoc German visitors', $html);
        self::assertStringNotContainsString('Other site', $html);
        $list = $this->callsTo('SegmentEditor.getAll');
        self::assertCount(1, $list);
        self::assertSame('3', $list[0]['idSite']);
        foreach ($this->calls as $call) {
            self::assertArrayNotHasKey('segment', $call);
        }
    }

    #[Test]
    public function selectedSegmentIsSentWithEveryReportAndSharedAcrossSections(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices', 'segment' => '100'])->getBody();

        self::assertStringContainsString('Segment: Mobile users', $html);
        self::assertStringContainsString('<td>Smartphone</td>', $html);
        self::assertStringNotContainsString('<td>Desktop</td>', $html);
        $reports = $this->callsTo('API.getProcessedReport');
        self::assertCount(4, $reports);
        foreach ($reports as $report) {
            self::assertSame('deviceType==Smartphone,deviceType==Tablet', $report['segment']);
        }

        $this->calls = [];
        $this->render(1, ['id' => '1', 'report' => 'overview'], 'visitors');
        $overview = $this->callsTo('API.get');
        self::assertNotEmpty($overview);
        foreach ([...$overview, ...$this->callsTo('Live.getCounters')] as $call) {
            self::assertSame('deviceType==Smartphone,deviceType==Tablet', $call['segment']);
        }

        $this->calls = [];
        $this->render(1, ['id' => '2', 'language' => '0', 'transitions' => '1'], 'page');
        $pageCalls = [...$this->callsTo('Actions.getPageUrl'), ...$this->callsTo('Transitions.getTransitionsForAction')];
        self::assertCount(1, $this->callsTo('Transitions.getTransitionsForAction'));
        foreach ($pageCalls as $call) {
            self::assertSame('deviceType==Smartphone,deviceType==Tablet', $call['segment']);
        }
    }

    #[Test]
    public function segmentIsPartOfTheCacheKeyAndCanBeReset(): void
    {
        $this->render(1, ['id' => '1', 'report' => 'devices']);
        $this->render(1, ['id' => '1', 'report' => 'devices', 'segment' => '100']);
        $this->render(1, ['id' => '1', 'report' => 'devices', 'segment' => '100']);
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices', 'segment' => ''])->getBody();

        self::assertStringContainsString('Segment: All visits', $html);
        self::assertStringContainsString('<td>Desktop</td>', $html);
        self::assertCount(8, $this->callsTo('API.getProcessedReport'));
        self::assertCount(1, $this->callsTo('SegmentEditor.getAll'));
    }

    #[Test]
    public function segmentsThatAreNotOfferedAreIgnored(): void
    {
        foreach (['102', '103', '999'] as $segment) {
            $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices', 'segment' => $segment])->getBody();
            self::assertStringContainsString('Segment: All visits', $html);
        }
        foreach ($this->callsTo('API.getProcessedReport') as $call) {
            self::assertArrayNotHasKey('segment', $call);
        }
    }

    #[Test]
    public function segmentIsStoredPerSite(): void
    {
        $this->render(1, ['id' => '1', 'report' => 'devices', 'segment' => '100']);
        $this->calls = [];
        $shop = (string)$this->render(1, ['id' => '10', 'report' => 'devices'])->getBody();

        self::assertStringContainsString('Segment: All visits', $shop);
        foreach ($this->callsTo('API.getProcessedReport') as $call) {
            self::assertArrayNotHasKey('segment', $call);
        }
        $main = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();
        self::assertStringContainsString('Segment: Mobile users', $main);
    }

    #[Test]
    public function segmentWithoutArchivedDataShowsAnEmptyState(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'locations', 'segment' => '101'])->getBody();

        self::assertStringContainsString('No archived data for the segment “Returning visitors”', $html);
    }

    #[Test]
    public function withoutStoredSegmentsTheControlIsHidden(): void
    {
        $this->segments = 'Empty';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices'])->getBody();

        self::assertStringNotContainsString('Segment:', $html);
    }

    #[Test]
    public function failingSegmentListDoesNotBreakTheReports(): void
    {
        $this->segments = 'ErrorNoViewAccess';
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'devices', 'segment' => '100'])->getBody();

        self::assertStringNotContainsString('Segment:', $html);
        self::assertStringContainsString('<td>Desktop</td>', $html);
    }

    #[Test]
    public function demoSitesOfferSegmentsWithSmallerNumbersWithoutHttp(): void
    {
        $all = $this->firstVisits((string)$this->render(1, ['id' => '30', 'report' => 'devices'])->getBody());
        $html = (string)$this->render(1, ['id' => '30', 'report' => 'devices', 'segment' => '1'])->getBody();

        self::assertStringContainsString('Segment: Mobile users', $html);
        self::assertStringNotContainsString('New visitors', $html);
        self::assertLessThan($all, $this->firstVisits($html));
        self::assertSame([], $this->calls);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function answer(array $parameters): mixed
    {
        $method = $parameters['method'];
        if ($method === 'SegmentEditor.getAll') {
            return $this->segments === 'Empty' ? [] : $this->fixture($this->segments)[0];
        }
        if ($method === 'API.getProcessedReport') {
            if (($parameters['segment'] ?? '') === 'visitorType==returning') {
                $report = $this->fixture('ProcessedVisitorsDevicesSegment')[0];
                return ['reportData' => [], 'reportMetadata' => [], 'reportTotal' => []] + $report;
            }
            return $this->fixture(isset($parameters['segment']) ? 'ProcessedVisitorsDevicesSegment' : 'ProcessedVisitorsDevices')[0];
        }
        if ($method === 'Live.getCounters') {
            return [['visits' => 1, 'actions' => 2, 'visitors' => 1]];
        }
        if ($method === 'API.get') {
            return $this->fixture('SyntheticOverview')[$parameters['period'] === 'range' ? 'range' : 'evolution'];
        }
        if ($method === 'Transitions.getTransitionsForAction') {
            return $this->fixture('Transitions')[0];
        }
        if ($method === 'Actions.getPageUrl') {
            return $this->fixture('PageDetails')[$parameters['period'] === 'range' ? 0 : 1];
        }
        // Actions.getPageUrls and Goals.getGoals: no rows
        return [];
    }

    /** @return list<array<string, string>> */
    private function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    private function firstVisits(string $html): int
    {
        self::assertSame(1, preg_match('#<td>Desktop</td>\s*<td>([\d,]+)</td>#', $html, $matches));
        return (int)str_replace(',', '', $matches[1] ?? '');
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
