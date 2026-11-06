<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit;

use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * The cache backends have different constructors in TYPO3 13 and 14, so the
 * tests keep their cache entries in an array instead.
 */
trait InMemoryCacheTrait
{
    private function createStatisticsCache(): StatisticsCache
    {
        /** @var array<string, mixed> $entries */
        $entries = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $identifier) use (&$entries): mixed {
            return $entries[$identifier] ?? false;
        });
        $cache->method('set')->willReturnCallback(static function (string $identifier, mixed $data) use (&$entries): void {
            $entries[$identifier] = $data;
        });
        return new StatisticsCache($cache);
    }
}
