<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit;

use Doctrine\DBAL\Result;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewStore;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlPageMapper;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A page view store without any stored Matomo site, for services that read
 * Matomo when the store does not answer.
 */
trait EmptyPageViewStoreTrait
{
    private function createEmptyPageViewStore(MatomoClient $client, StatisticsCache $cache): PageViewStore
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);
        $connection = $this->createMock(Connection::class);
        $connection->method('select')->willReturn($result);
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);
        $context = new Context();
        return new PageViewStore(
            new ConnectionRepository($connectionPool),
            new ImportRepository($connectionPool),
            new UrlDayRepository($connectionPool),
            new UrlPageMapper(new PageUrlMapper($context), $connectionPool),
            $client,
            $cache,
            $context,
            new NullLogger(),
        );
    }
}
