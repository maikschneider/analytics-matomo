<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Matomo;

use MaikSchneider\AnalyticsMatomo\Matomo\SiteTimezone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteTimezoneTest extends UnitTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function timezones(): array
    {
        return [
            'name' => ['America/New_York', 'America/New_York'],
            'UTC' => ['UTC', 'UTC'],
            'whole hours' => ['UTC+2', '+02:00'],
            'negative half hour' => ['UTC-9.5', '-09:30'],
            'three quarters' => ['UTC+5.75', '+05:45'],
        ];
    }

    #[Test]
    #[DataProvider('timezones')]
    public function readsNamesAndMatomosManualOffsets(string $timezone, string $expected): void
    {
        self::assertSame($expected, SiteTimezone::fromSite(['idsite' => 3, 'timezone' => $timezone])?->getName());
    }

    #[Test]
    public function nullWithoutATimezonePhpUnderstands(): void
    {
        self::assertNull(SiteTimezone::fromSite(['timezone' => 'Mars/Olympus']));
        self::assertNull(SiteTimezone::fromSite(['result' => 'error', 'message' => 'No access']));
        self::assertNull(SiteTimezone::fromSite(null));
    }
}
