<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class StatisticsCacheTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];

    #[Test]
    public function flushingTheSystemCachesFlushesTheStatistics(): void
    {
        $cacheManager = $this->get(CacheManager::class);
        $cacheManager->getCache('analytics_matomo')->set('entry', 'cached');

        $cacheManager->flushCachesInGroup('system');

        self::assertFalse($cacheManager->getCache('analytics_matomo')->has('entry'));
    }
}
