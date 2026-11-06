<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\PageStatistics;
use MaikSchneider\AnalyticsMatomo\Statistics\Tile;
use MaikSchneider\AnalyticsMatomo\Statistics\TileFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TileFactoryTest extends UnitTestCase
{
    #[Test]
    public function createFormatsValuesAndRatesTrends(): void
    {
        $tiles = $this->tilesByKey(new PageStatistics(
            current: new PageMetrics(views: 1234, bounceRate: 0.84, averageTimeOnPage: 95, exitRate: 0.5),
            previous: new PageMetrics(views: 1000, bounceRate: 0.80, averageTimeOnPage: 100, exitRate: 0.5),
            daily: [],
        ));

        self::assertSame("1\u{202F}234", $tiles['views']->value);
        self::assertSame('+234', $tiles['views']->trend);
        self::assertSame('actions-arrow-up', $tiles['views']->trendIcon);
        self::assertSame('positive', $tiles['views']->trendRating);

        // A rising bounce rate is bad news
        self::assertSame('84.0 %', $tiles['bounceRate']->value);
        self::assertSame('+4.0 %', $tiles['bounceRate']->trend);
        self::assertSame('negative', $tiles['bounceRate']->trendRating);

        self::assertSame('1:35', $tiles['averageTimeOnPage']->value);
        self::assertSame('−0:05', $tiles['averageTimeOnPage']->trend);
        self::assertSame('actions-arrow-down', $tiles['averageTimeOnPage']->trendIcon);
        self::assertSame('negative', $tiles['averageTimeOnPage']->trendRating);

        self::assertSame('50.0 %', $tiles['exitRate']->value);
        self::assertNull($tiles['exitRate']->trend);
        self::assertNull($tiles['exitRate']->trendRating);
    }

    #[Test]
    public function createDrawsSparklineOverDays(): void
    {
        $tiles = $this->tilesByKey(new PageStatistics(
            current: new PageMetrics(views: 15),
            previous: new PageMetrics(),
            daily: [
                '2026-11-04' => new PageMetrics(views: 0),
                '2026-11-05' => new PageMetrics(views: 10),
                '2026-11-06' => new PageMetrics(views: 5),
            ],
        ));

        self::assertSame('0,29 50,1 100,15', $tiles['views']->sparklineLine);
        self::assertSame('0,30 0,29 50,1 100,15 100,30', $tiles['views']->sparklineArea);
    }

    #[Test]
    public function createDrawsNoSparklineForASingleDay(): void
    {
        $tiles = $this->tilesByKey(new PageStatistics(new PageMetrics(), new PageMetrics(), ['2026-11-06' => new PageMetrics(views: 3)]));

        self::assertSame('', $tiles['views']->sparklineLine);
    }

    /**
     * @return array<string, Tile>
     */
    private function tilesByKey(PageStatistics $statistics): array
    {
        $tiles = [];
        foreach ((new TileFactory())->create($statistics) as $tile) {
            $tiles[$tile->key] = $tile;
        }
        return $tiles;
    }
}
