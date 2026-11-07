<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Status;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Matomo\DemoData;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Registry\StatusRegistry;
use TYPO3\CMS\Reports\Status;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ConnectionStatusProviderTest extends FunctionalTestCase
{
    private const NOW = '2026-11-02 14:30:00 UTC';

    protected array $coreExtensionsToLoad = ['dashboard', 'reports'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var array<int, array<string, mixed>> answers by Matomo site ID and method */
    private array $answers = [];
    /** @var array<int, int> HTTP status by Matomo site ID */
    private array $httpStatus = [];
    /** @var list<array<string, string>> every sub-request */
    private array $calls = [];

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable(self::NOW)));
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $recorded = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/ConnectionStatus.json'), true);
        self::assertIsArray($recorded);
        $now = (new \DateTimeImmutable(self::NOW))->getTimestamp();
        // The recorded answers, moved to the URLs of the test sites; both sites are healthy
        foreach ([3 => 'https://example.com', 7 => 'https://shop.example.org'] as $idSite => $url) {
            $this->answers[$idSite] = array_replace($recorded, [
                'SitesManager.getSiteFromId' => ['idsite' => $idSite, 'main_url' => $url, 'timezone' => 'Europe/Berlin'] + $recorded['SitesManager.getSiteFromId'],
                'SitesManager.getSiteUrlsFromId' => $idSite === 7 ? [$url, 'https://shop.example.de'] : [$url],
                'Live.getLastVisitsDetails' => [['lastActionTimestamp' => $now - 3600, 'serverTimestamp' => $now - 3600]],
                'SitesManager.getSitesIdWithAtLeastViewAccess' => [$idSite],
                'Live.getCounters' => [['visits' => 1, 'actions' => 1, 'visitors' => 1, 'visitsConverted' => 0]],
            ]);
        }
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $results = [];
            $idSite = 0;
            foreach ($body['urls'] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->calls[] = $parameters;
                $idSite = (int)$parameters['idSite'];
                $results[] = $this->answers[$idSite][$parameters['method']] ?? ['result' => 'error', 'message' => 'Unexpected method ' . $parameters['method']];
            }
            $status = $this->httpStatus[$idSite] ?? 200;
            return new FulfilledPromise(new Response($status, [], $status === 200 ? (string)json_encode($results) : '{"result":"error","message":"Token secret-token is not valid"}'));
        };
    }

    protected function tearDown(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN');
        parent::tearDown();
    }

    #[Test]
    public function onePerSiteWithTheMatomoVersionOfAHealthyConnection(): void
    {
        $statuses = $this->statuses();

        self::assertSame(['main', 'shop', 'trial', 'unconfigured'], array_keys($statuses));
        self::assertSame(ContextualFeedbackSeverity::OK, $statuses['main']->getSeverity());
        self::assertStringContainsString('6.0.0-rc1', $statuses['main']->getValue());
        self::assertSame(ContextualFeedbackSeverity::OK, $statuses['trial']->getSeverity());
        self::assertSame('Matomo ' . DemoData::VERSION, $statuses['trial']->getValue());
        self::assertSame([], array_filter($this->calls, static fn (array $call): bool => !in_array($call['idSite'], ['3', '7'], true)));
        self::assertSame(ContextualFeedbackSeverity::INFO, $statuses['unconfigured']->getSeverity());
        self::assertSame('Not configured', $statuses['unconfigured']->getValue());
        foreach ($statuses as $status) {
            self::assertStringNotContainsString('secret-token', $status->getMessage() . $status->getValue());
        }
    }

    #[Test]
    public function warnsAboutLanguagesMatomoDropsAndStalledTracking(): void
    {
        $this->answers[7]['SitesManager.getSiteUrlsFromId'] = ['https://shop.example.org'];
        $this->answers[7]['Live.getLastVisitsDetails'] = [['lastActionTimestamp' => (new \DateTimeImmutable(self::NOW))->getTimestamp() - 30 * 3600]];

        $shop = $this->statuses()['shop'];

        self::assertSame(ContextualFeedbackSeverity::WARNING, $shop->getSeverity());
        self::assertStringContainsString('https://shop.example.de/', $shop->getMessage());
        self::assertStringContainsString('30 hours', $shop->getMessage());
    }

    #[Test]
    public function noVisitsAtAllAndTheUnknownUrlOptionOffAreNoProblemOfALanguage(): void
    {
        $this->answers[7]['SitesManager.getSiteFromId']['exclude_unknown_urls'] = 0;
        $this->answers[7]['SitesManager.getSiteUrlsFromId'] = ['https://shop.example.org'];
        $this->answers[7]['Live.getLastVisitsDetails'] = [];

        $shop = $this->statuses()['shop'];

        self::assertSame(ContextualFeedbackSeverity::WARNING, $shop->getSeverity());
        self::assertStringNotContainsString('https://shop.example.de/', $shop->getMessage());
        self::assertStringContainsString('No visit has been tracked yet', $shop->getMessage());
    }

    #[Test]
    public function informsAboutSiteSearchYoungTrackingAndArchivingLag(): void
    {
        $this->answers[3]['SitesManager.getSiteFromId']['sitesearch'] = 0;
        $this->answers[3]['SitesManager.getSiteFromId']['ts_created'] = '2026-09-20 08:00:00';
        $this->answers[3]['Live.getCounters'] = [['visits' => 9, 'actions' => 12, 'visitors' => 8, 'visitsConverted' => 0]];

        $main = $this->statuses()['main'];

        self::assertSame(ContextualFeedbackSeverity::INFO, $main->getSeverity());
        self::assertStringContainsString('Site search is off', $main->getMessage());
        self::assertStringContainsString('20.09.2026', $main->getMessage());
        self::assertStringContainsString('Heuristic', $main->getMessage());
        // Today in the site's time zone: 15:30 in Berlin
        $counters = array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'Live.getCounters' && $call['idSite'] === '3'));
        self::assertSame('930', $counters[0]['lastMinutes']);
    }

    #[Test]
    public function checksThatTheTokenHasViewAccessOnly(): void
    {
        $this->answers[3]['SitesManager.getSitesIdWithAdminAccess'] = [3];
        $this->answers[3]['SitesManager.getSitesIdWithAtLeastViewAccess'] = [3, 7, 12];
        $main = $this->statuses()['main'];
        self::assertSame(ContextualFeedbackSeverity::WARNING, $main->getSeverity());
        self::assertStringContainsString('admin access', $main->getMessage());
        // Site 7 belongs to the shop, but with another token
        self::assertStringContainsString('7, 12', $main->getMessage());

        $this->get(CacheManager::class)->getCache('analytics_matomo')->flush();
        $this->answers[3]['UsersManager.getSitesAccessFromUser'] = [['site' => 3, 'access' => 'admin']];
        $main = $this->statuses()['main'];
        self::assertSame(ContextualFeedbackSeverity::ERROR, $main->getSeverity());
        self::assertStringContainsString('superuser', $main->getMessage());

        // Matomo checks for a superuser first, so any other error means the token passed that check
        $this->get(CacheManager::class)->getCache('analytics_matomo')->flush();
        $this->answers[3]['UsersManager.getSitesAccessFromUser'] = ['result' => 'error', 'message' => "User 'anonymous' doesn't exist."];
        self::assertStringContainsString('superuser', $this->statuses()['main']->getMessage());
    }

    #[Test]
    public function errorsAreShownEscapedAndWithoutTheToken(): void
    {
        $this->httpStatus[3] = 401;
        $this->answers[7]['SitesManager.getSiteFromId'] = ['result' => 'error', 'message' => "You can't access this resource as it requires 'view' access for the website id = <b>7</b>."];

        $statuses = $this->statuses();

        self::assertSame(ContextualFeedbackSeverity::ERROR, $statuses['main']->getSeverity());
        self::assertStringContainsString('Token *** is not valid', $statuses['main']->getMessage());
        self::assertSame(ContextualFeedbackSeverity::ERROR, $statuses['shop']->getSeverity());
        self::assertStringContainsString('website id = &lt;b&gt;7&lt;/b&gt;', $statuses['shop']->getMessage());
    }

    #[Test]
    public function resultsAreCachedInsteadOfAskingMatomoOnEveryRequest(): void
    {
        $this->statuses();
        $requests = count($this->calls);
        self::assertGreaterThan(0, $requests);

        $this->statuses();
        self::assertCount($requests, $this->calls);
    }

    /**
     * @return array<string, Status> by site identifier
     */
    private function statuses(): array
    {
        foreach ($this->get(StatusRegistry::class)->getProviders() as $provider) {
            if ($provider->getLabel() === 'Matomo Analytics') {
                return $provider->getStatus();
            }
        }
        self::fail('No Matomo status provider registered');
    }
}
