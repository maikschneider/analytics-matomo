<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Dashboard;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Dashboard\Breakdown;
use MaikSchneider\AnalyticsMatomo\Dashboard\SiteStatisticsService;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Tests\Unit\InMemoryCacheTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Replays bulk responses recorded from demo.matomo.org (idSite 3, 2026-10-27 to 2026-11-02).
 */
final class SiteStatisticsServiceTest extends UnitTestCase
{
    use InMemoryCacheTrait;

    protected bool $resetSingletonInstances = true;

    /**
     * @var array<string, mixed> form parameters of the last request
     */
    private array $sentBody = [];

    #[Test]
    public function getPerformanceComparesWithThePreviousPeriod(): void
    {
        $performance = $this->subject('SitePerformance')->getPerformance($this->configuration(), $this->period());

        self::assertSame(29, $performance->current->visits);
        self::assertSame(43, $performance->current->pageViews);
        self::assertSame(0.72, $performance->current->bounceRate);
        self::assertSame(3, $performance->current->averageTimeOnSite);
        self::assertSame(37, $performance->previous->pageViews);
        self::assertSame(0.79, $performance->previous->bounceRate);
        self::assertSame(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], array_keys($performance->daily));
        self::assertSame(8, $performance->daily['2026-10-30']->visits);
        self::assertSame(10, $performance->daily['2026-10-30']->pageViews);
        self::assertSame([
            'method=VisitsSummary.get&format_metrics=0&period=range&date=2026-10-27%2C2026-11-02&idSite=3',
            'method=VisitsSummary.get&format_metrics=0&period=range&date=2026-10-20%2C2026-10-26&idSite=3',
            'method=Actions.get&format_metrics=0&showColumns=nb_pageviews&period=range&date=2026-10-27%2C2026-11-02&idSite=3',
            'method=Actions.get&format_metrics=0&showColumns=nb_pageviews&period=range&date=2026-10-20%2C2026-10-26&idSite=3',
            'method=VisitsSummary.get&format_metrics=0&period=day&date=2026-10-27%2C2026-11-02&idSite=3',
            'method=Actions.get&format_metrics=0&showColumns=nb_pageviews&period=day&date=2026-10-27%2C2026-11-02&idSite=3',
        ], $this->sentBody['urls']);
    }

    #[Test]
    public function getPerformanceOfAPeriodWithoutVisitsIsZero(): void
    {
        $performance = $this->subject('EmptySitePerformance')->getPerformance($this->configuration(), Period::lastDays(7, new \DateTimeImmutable('2001-01-07')));

        self::assertSame(0, $performance->current->visits);
        self::assertSame(0, $performance->current->pageViews);
        self::assertSame(0, $performance->daily['2001-01-03']->visits);
    }

    #[Test]
    public function getTrafficSplitsVisitsIntoNewAndReturning(): void
    {
        $traffic = $this->subject('Traffic')->getTraffic($this->configuration(), $this->period());

        self::assertSame(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], $traffic->dates);
        self::assertSame([6, 1, 2, 8, 1, 7, 4], $traffic->visits);
        self::assertSame([6, 1, 2, 8, 1, 6, 4], $traffic->visitors);
        // The recorded period has no returning visits: Matomo answers `[]` per day
        self::assertSame([0, 0, 0, 0, 0, 0, 0], $traffic->returningVisits);
        self::assertSame([6, 1, 2, 8, 1, 7, 4], $traffic->newVisits);
    }

    #[Test]
    public function getTrafficSubtractsReturningFromAllVisits(): void
    {
        $traffic = $this->subject('SyntheticTrafficWithReturningVisits')->getTraffic($this->configuration(), $this->period());

        self::assertSame([2, 0, 1, 3, 0, 7, 1], $traffic->returningVisits);
        self::assertSame([4, 1, 1, 5, 1, 0, 3], $traffic->newVisits);
    }

    #[Test]
    public function getTrafficFillsDaysWithoutVisits(): void
    {
        $traffic = $this->subject('EmptyTraffic')->getTraffic($this->configuration(), Period::lastDays(7, new \DateTimeImmutable('2001-01-07')));

        self::assertSame([0, 0, 0, 0, 0, 0, 0], $traffic->visits);
        self::assertSame(0, $traffic->totalVisits());
    }

    #[Test]
    public function getTopPagesReturnsViewsSharesAndPreviousViews(): void
    {
        $pages = $this->subject('TopPages')->getTopPages($this->configuration(), $this->period());

        self::assertCount(6, $pages);
        self::assertSame('http://www.virtual-drums.com/', $pages[0]->url);
        self::assertSame(31, $pages[0]->views);
        self::assertSame(23, $pages[0]->previousViews);
        self::assertEqualsWithDelta(31 / 43, $pages[0]->share, 0.0001);
        // Not among the pages of the previous period
        self::assertSame('http://www.virtual-drums.com/photos.php/screenshots-virtual-drums-3/', $pages[3]->url);
        self::assertSame(0, $pages[3]->previousViews);
    }

    #[Test]
    public function getTopPagesOfAPeriodWithoutVisitsIsEmpty(): void
    {
        self::assertSame([], $this->subject('EmptyTopPages')->getTopPages($this->configuration(), $this->period()));
    }

    #[Test]
    public function getBreakdownSendsTheLanguageAndComparesShares(): void
    {
        $breakdown = $this->subject('Referrers.getReferrerType')->getBreakdown($this->configuration(), $this->period(), Breakdown::CHANNEL, 'de');

        self::assertSame('de', $this->sentBody['language']);
        self::assertSame(29, $breakdown->total);
        self::assertSame(['Suchmaschinen', 'Direkte Zugriffe', 'KI-Assistenten'], array_column($breakdown->rows, 'label'));
        self::assertSame(23, $breakdown->rows[0]->visits);
        self::assertEqualsWithDelta(23 / 29, $breakdown->rows[0]->share, 0.0001);
        // 14 of 29 visits before: +31.0 percentage points
        self::assertEqualsWithDelta(23 / 29 - 14 / 29, $breakdown->rows[0]->shareChange, 0.0001);
        self::assertNull($breakdown->rows[2]->shareChange);
    }

    #[Test]
    public function getBreakdownGroupsTheRestAsOtherAndSkipsRowsWithoutVisits(): void
    {
        $browsers = $this->subject('DevicesDetection.getBrowsers')->getBreakdown($this->configuration(), $this->period(), Breakdown::BROWSERS, 'de');

        self::assertSame(['Chrome', 'Chrome Mobile', 'Microsoft Edge', 'Safari', ''], array_column($browsers->rows, 'label'));
        self::assertTrue($browsers->rows[4]->isOther);
        self::assertSame(3, $browsers->rows[4]->visits);

        $devices = $this->subject('DevicesDetection.getType')->getBreakdown($this->configuration(), $this->period(), Breakdown::DEVICES, 'de');

        self::assertSame(['Desktop', 'Smartphone', 'Tablet', 'TV'], array_column($devices->rows, 'label'));
    }

    #[Test]
    public function getBreakdownOfAPeriodWithoutVisitsIsEmpty(): void
    {
        $breakdown = $this->subject('EmptyBreakdown')->getBreakdown($this->configuration(), $this->period(), Breakdown::COUNTRIES, 'de');

        self::assertSame(0, $breakdown->total);
        self::assertSame([], $breakdown->rows);
    }

    #[Test]
    public function getBreakdownRejectsUnknownReports(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->subject('EmptyBreakdown')->getBreakdown($this->configuration(), $this->period(), 'Live.getLastVisitsDetails', 'de');
    }

    #[Test]
    public function errorsOfMatomoArePassedOn(): void
    {
        $this->expectException(MatomoApiException::class);
        $this->expectExceptionMessage("requires 'view' access");

        $this->subject('ErrorNoViewAccess')->getTraffic($this->configuration(), $this->period());
    }

    #[Test]
    public function resultsFetchedWithOneTokenNeverAnswerAnotherToken(): void
    {
        $subject = $this->subject('SitePerformance');

        $subject->getPerformance(new MatomoConfiguration('https://matomo.example.com', 3, 'token-with-view-access'), $this->period());
        $subject->getPerformance(new MatomoConfiguration('https://matomo.example.com', 3, 'other-token'), $this->period());

        self::assertSame('other-token', $this->sentBody['token_auth']);
    }

    private function subject(string $fixture): SiteStatisticsService
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(function (string $url, string $method, array $options) use ($fixture): Response {
            $this->sentBody = $options['form_params'];
            $body = new Stream('php://temp', 'rw');
            $body->write((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $fixture . '.json'));
            return new Response($body, 200);
        });

        return new SiteStatisticsService(
            new MatomoClient($requestFactory),
            $this->createStatisticsCache(),
        );
    }

    private function configuration(): MatomoConfiguration
    {
        return new MatomoConfiguration('https://matomo.example.com', 3, '');
    }

    private function period(): Period
    {
        return Period::lastDays(7, new \DateTimeImmutable('2026-11-02 10:00:00'));
    }
}
