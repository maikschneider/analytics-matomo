<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use MaikSchneider\AnalyticsMatomo\Module\ReportCatalog;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Without EXT:redirects, the 404 pages report is not offered at all
 */
final class NotFoundWithoutRedirectsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];

    #[Test]
    public function behaviourHasNo404PagesWithoutRedirectsExtension(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');

        $pages = $this->get(ReportCatalog::class)->getPages('behaviour', $this->setUpBackendUser(1));

        self::assertArrayHasKey('pageTitles', $pages);
        self::assertArrayNotHasKey('notFound', $pages);
    }
}
