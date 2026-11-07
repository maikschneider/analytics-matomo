<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Matomo;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Dashboard\SiteStatisticsService;
use MaikSchneider\AnalyticsMatomo\Matomo\DemoData;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
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
