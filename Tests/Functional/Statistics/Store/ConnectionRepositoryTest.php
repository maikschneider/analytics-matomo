<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ConnectionRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];

    #[Test]
    public function findReturnsNullForAnUnknownMatomoSite(): void
    {
        self::assertNull($this->repository()->find(new MatomoConfiguration('https://matomo.example.org', 3, 'token')));
    }

    #[Test]
    public function getOrCreateStoresTheMatomoSiteOnce(): void
    {
        $repository = $this->repository();
        $configuration = new MatomoConfiguration('https://matomo.example.org', 3, 'token');

        $uid = $repository->getOrCreate($configuration);

        self::assertGreaterThan(0, $uid);
        self::assertSame($uid, $repository->getOrCreate($configuration));
        self::assertSame($uid, $repository->find($configuration));
        $rows = $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_connection')->select(['*'], 'tx_analyticsmatomo_connection')->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', "https://matomo.example.org\n3"), $rows[0]['identifier']);
        self::assertSame('https://matomo.example.org', $rows[0]['url']);
        self::assertSame(3, (int)$rows[0]['id_site']);
    }

    #[Test]
    public function tokenAndSegmentDoNotMakeAnotherConnection(): void
    {
        $repository = $this->repository();

        $uid = $repository->getOrCreate(new MatomoConfiguration('https://matomo.example.org', 3, 'token-a'));

        self::assertSame($uid, $repository->getOrCreate(new MatomoConfiguration('https://matomo.example.org', 3, 'token-b')));
        self::assertSame($uid, $repository->find(new MatomoConfiguration('https://matomo.example.org', 3, 'token-a', segment: 'deviceType==Smartphone')));
    }

    #[Test]
    public function otherSiteIdOrUrlMakesAnotherConnection(): void
    {
        $repository = $this->repository();

        $uid = $repository->getOrCreate(new MatomoConfiguration('https://matomo.example.org', 3, 'token'));
        $otherSite = $repository->getOrCreate(new MatomoConfiguration('https://matomo.example.org', 4, 'token'));
        $otherUrl = $repository->getOrCreate(new MatomoConfiguration('https://analytics.example.org', 3, 'token'));

        self::assertCount(3, array_unique([$uid, $otherSite, $otherUrl]));
    }

    #[Test]
    public function createReturnsTheConnectionAnotherProcessCreatedMeanwhile(): void
    {
        $repository = $this->repository();
        $configuration = new MatomoConfiguration('https://matomo.example.org', 3, 'token');
        // Another import inserted the row between find() and the insert of getOrCreate()
        $uid = $repository->getOrCreate($configuration);

        self::assertSame($uid, $repository->create($configuration));
        self::assertSame(1, $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_connection')->count('*', 'tx_analyticsmatomo_connection', []));
    }

    private function repository(): ConnectionRepository
    {
        return new ConnectionRepository($this->get(ConnectionPool::class));
    }
}
