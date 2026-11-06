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
    public function getStatisticsRequestsCurrentAndPreviousPeriodAndSingleDays(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->with(self::anything(), 'POST', self::callback(static function (array $options): bool {
                $pageUrl = 'pageUrl=https%3A%2F%2Fexample.com%2Fabout';
                self::assertSame([
                    'method=Actions.getPageUrl&' . $pageUrl . '&format_metrics=0&period=range&date=2026-10-31%2C2026-11-06&idSite=3',
                    'method=Actions.getPageUrl&' . $pageUrl . '&format_metrics=0&period=range&date=2026-10-24%2C2026-10-30&idSite=3',
                    'method=Actions.getPageUrl&' . $pageUrl . '&format_metrics=0&period=day&date=2026-10-31%2C2026-11-06&idSite=3',
                ], $options['form_params']['urls']);
                return true;
            }))
            ->willReturn($this->response(json_encode([
                [['nb_hits' => 29, 'bounce_rate' => 0.84, 'avg_time_on_page' => 9, 'exit_rate' => 0.96]],
                [],
                ['2026-11-05' => [['nb_hits' => '7']], '2026-11-06' => []],
            ], JSON_THROW_ON_ERROR)));

        $subject = $this->subject($requestFactory);
        $statistics = $subject->getStatistics(new MatomoConfiguration('https://matomo.example.com', 3, ''), 'https://example.com/about', 7);

        self::assertEquals(new PageMetrics(29, 0.84, 9, 0.96), $statistics->current);
        self::assertEquals(new PageMetrics(), $statistics->previous);
        self::assertCount(7, $statistics->daily);
        self::assertSame('2026-10-31', array_key_first($statistics->daily));
        self::assertSame(7, $statistics->daily['2026-11-05']->views);
        self::assertSame(0, $statistics->daily['2026-11-06']->views);

        // Second call is served from the cache: request() is expected once only
        self::assertEquals($statistics, $subject->getStatistics(new MatomoConfiguration('https://matomo.example.com', 3, ''), 'https://example.com/about', 7));
    }

    #[Test]
    public function getStatisticsDoesNotCacheMalformedResults(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(fn (): Response => $this->response('[[], [], [[{"nb_hits": 3}]]]'));
        $subject = $this->subject($requestFactory);
        $configuration = new MatomoConfiguration('https://matomo.example.com', 3, '');

        for ($i = 0; $i < 2; $i++) {
            try {
                $subject->getStatistics($configuration, 'https://example.com/about', 7);
                self::fail('Expected a MatomoApiException');
            } catch (MatomoApiException $e) {
                self::assertSame(1793960007, $e->getCode());
            }
        }
    }

    private function subject(RequestFactory $requestFactory): PageStatisticsService
    {
        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-06 14:30:00')));

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
