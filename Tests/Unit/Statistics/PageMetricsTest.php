<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageMetricsTest extends UnitTestCase
{
    #[Test]
    public function fromApiResultReadsFirstRow(): void
    {
        self::assertEquals(
            new PageMetrics(29, 0.84, 9, 0.96),
            PageMetrics::fromApiResult([['nb_hits' => '29', 'bounce_rate' => 0.84, 'avg_time_on_page' => 9, 'exit_rate' => '0.96']])
        );
    }

    #[Test]
    public function fromApiResultTreatsEmptyListAsNoData(): void
    {
        self::assertEquals(new PageMetrics(), PageMetrics::fromApiResult([]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedResults(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['Maintenance'];
        yield 'error object' => [['result' => 'error', 'message' => 'Archiving failed']];
        yield 'list of scalars' => [[1, 2]];
    }

    #[Test]
    #[DataProvider('malformedResults')]
    public function fromApiResultRejectsMalformedResults(mixed $result): void
    {
        $this->expectException(MatomoApiException::class);
        $this->expectExceptionCode(1793960006);

        PageMetrics::fromApiResult($result);
    }
}
