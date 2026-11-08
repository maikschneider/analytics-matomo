<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Matomo;

use MaikSchneider\AnalyticsMatomo\Annotation\Annotation;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Dashboard\SiteStatisticsService;
use MaikSchneider\AnalyticsMatomo\Matomo\DemoData;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Module\TrendingService;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Tests\Unit\InMemoryCacheTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DemoDataTest extends UnitTestCase
{
    use InMemoryCacheTrait;

    protected bool $resetSingletonInstances = true;

    private MatomoConfiguration $configuration;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = MatomoConfiguration::demo('https://example.com/', 1);
        $this->period = Period::lastDays(7, new \DateTimeImmutable('2026-11-06'));
    }

    #[Test]
    public function clientAnswersDemoConfigurationsWithoutHttpRequest(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        $results = (new MatomoClient($requestFactory))->bulkRequest($this->configuration, [
            ['method' => 'VisitsSummary.get', 'period' => 'range', 'date' => $this->period->toMatomoDate()],
        ]);

        self::assertGreaterThan(0, $results[0]['nb_visits']);
    }

    #[Test]
    public function segmentsAreSmallerSharesOfTheSameSite(): void
    {
        [$segments] = (new DemoData())->results([['method' => 'SegmentEditor.getAll']], $this->configuration);
        self::assertIsArray($segments);
        $definitions = array_column($segments, 'definition', 'name');
        self::assertArrayHasKey('Mobile users', $definitions);

        $request = ['method' => 'API.get', 'period' => 'range', 'date' => '2026-10-01,2026-10-31'];
        [$all] = (new DemoData())->results([$request], $this->configuration);
        [$mobile] = (new DemoData())->results([$request], $this->configuration->withSegment($definitions['Mobile users']));
        self::assertIsArray($all);
        self::assertIsArray($mobile);
        self::assertGreaterThan(0, $mobile['nb_visits']);
        self::assertLessThan($all['nb_visits'], $mobile['nb_visits']);
    }

    #[Test]
    public function annotationsMarkChangesOfDemoPagesAndASiteEventInTheRequestedRange(): void
    {
        $today = new \DateTimeImmutable('today');
        $period = Period::lastDays(30, $today);
        $request = ['method' => 'Annotations.getAll', 'period' => 'range', 'date' => $period->toMatomoDate()];
        [$result, $again] = (new DemoData())->results([$request, $request], $this->configuration);
        self::assertSame($result, $again);

        $annotations = Annotation::listFromApiResult($result);
        self::assertGreaterThanOrEqual(2, count($annotations));
        self::assertNotSame([], Annotation::forPage($annotations, 'https://example.com/'));
        foreach ($annotations as $annotation) {
            self::assertGreaterThanOrEqual($period->from->format('Y-m-d'), $annotation->date);
            self::assertLessThan($today->format('Y-m-d'), $annotation->date);
        }

        [$older] = (new DemoData())->results([['method' => 'Annotations.getAll', 'period' => 'range', 'date' => '2020-01-01,2020-01-31']], $this->configuration);
        self::assertSame([], Annotation::listFromApiResult($older));
    }

    #[Test]
    public function rangesAddUpTheDaysAndAreStable(): void
    {
        $request = ['method' => 'VisitsSummary.get', 'format_metrics' => 0];
        [$range, $days, $again] = (new DemoData())->results([
            $request + ['period' => 'range', 'date' => $this->period->toMatomoDate()],
            $request + ['period' => 'day', 'date' => $this->period->toMatomoDate()],
            $request + ['period' => 'range', 'date' => $this->period->toMatomoDate()],
        ], $this->configuration);

        self::assertSame($this->period->dates(), array_keys($days));
        self::assertSame(array_sum(array_column($days, 'nb_visits')), $range['nb_visits']);
        self::assertSame($range, $again);
        self::assertGreaterThan(0.0, $range['bounce_rate']);
        self::assertLessThan(1.0, $range['bounce_rate']);
    }

    #[Test]
    public function pagesRiseAndFallBetweenWeeksAboveTheTrendingThresholds(): void
    {
        $request = ['method' => 'Actions.getPageUrls', 'flat' => 1, 'period' => 'range', 'filter_limit' => -1];
        [$current, $previous, $report] = (new DemoData())->results([
            $request + ['date' => $this->period->toMatomoDate()],
            $request + ['date' => $this->period->previous()->toMatomoDate()],
            ['method' => 'API.getProcessedReport', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls', 'flat' => 1, 'period' => 'range', 'date' => $this->period->toMatomoDate(), 'filter_limit' => 1],
        ], $this->configuration);

        self::assertSame(array_sum(array_column($current, 'nb_hits')), $report['reportTotal']['nb_hits']);
        $before = array_column($previous, 'nb_hits', 'url');
        $trends = array_map(static fn (array $row): ?string => TrendingService::trend($before[$row['url']] ?? 0, $row['nb_hits'], $report['reportTotal']['nb_hits']), $current);
        self::assertContains('up', $trends);
        self::assertContains('down', $trends);
        self::assertContains(null, $trends);
    }

    #[Test]
    public function pageUrlsFilteredByUrlPatternAnswerWithTimingsOfThatPage(): void
    {
        [$result] = (new DemoData())->results([[
            'method' => 'Actions.getPageUrls', 'flat' => 1, 'period' => 'range', 'date' => $this->period->toMatomoDate(),
            'filter_column' => 'url', 'filter_pattern' => '^https?://example\.com/about$', 'filter_limit' => -1,
        ]], $this->configuration);

        self::assertCount(1, $result);
        self::assertSame('https://example.com/about', $result[0]['url']);
        self::assertGreaterThan(0, $result[0]['nb_hits_with_time_network']);
        self::assertGreaterThan(0, $result[0]['avg_page_load_time']);
        self::assertGreaterThan(0, $result[0]['nb_hits_with_time_server']);
        self::assertGreaterThan(0, $result[0]['goals']['idgoal=1']['nb_conversions_page_uniq']);
        self::assertLessThanOrEqual($result[0]['goals']['idgoal=1']['nb_conversions_page_uniq'], $result[0]['goals']['idgoal=1']['nb_conversions_attrib']);
    }

    #[Test]
    public function transitionsLinkThePageWithOtherPagesOfTheSite(): void
    {
        [$result] = (new DemoData())->results([[
            'method' => 'Transitions.getTransitionsForAction', 'actionType' => 'url', 'actionName' => 'https://example.com/about',
            'period' => 'range', 'date' => $this->period->toMatomoDate(),
        ]], $this->configuration);

        self::assertSame(['previousPages', 'followingPages', 'outlinks', 'downloads', 'referrers', 'pageMetrics'], array_keys($result));
        $previous = array_column($result['previousPages'], 'label');
        self::assertContains('example.com/', $previous);
        self::assertNotContains('example.com/about', $previous);
        $referrals = array_column($result['previousPages'], 'referrals');
        $sorted = $referrals;
        rsort($sorted);
        self::assertSame($sorted, $referrals);
        self::assertNotEmpty($result['followingPages']);
        self::assertNotEmpty($result['outlinks']);
        self::assertSame('search', $result['referrers'][0]['shortName']);
        self::assertNotEmpty($result['referrers'][0]['details']);
    }

    #[Test]
    public function goalsAddUpOverTheGoalsAndTheirChannels(): void
    {
        $date = ['period' => 'range', 'date' => $this->period->toMatomoDate(), 'format_metrics' => 0];
        [$goals, $all, $first, $second, $channels, $entryPages] = (new DemoData())->results([
            ['method' => 'Goals.getGoals'],
            ['method' => 'Goals.get'] + $date,
            ['method' => 'Goals.get', 'idGoal' => 1] + $date,
            ['method' => 'Goals.get', 'idGoal' => 2] + $date,
            ['method' => 'Referrers.getReferrerType', 'idGoal' => 1, 'filter_limit' => -1] + $date,
            ['method' => 'Actions.getEntryPageUrls', 'idGoal' => 1, 'flat' => 1, 'filter_limit' => -1] + $date,
        ], $this->configuration);

        self::assertSame([1, 2], array_column($goals, 'idgoal'));
        self::assertGreaterThan(0, $first['nb_conversions']);
        self::assertSame($first['nb_conversions'] + $second['nb_conversions'], $all['nb_conversions']);
        self::assertGreaterThan(0, $all['revenue']);
        self::assertSame($first['nb_conversions'], array_sum(array_map(static fn (array $row): int => $row['goals']['idgoal=1']['nb_conversions'] ?? 0, $channels)));
        self::assertSame('https://example.com/', $entryPages[0]['url']);
        self::assertSame($first['nb_conversions'], array_sum(array_map(static fn (array $row): int => $row['goals']['idgoal=1']['nb_conversions_entry'] ?? 0, $entryPages)));
    }

    #[Test]
    public function pageStatisticsUseThePageUrlOfTheRequest(): void
    {
        [$result] = (new DemoData())->results([
            ['method' => 'Actions.getPageUrl', 'pageUrl' => 'https://example.com/about', 'period' => 'range', 'date' => $this->period->toMatomoDate()],
        ], $this->configuration);

        self::assertCount(1, $result);
        self::assertSame('https://example.com/about', $result[0]['url']);
        self::assertGreaterThan(0, $result[0]['nb_hits']);
    }

    #[Test]
    public function everySiteHasItsOwnNumbers(): void
    {
        $request = ['method' => 'VisitsSummary.get', 'period' => 'range', 'date' => $this->period->toMatomoDate()];
        $demoData = new DemoData();

        [$main] = $demoData->results([$request], $this->configuration);
        [$shop] = $demoData->results([$request], MatomoConfiguration::demo('https://shop.example.com/', 10));

        self::assertNotSame($main['nb_visits'], $shop['nb_visits']);
    }

    #[Test]
    public function deeperPagesHaveFewerViews(): void
    {
        $request = ['method' => 'Actions.getPageUrl', 'period' => 'range', 'date' => $this->period->toMatomoDate()];
        [[$home], [$deep]] = (new DemoData())->results([
            $request + ['pageUrl' => 'https://example.com/'],
            $request + ['pageUrl' => 'https://example.com/bikes/e-bikes/cargo/long-john/'],
        ], $this->configuration);

        self::assertGreaterThan($deep['nb_hits'], $home['nb_hits']);
    }

    #[Test]
    public function servesEveryDashboardReport(): void
    {
        $service = new SiteStatisticsService(
            new MatomoClient($this->createMock(RequestFactory::class)),
            $this->createStatisticsCache(),
        );

        $performance = $service->getPerformance($this->configuration, $this->period);
        self::assertGreaterThan(0, $performance->current->visits);
        self::assertGreaterThan(0, $performance->previous->pageViews);
        self::assertCount(7, $performance->daily);

        $traffic = $service->getTraffic($this->configuration, $this->period);
        self::assertSame(array_sum($traffic->visits), array_sum($traffic->newVisits) + array_sum($traffic->returningVisits));

        $pages = $service->getTopPages($this->configuration, $this->period);
        self::assertStringStartsWith('https://example.com/', $pages[0]->url);
        self::assertGreaterThan(0, $pages[0]->previousViews);
    }

    #[Test]
    public function demoConfigurationHasNoReportUrl(): void
    {
        self::assertSame('', $this->configuration->getReportUrl($this->period));
    }
}
