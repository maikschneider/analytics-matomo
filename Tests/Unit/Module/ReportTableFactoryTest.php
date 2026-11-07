<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Module;

use MaikSchneider\AnalyticsMatomo\Module\MetricFormatter;
use MaikSchneider\AnalyticsMatomo\Module\ReportTableFactory;
use MaikSchneider\AnalyticsMatomo\Statistics\PageRecordResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportTableFactoryTest extends UnitTestCase
{
    #[Test]
    public function searchesThenSortsRawNumbersAndPagesTwentyFiveRows(): void
    {
        $factory = new ReportTableFactory(new MetricFormatter(), new PageUrlMapper(new Context()), $this->createMock(UriBuilder::class), new PageRecordResolver(new Context()));
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['label' => ($i <= 55 ? 'Device ' : 'Other ') . $i, 'nb_visits' => $i];
        }
        $report = ['metadata' => ['name' => 'Devices', 'metricTypes' => ['nb_visits' => 'number'], 'metricsDocumentation' => ['nb_visits' => 'Visit documentation']], 'columns' => ['label' => 'Device', 'nb_visits' => 'Visits'], 'reportData' => $rows, 'reportMetadata' => [], 'reportTotal' => []];
        $table = $factory->create($report, null, ['q' => 'device', 'sort' => 'nb_visits', 'dir' => 'desc', 'page' => '2'], '/module?report=devices');
        self::assertSame(55, $table['total']);
        self::assertCount(25, $table['rows']);
        self::assertSame('Device 30', $table['rows'][0]['cells'][0]['value']);
        self::assertSame('30', $table['rows'][0]['cells'][1]['value']);
        self::assertSame('Visit documentation', $table['columns'][1]['documentation']);
        self::assertStringContainsString('q=device', $table['nextUrl']);
        self::assertStringContainsString('page=3', $table['nextUrl']);
        self::assertSame(3, $factory->create($report, null, ['page' => '999'], '/module?report=devices')['page']);
    }

    #[Test]
    public function comparisonMatchesLabelsAndKeepsMissingPreviousRowsUnknown(): void
    {
        $factory = new ReportTableFactory(new MetricFormatter(), new PageUrlMapper(new Context()), $this->createMock(UriBuilder::class), new PageRecordResolver(new Context()));
        $report = ['metadata' => ['name' => 'Devices', 'metricTypes' => ['nb_visits' => 'number']], 'columns' => ['label' => 'Device', 'nb_visits' => 'Visits'], 'reportData' => [['label' => 'Desktop', 'nb_visits' => 30], ['label' => 'Tablet', 'nb_visits' => 10]], 'reportMetadata' => [], 'reportTotal' => []];
        $previous = array_replace($report, ['reportData' => [['label' => 'Desktop', 'nb_visits' => 20]]]);
        $table = $factory->create($report, $previous, [], '/module?report=devices');
        self::assertTrue($table['compare']);
        self::assertSame('+50.0 %', $table['rows'][0]['cells'][1]['change']);
        self::assertSame('–', $table['rows'][1]['cells'][1]['change']);
    }
}
