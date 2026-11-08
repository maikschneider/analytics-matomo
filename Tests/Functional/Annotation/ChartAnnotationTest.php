<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Annotation;

use MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller\ModuleRequestTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ChartAnnotationTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/AnnotationSites' => 'typo3conf/sites',
    ];

    private mixed $request = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Annotations.csv');
        $this->request = $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request;
        parent::tearDown();
    }

    #[Test]
    public function theVisitorsChartMarksAllAnnotationsOfTheSite(): void
    {
        $annotations = $this->chartAnnotations((string)$this->render(1, ['id' => '20', 'period' => '30'])->getBody());

        self::assertContains('Newsletter sent', array_column($annotations, 'note'));
        self::assertContains('TYPO3: https://demo.example.net/ edited by editor', array_column($annotations, 'note'));
    }

    #[Test]
    public function thePageChartMarksOnlyTheChangesOfThePage(): void
    {
        $annotations = $this->chartAnnotations((string)$this->render(1, ['id' => '20', 'period' => '30'], 'page')->getBody());

        self::assertSame(['TYPO3: https://demo.example.net/ edited by editor'], array_column($annotations, 'note'));
    }

    #[Test]
    public function theChartListsTheAnnotationsAsText(): void
    {
        $html = (string)$this->render(1, ['id' => '20', 'period' => '30'])->getBody();

        self::assertMatchesRegularExpression('#<details[^>]*data-chart-annotations[^>]*>\s*<summary>Matomo annotations \(\d+\)</summary>#', $html);
        self::assertStringContainsString('Newsletter sent</li>', $html);
    }

    /**
     * @return list<mixed>
     */
    private function chartAnnotations(string $html): array
    {
        self::assertSame(1, preg_match('/data-evolution-chart="([^"]+)"/', $html, $matches), $html);
        $chart = json_decode(html_entity_decode($matches[1] ?? ''), true);
        self::assertIsArray($chart);
        self::assertIsArray($chart['annotations'] ?? null);
        self::assertTrue(array_is_list($chart['annotations']));
        return $chart['annotations'];
    }
}
