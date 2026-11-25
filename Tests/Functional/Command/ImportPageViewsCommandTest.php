<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Command;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Command\ImportPageViewsCommand;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlPageMapper;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ImportPageViewsCommandTest extends FunctionalTestCase
{
    private const NOW = '2026-11-23 14:30:00';

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/ImportSites' => 'typo3conf/sites',
    ];

    /** @var list<string> "idSite date" of every request, in order */
    private array $requested = [];

    /** @var list<string> token of every HTTP request */
    private array $tokens = [];

    /** @var array<string, string> error message by "idSite date" */
    private array $failing = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable(self::NOW)));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            parse_str((string)$request->getBody(), $body);
            $this->tokens[] = is_string($body['token_auth'] ?? null) ? $body['token_auth'] : '';
            $results = [];
            foreach (is_array($body['urls'] ?? null) ? $body['urls'] : [] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                if ($parameters['method'] === 'SitesManager.getSiteFromId') {
                    $results[] = ['idsite' => (int)$parameters['idSite'], 'timezone' => 'UTC'];
                    continue;
                }
                $key = $parameters['idSite'] . ' ' . $parameters['date'];
                $this->requested[] = $key;
                $results[] = isset($this->failing[$key]) ? ['result' => 'error', 'message' => $this->failing[$key]] : [
                    ['label' => '/about', 'url' => 'https://example.com/about', 'nb_hits' => 3],
                    ['label' => '/about', 'url' => 'https://blog.example.com/about', 'nb_hits' => 2],
                    ['label' => '/products/', 'url' => 'https://shop.example.org/products/', 'nb_hits' => 1],
                ];
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    #[Test]
    public function sitesOnOneMatomoSiteShareAConnectionAndEachGetsItsMapping(): void
    {
        $command = $this->command();

        self::assertSame(Command::SUCCESS, $command->execute(['--retention-days' => '2']), $command->getDisplay());

        self::assertSame(['3 2026-11-23', '3 2026-11-22', '7 2026-11-23', '7 2026-11-22'], $this->requested);
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_connection')->count('*', 'tx_analyticsmatomo_connection', []));
        // The token of the first site of the connection (sites are sorted by identifier)
        self::assertSame('blog-token', $this->tokens[0]);
        $connection = $this->connection(3);
        $repository = new UrlDayRepository($this->getConnectionPool());
        self::assertSame(6, $repository->sumByPage('main', $connection, 20261122, 20261123)[2][0]['nb_hits'] ?? null);
        self::assertSame(4, $repository->sumByPage('blog', $connection, 20261122, 20261123)[31][0]['nb_hits'] ?? null);
        self::assertSame(2, $repository->sumByPage('shop', $this->connection(7), 20261122, 20261123)[11][0]['nb_hits'] ?? null);
        self::assertSame(3, $this->getConnectionPool()->getConnectionForTable(UrlPageMapper::TABLE)->count('*', UrlPageMapper::TABLE, ['site' => 'blog']));
        $display = $command->getDisplay();
        self::assertStringContainsString('blog, main: 2 days imported', $display);
        self::assertStringContainsString('shop: 2 days imported', $display);
        self::assertStringNotContainsString('demo', $display);
    }

    #[Test]
    public function theNewestDaysOfEveryConnectionComeBeforeAnyBackfill(): void
    {
        $command = $this->command();

        $command->execute(['--max-requests' => '5', '--retention-days' => '10']);

        self::assertSame(['3 2026-11-23', '3 2026-11-22', '7 2026-11-23', '7 2026-11-22', '3 2026-11-21'], $this->requested);
        self::assertStringContainsString('blog, main: 3 days imported, 7 days left', $command->getDisplay());
        self::assertStringContainsString('shop: 2 days imported, 8 days left', $command->getDisplay());

        $this->requested = [];
        $this->command()->execute(['--max-requests' => '2', '--retention-days' => '10']);

        // Today is imported again, yesterday is final: no backfill before the other connection's today
        self::assertSame(['3 2026-11-23', '7 2026-11-23'], $this->requested);
    }

    #[Test]
    public function aRangeIsImportedAgain(): void
    {
        $this->command()->execute(['--retention-days' => '2']);
        $this->requested = [];

        $command = $this->command();
        self::assertSame(Command::SUCCESS, $command->execute(['--site' => 'shop', '--from' => '2026-11-20', '--to' => '2026-11-22', '--retention-days' => '10']), $command->getDisplay());

        self::assertSame(['7 2026-11-22', '7 2026-11-21', '7 2026-11-20'], $this->requested);

        // Without --to up to today, without --from the day of --to only
        $this->requested = [];
        $this->command()->execute(['--site' => 'shop', '--from' => '2026-11-22', '--retention-days' => '10']);
        self::assertSame(['7 2026-11-23', '7 2026-11-22'], $this->requested);
        $this->requested = [];
        $this->command()->execute(['--site' => 'shop', '--to' => '2026-11-21', '--retention-days' => '10']);
        self::assertSame(['7 2026-11-21'], $this->requested);
    }

    #[Test]
    public function aConnectionWithAnImportRunningIsSkipped(): void
    {
        $locker = $this->get(LockFactory::class)->createLocker(
            ImportPageViewsCommand::lockKey($this->getOrCreateConnection(3)),
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
        );
        self::assertTrue($locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK));
        try {
            $command = $this->command();
            self::assertSame(Command::SUCCESS, $command->execute(['--retention-days' => '1']));
        } finally {
            $locker->release();
        }

        self::assertSame(['7 2026-11-23'], $this->requested);
        self::assertStringContainsString('blog, main: skipped (running)', $command->getDisplay());

        // Released after the run
        $this->requested = [];
        $this->command()->execute(['--retention-days' => '1']);
        self::assertSame(['3 2026-11-23', '7 2026-11-23'], $this->requested);
    }

    #[Test]
    public function aFailedDayFailsTheRunAfterTheOtherDays(): void
    {
        $this->failing['7 2026-11-22'] = 'Archiving timed out';
        $command = $this->command();

        self::assertSame(Command::FAILURE, $command->execute(['--retention-days' => '3']));

        self::assertSame(['3 2026-11-23', '3 2026-11-22', '7 2026-11-23', '7 2026-11-22', '3 2026-11-21', '7 2026-11-21'], $this->requested);
        self::assertStringContainsString('shop: 2 days imported, 1 failed', $command->getDisplay());
        self::assertStringContainsString('2026-11-22: Matomo API error: Archiving timed out', $command->getDisplay());
    }

    #[Test]
    public function anUnknownSiteIsRejected(): void
    {
        $command = $this->command();

        self::assertSame(Command::INVALID, $command->execute(['--site' => 'nope']));

        self::assertStringContainsString('Unknown site nope', $command->getDisplay());
        self::assertSame(0, $this->connectionCount());
        self::assertSame([], $this->requested);
    }

    #[Test]
    public function aSiteOptionCreatesTheConnectionOfThatSiteOnly(): void
    {
        $command = $this->command();

        self::assertSame(Command::SUCCESS, $command->execute(['--site' => 'shop', '--retention-days' => '1']), $command->getDisplay());

        self::assertSame(['7 2026-11-23'], $this->requested);
        self::assertSame(1, $this->connectionCount());
        self::assertStringNotContainsString('main', $command->getDisplay());
    }

    #[Test]
    public function theSiteWhoseConfigurationIsUsedIsShownButNotItsToken(): void
    {
        $command = $this->command();

        $command->execute(['--retention-days' => '1']);

        self::assertStringContainsString('blog, main: Matomo site 3 of https://matomo.example.com, configuration of site blog', $command->getDisplay());
        self::assertStringContainsString('shop: Matomo site 7 of https://matomo.example.com, configuration of site shop', $command->getDisplay());
        self::assertStringNotContainsString('-token', $command->getDisplay());
    }

    #[Test]
    public function noRequestIsSentWithoutRequestBudget(): void
    {
        $command = $this->command();

        self::assertSame(Command::SUCCESS, $command->execute(['--max-requests' => '0', '--retention-days' => '3']), $command->getDisplay());

        self::assertSame([], $this->requested);
        self::assertStringContainsString('shop: 0 days imported, 3 days left', $command->getDisplay());
    }

    #[Test]
    public function toWithoutFromImportsThatDayOnly(): void
    {
        $command = $this->command();

        self::assertSame(Command::SUCCESS, $command->execute(['--to' => '2026-11-20', '--retention-days' => '10']), $command->getDisplay());

        self::assertSame(['3 2026-11-20', '7 2026-11-20'], $this->requested);
    }

    #[Test]
    public function daysBeyondTheRetentionArePurged(): void
    {
        $this->command()->execute(['--retention-days' => '3']);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-25 14:30:00')));

        $this->command()->execute(['--max-requests' => '0', '--retention-days' => '3']);

        self::assertSame([20261123], $this->importedDays($this->connection(7)));
    }

    #[Test]
    public function theRemapLimitIsSharedByAllSites(): void
    {
        $command = $this->command();

        self::assertSame(Command::SUCCESS, $command->execute(['--retention-days' => '1', '--max-remap' => '4']), $command->getDisplay());

        // Each connection has three URLs: blog is re-mapped, main once, shop is due next run
        self::assertSame(['', 0], $this->remapState('blog'));
        self::assertSame($this->firstUrl($this->connection(3)), $this->remapState('main')[1]);
        self::assertNotSame('', $this->remapState('main')[0]);
        self::assertSame(0, $this->remapState('shop')[1]);
        self::assertNotSame('', $this->remapState('shop')[0]);
        self::assertSame(Command::INVALID, $this->command()->execute(['--max-remap' => '-1']));
    }

    #[Test]
    public function invalidOptionsAreRejected(): void
    {
        $command = $this->command();

        self::assertSame(Command::INVALID, $command->execute(['--from' => '23.11.2026']));
        self::assertSame(Command::INVALID, $this->command()->execute(['--from' => '2026-11-22', '--to' => '2026-11-20']));
        self::assertSame(Command::INVALID, $this->command()->execute(['--retention-days' => '0']));
        self::assertSame([], $this->requested);
    }

    private function connectionCount(): int
    {
        return $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_connection')->count('*', 'tx_analyticsmatomo_connection', []);
    }

    /**
     * @return list<int>
     */
    private function importedDays(int $connection): array
    {
        return array_map(intval(...), $this->getConnectionPool()->getConnectionForTable('tx_analyticsmatomo_import')
            ->select(['day'], 'tx_analyticsmatomo_import', ['connection' => $connection], [], ['day' => 'ASC'])
            ->fetchFirstColumn());
    }

    /**
     * @return array{string, int} pending hash and cursor
     */
    private function remapState(string $site): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(UrlPageMapper::SITE_STATE_TABLE)
            ->select(['pending_hash', 'remap_cursor'], UrlPageMapper::SITE_STATE_TABLE, ['site' => $site])->fetchAssociative();
        self::assertIsArray($row);
        return [trim((string)$row['pending_hash']), (int)$row['remap_cursor']];
    }

    private function firstUrl(int $connection): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable(UrlDayRepository::URL_TABLE)
            ->select(['uid'], UrlDayRepository::URL_TABLE, ['connection' => $connection], [], ['uid' => 'ASC'], 1)->fetchOne();
    }

    private function connection(int $idSite): int
    {
        return (int)$this->get(ConnectionRepository::class)->find(new MatomoConfiguration('https://matomo.example.com', $idSite, ''));
    }

    private function getOrCreateConnection(int $idSite): int
    {
        return $this->get(ConnectionRepository::class)->getOrCreate(new MatomoConfiguration('https://matomo.example.com', $idSite, ''));
    }

    private function command(): CommandTester
    {
        return new CommandTester($this->get(ImportPageViewsCommand::class));
    }
}
