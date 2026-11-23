<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Command;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Command\ImportPageViewsCommand;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The site "broken" has a router that fails on every page.
 */
final class ImportPageViewsCommandFailureTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/BrokenImportSites' => 'typo3conf/sites',
    ];

    /** @var list<string> "idSite date" of every request, in order */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-23 14:30:00')));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            parse_str((string)$request->getBody(), $body);
            $results = [];
            foreach (is_array($body['urls'] ?? null) ? $body['urls'] : [] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->requested[] = $parameters['idSite'] . ' ' . $parameters['date'];
                $results[] = [
                    ['label' => '/about', 'url' => 'https://example.com/about', 'nb_hits' => 3],
                    ['label' => '/products/', 'url' => 'https://shop.example.org/products/', 'nb_hits' => 1],
                ];
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    #[Test]
    public function aFailingConnectionFailsTheRunAfterTheOtherConnections(): void
    {
        // A day imported before the router broke
        $broken = $this->get(ConnectionRepository::class)->getOrCreate(new MatomoConfiguration('https://matomo.example.com', 3, ''));
        $urlDay = UrlDayRows::fromReport([['label' => '/about', 'url' => 'https://example.com/about', 'nb_hits' => 3]]);
        $repository = new UrlDayRepository($this->getConnectionPool());
        $uids = $repository->addUrls($broken, 20261122, $urlDay);
        $this->getConnectionPool()->getConnectionForTable(UrlDayRepository::TABLE)->transactional(function (Connection $database) use ($repository, $broken, $urlDay, $uids): void {
            $repository->replaceDayFacts($database, $broken, 20261122, $urlDay, $uids);
            (new ImportRepository($this->getConnectionPool()))->markImported($database, $broken, 20261122, (int)strtotime('2026-11-23 03:00:00'), $urlDay);
        });
        $command = new CommandTester($this->get(ImportPageViewsCommand::class));

        self::assertSame(Command::FAILURE, $command->execute(['--retention-days' => '2']));

        self::assertSame(['3 2026-11-23', '7 2026-11-23', '7 2026-11-22'], $this->requested);
        $display = $command->getDisplay();
        self::assertStringContainsString('broken: 0 days imported, 1 failed', $display);
        // The re-map of the site fails outside of any day
        self::assertStringContainsString('broken: failed: No enhancer found for DoesNotExist', $display);
        self::assertStringContainsString('shop: 2 days imported', $display);
        $shop = (int)$this->get(ConnectionRepository::class)->find(new MatomoConfiguration('https://matomo.example.com', 7, ''));
        self::assertSame(2, (new UrlDayRepository($this->getConnectionPool()))->sumByPage('shop', $shop, 20261122, 20261123)[11][0]['nb_hits'] ?? null);

        // The lock is released
        $capabilities = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        $locker = $this->get(LockFactory::class)->createLocker(ImportPageViewsCommand::lockKey($broken), $capabilities);
        self::assertTrue($locker->acquire($capabilities));
        $locker->release();
    }
}
