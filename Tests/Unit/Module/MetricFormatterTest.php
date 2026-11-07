<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Module;

use MaikSchneider\AnalyticsMatomo\Module\MetricFormatter;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MetricFormatterTest extends UnitTestCase
{
    #[Test]
    public function formatsRawMetricTypesWithoutLosingFractions(): void
    {
        $formatter = new MetricFormatter();
        self::assertSame('1,234', $formatter->format(1234, 'number'));
        self::assertSame('2.75', $formatter->format(2.75, 'number'));
        self::assertSame('58.0 %', $formatter->format(0.58, 'percent'));
        self::assertSame('1:01:05', $formatter->format(3665, 'duration_s'));
        self::assertSame('1.25', $formatter->format(1.25, 'money'));
        self::assertSame('–', $formatter->format(null, 'number'));
        self::assertSame('–', $formatter->format(['unexpected'], 'number'));
        self::assertSame('3.25', $formatter->format(3.25, 'unknown'));
    }

    #[Test]
    public function subMinuteFractionsOfSecondsKeepTheirPrecision(): void
    {
        $formatter = new MetricFormatter();
        self::assertSame('1.56 s', $formatter->format(1.56, 'duration_s'));
        self::assertSame('0.03 s', $formatter->format(0.026, 'duration_s'));
        self::assertSame('0:00:14', $formatter->format(14, 'duration_s'));
        self::assertSame('0:01:05', $formatter->format(65.4, 'duration_s'));
    }
}
