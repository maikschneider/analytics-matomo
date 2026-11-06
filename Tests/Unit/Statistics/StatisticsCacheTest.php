<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Statistics;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class StatisticsCacheTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private int $calls = 0;

    #[Test]
    public function buildsOncePerKey(): void
    {
        $subject = new StatisticsCache(new VariableFrontend('test', new TransientMemoryBackend()));
        $build = fn (): array => ['call' => ++$this->calls];

        $first = $subject->remember(['a', 1], $build);

        self::assertSame(['call' => 1], $first);
        self::assertSame($first, $subject->remember(['a', 1], $build));
        self::assertSame(['call' => 2], $subject->remember(['a', 2], $build));
    }

    #[Test]
    public function doesNotCacheFailures(): void
    {
        $subject = new StatisticsCache(new VariableFrontend('test', new TransientMemoryBackend()));
        $calls = 0;
        $build = static function () use (&$calls): never {
            $calls++;
            throw new MatomoApiException('down', 1);
        };

        for ($i = 0; $i < 2; $i++) {
            try {
                $subject->remember(['a'], $build);
            } catch (MatomoApiException) {
            }
        }

        self::assertSame(2, $calls);
    }
}
