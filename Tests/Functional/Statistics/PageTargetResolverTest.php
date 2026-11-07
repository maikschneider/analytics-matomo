<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics;

use MaikSchneider\AnalyticsMatomo\Statistics\PageTargetResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageTargetResolverTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'dashboard',
    ];

    protected array $testExtensionsToLoad = [
        'maikschneider/analytics-matomo',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN');
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function pages(): iterable
    {
        yield 'visible page' => [2, 0, 'https://example.com/about'];
        yield 'visible translation' => [2, 1, 'https://example.com/de/ueber-uns'];
        yield 'hidden page' => [5, 0, 'https://example.com/summer-sale'];
        yield 'hidden translation' => [5, 1, 'https://example.com/de/sommerschlussverkauf'];
    }

    #[Test]
    #[DataProvider('pages')]
    public function resolvesTheUrlOfThePageItself(int $pageId, int $languageId, string $expectedUrl): void
    {
        $target = $this->get(PageTargetResolver::class)->resolve($pageId, $languageId);

        self::assertNotNull($target);
        self::assertSame($expectedUrl, $target->pageUrl);
    }
}
