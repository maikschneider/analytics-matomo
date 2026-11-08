<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Monitoring;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Monitoring\AnomalyService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AnomalyServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    #[Test]
    public function dailyCommandWorksWithoutABackendUserAndSeedsTheDemoAlert(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        unset($GLOBALS['BE_USER']);
        $command = new \Symfony\Component\Console\Tester\CommandTester($this->get(\MaikSchneider\AnalyticsMatomo\Command\CheckAnomaliesCommand::class));
        self::assertSame(0, $command->execute(['--site' => 'trial']));
        self::assertStringContainsString('trial', $command->getDisplay());
        $configuration = $this->get(\MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver::class)->resolve($this->get(\TYPO3\CMS\Core\Site\SiteFinder::class)->getSiteByIdentifier('trial'));
        self::assertNotNull($configuration);
        $alerts = $this->get(AnomalyService::class)->getCached($configuration);
        self::assertNotEmpty($alerts);
    }

    #[Test]
    public function refreshUsesOnlyClosedDaysAndTheUiReadsWithoutHttp(): void
    {
        $calls = [];
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/VisitsPerDay.json'), true, flags: JSON_THROW_ON_ERROR);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = static function (callable $handler) use (&$calls, $fixture): \Closure {
            return static function (RequestInterface $request) use (&$calls, $fixture): FulfilledPromise {
                parse_str((string)$request->getBody(), $body);
                parse_str($body['urls'][0], $parameters);
                $calls[] = $parameters;
                return new FulfilledPromise(new Response(200, [], (string)json_encode($fixture)));
            };
        };
        $configuration = new MatomoConfiguration('https://example.com/matomo/', 3, 'view-token');
        $service = new AnomalyService($this->get(\MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient::class), $this->get(\MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache::class), $this->get(\TYPO3\CMS\Core\Context\Context::class));
        self::assertSame([], $service->getCached($configuration));
        $service->refresh($configuration);
        self::assertSame([], $service->getCached($configuration));
        self::assertCount(1, $calls);
        self::assertSame('previous29', $calls[0]['date']);
        self::assertSame('day', $calls[0]['period']);
        self::assertSame('VisitsSummary.get', $calls[0]['method']);
        self::assertSame([], $service->getCached(new MatomoConfiguration('https://example.com/matomo/', 7, 'other-token')));
        self::assertCount(1, $calls);
    }
}
