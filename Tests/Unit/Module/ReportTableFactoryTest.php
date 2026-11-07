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

    #[Test]
    public function catalogOptionsSetTitleRawColumnsMinimumAndDefaultSort(): void
    {
        $factory = new ReportTableFactory(new MetricFormatter(), new PageUrlMapper(new Context()), $this->createMock(UriBuilder::class), new PageRecordResolver(new Context()));
        $rows = [
            ['label' => '/fast', 'nb_hits' => 50, 'avg_page_load_time' => 0.5, 'nb_hits_with_time_network' => 40],
            ['label' => '/rare', 'nb_hits' => 2, 'avg_page_load_time' => 9.0, 'nb_hits_with_time_network' => 2],
            ['label' => '/slow', 'nb_hits' => 20, 'avg_page_load_time' => 3.2, 'nb_hits_with_time_network' => 5],
        ];
        $report = ['metadata' => ['name' => 'Page URLs', 'metricTypes' => ['nb_hits' => 'number']], 'columns' => ['label' => 'Page URL', 'nb_hits' => 'Pageviews', 'bounce_rate' => 'Bounce rate'], 'reportData' => $rows, 'reportMetadata' => [], 'reportTotal' => []];
        $options = [
            'title' => 'Slowest pages',
            'columns' => ['nb_hits' => ['Page views', 'number'], 'avg_page_load_time' => ['Page load time', 'duration_s'], 'nb_hits_with_time_network' => ['Measured page views', 'number']],
            'minimum' => ['nb_hits_with_time_network', 5],
            'sort' => 'avg_page_load_time',
        ];
        $table = $factory->create($report, null, [], '/module?report=performance', options: $options);

        self::assertSame('Slowest pages', $table['title']);
        self::assertSame(['Page URL', 'Page views', 'Page load time', 'Measured page views'], array_column($table['columns'], 'label'));
        self::assertSame([['/slow', '20', '3.20 s', '5'], ['/fast', '50', '0.50 s', '40']], array_map(static fn (array $row): array => array_column($row['cells'], 'value'), $table['rows']));
        self::assertSame(2, $table['total']);
        $byHits = $factory->create($report, null, ['sort' => 'nb_hits', 'dir' => 'asc'], '/module', options: $options);
        self::assertSame('/slow', $byHits['rows'][0]['cells'][0]['value']);
    }

    #[Test]
    public function reportsWithoutDimensionListOneRowPerMetricWithChange(): void
    {
        $factory = new ReportTableFactory(new MetricFormatter(), new PageUrlMapper(new Context()), $this->createMock(UriBuilder::class), new PageRecordResolver(new Context()));
        $report = ['metadata' => ['name' => 'Returning Visits', 'metricTypes' => ['nb_visits_new' => 'number', 'bounce_rate_new' => 'percent']], 'columns' => ['nb_visits_new' => 'New Visits', 'bounce_rate_new' => 'Bounce Rate'], 'reportData' => [['nb_visits_new' => 30, 'bounce_rate_new' => 0.5]], 'reportMetadata' => [], 'reportTotal' => []];
        $previous = array_replace($report, ['reportData' => [['nb_visits_new' => 20]]]);
        $table = $factory->create($report, $previous, [], '/module?report=engagement');

        self::assertTrue($table['metricList']);
        self::assertSame('Returning Visits', $table['title']);
        self::assertSame([['New Visits', '30', '+50.0 %'], ['Bounce Rate', '50.0 %', '–']], array_map(static fn (array $row): array => [$row['cells'][0]['value'], $row['cells'][1]['value'], $row['cells'][1]['change']], $table['rows']));
        self::assertSame([], $factory->create(array_replace($report, ['reportData' => []]), null, [], '/module')['rows']);
    }
}
