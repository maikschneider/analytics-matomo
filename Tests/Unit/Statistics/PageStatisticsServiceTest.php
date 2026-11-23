<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\PageStatisticsService;
use MaikSchneider\AnalyticsMatomo\Tests\Unit\InMemoryCacheTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageStatisticsServiceTest extends UnitTestCase
{
    use InMemoryCacheTrait;

    protected bool $resetSingletonInstances = true;

    #[Test]
    public function getStatisticsAggregatesBothPeriodsFromOneDayByDayRequest(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->with(self::anything(), 'POST', self::callback(static function (array $options): bool {
                self::assertSame([
                    'method=Actions.getPageUrl&pageUrl=http%3A%2F%2Fwww.virtual-drums.com%2F&format_metrics=0&period=day&date=2026-10-20%2C2026-11-02&idSite=3',
                ], $options['form_params']['urls']);
                return true;
            }))
            ->willReturn($this->response((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/PageStatisticsDays.json')));

        $subject = $this->subject($requestFactory);
        $configuration = new MatomoConfiguration('https://matomo.example.com', 3, '');
        $statistics = $subject->getStatistics($configuration, 'http://www.virtual-drums.com/', 7);

        // Matomo answered the same periods as range with these (rounded) values
        self::assertSame(31, $statistics->current->views);
        self::assertSame(0.76, round($statistics->current->bounceRate, 2));
        self::assertSame(6, $statistics->current->averageTimeOnPage);
        self::assertSame(0.92, round($statistics->current->exitRate, 2));
        self::assertSame(23, $statistics->previous->views);
        self::assertSame(0.74, round($statistics->previous->bounceRate, 2));
        self::assertSame(2, $statistics->previous->averageTimeOnPage);
        self::assertSame(0.95, round($statistics->previous->exitRate, 2));

        self::assertSame(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], array_keys($statistics->daily));
        self::assertSame(31, array_sum(array_map(static fn (PageMetrics $day): int => $day->views, $statistics->daily)));

        // Second call is served from the cache: request() is expected once only
        self::assertEquals($statistics, $subject->getStatistics($configuration, 'http://www.virtual-drums.com/', 7));
    }

    #[Test]
    public function getStatisticsDoesNotCacheMalformedResults(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(fn (): Response => $this->response('[[{"nb_hits": 3}]]'));
        $subject = $this->subject($requestFactory);
        $configuration = new MatomoConfiguration('https://matomo.example.com', 3, '');

        for ($i = 0; $i < 2; $i++) {
            try {
                $subject->getStatistics($configuration, 'https://example.com/about', 7);
                self::fail('Expected a MatomoApiException');
            } catch (MatomoApiException $e) {
                self::assertSame(1793960006, $e->getCode());
            }
        }
    }

    private function subject(RequestFactory $requestFactory): PageStatisticsService
    {
        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));

        return new PageStatisticsService(new MatomoClient($requestFactory), $context, $this->createStatisticsCache());
    }

    private function response(string $body): Response
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();
        return new Response($stream);
    }
}
