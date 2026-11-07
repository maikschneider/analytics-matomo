<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Statistics\PageViewsService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageViewsServiceTest extends UnitTestCase
{
    private const VIEWS = [
        'current' => [2 => [0 => 4, 1 => 6], 7 => [0 => 10]],
        'previous' => [2 => [0 => 3]],
        'currentTruncated' => false,
        'previousTruncated' => false,
        'total' => 20,
    ];

    #[Test]
    public function viewsOfAPageAreTheSumOfItsLanguages(): void
    {
        self::assertSame(['current' => 10, 'previous' => 3], PageViewsService::ofPage(self::VIEWS, 2, [0, 1]));
        self::assertSame(['current' => 4, 'previous' => 3], PageViewsService::ofPage(self::VIEWS, 2, [0]));
    }

    #[Test]
    public function aPageMissingFromACompleteReportHadNoViews(): void
    {
        self::assertSame(['current' => 0, 'previous' => 0], PageViewsService::ofPage(self::VIEWS, 9, [0, 1]));
    }

    #[Test]
    public function aPartlyMissingTranslationInATruncatedReportIsUnknown(): void
    {
        $views = ['currentTruncated' => true] + self::VIEWS;

        self::assertSame(['current' => null, 'previous' => 0], PageViewsService::ofPage($views, 7, [0, 1]));
    }

    #[Test]
    public function aPageMissingFromATruncatedReportIsUnknown(): void
    {
        $views = ['currentTruncated' => true] + self::VIEWS;

        self::assertSame(['current' => null, 'previous' => 0], PageViewsService::ofPage($views, 9, [0]));
        self::assertSame(['current' => 10, 'previous' => 0], PageViewsService::ofPage($views, 7, [0]));
        // Only the German views are missing
        self::assertSame(['current' => null, 'previous' => 0], PageViewsService::ofPage($views, 7, [1]));
    }
}
