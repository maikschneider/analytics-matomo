<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Controller\StatisticsBarController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class StatisticsBarControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'maikschneider/analytics-matomo',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /**
     * @var list<RequestInterface>
     */
    private array $sentRequests = [];

    private string $matomoResponse = '';

    private int $matomoStatus = 200;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');

        $this->matomoResponse = (string)json_encode([
            [['nb_hits' => 1234, 'bounce_rate' => 0.84, 'avg_time_on_page' => 95, 'exit_rate' => 0.5]],
            [['nb_hits' => 1000, 'bounce_rate' => 0.8, 'avg_time_on_page' => 100, 'exit_rate' => 0.5]],
            [],
        ]);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $this->sentRequests[] = $request;
            return new FulfilledPromise(new Response($this->matomoStatus, [], $this->matomoResponse));
        };
    }

    protected function tearDown(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN');
        parent::tearDown();
    }

    #[Test]
    public function rendersStatisticsOfThePageUrl(): void
    {
        $response = $this->callController(1, ['pageId' => '2', 'days' => '14']);

        self::assertSame(200, $response->getStatusCode());
        $html = $this->html($response);
        self::assertStringContainsString("1\u{202F}234", $html);
        self::assertStringContainsString('84.0 %', $html);
        self::assertStringContainsString('1:35', $html);
        self::assertStringContainsString('Statistics for https://example.com/about', $html);
        self::assertStringContainsString('<option value="14" selected>', $html);

        self::assertCount(1, $this->sentRequests);
        self::assertSame('https://matomo.example.com/index.php', (string)$this->sentRequests[0]->getUri());
        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertSame('API.getBulkRequest', $body['method']);
        self::assertSame('secret-token', $body['token_auth']);
        self::assertIsArray($body['urls']);
        parse_str($body['urls'][0], $firstCall);
        self::assertSame('https://example.com/about', $firstCall['pageUrl']);
        self::assertSame('3', $firstCall['idSite']);
    }

    #[Test]
    public function usesUrlOfTheSelectedLanguage(): void
    {
        $this->html($this->callController(1, ['pageId' => '2', 'languageId' => '1']));

        parse_str((string)$this->sentRequests[0]->getBody(), $body);
        self::assertIsArray($body['urls']);
        parse_str($body['urls'][0], $firstCall);
        self::assertSame('https://example.com/de/ueber-uns', $firstCall['pageUrl']);
    }

    #[Test]
    public function servesSecondRequestFromCache(): void
    {
        $this->callController(1, ['pageId' => '2']);
        $this->callController(1, ['pageId' => '2']);

        self::assertCount(1, $this->sentRequests);
    }

    #[Test]
    public function showsErrorDetailsToAdmins(): void
    {
        $this->matomoStatus = 401;
        $this->matomoResponse = '{"result":"error","message":"token_auth is not valid"}';

        $html = $this->html($this->callController(1, ['pageId' => '2']));

        self::assertStringContainsString('The statistics could not be loaded from Matomo.', $html);
        self::assertStringContainsString('token_auth is not valid', $html);
    }

    #[Test]
    public function returnsNotFoundForPagesWithoutUrl(): void
    {
        self::assertSame(404, $this->callController(1, ['pageId' => '4'])->getStatusCode());
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function returnsNotFoundForPagesTheUserMayNotSee(): void
    {
        self::assertSame(404, $this->callController(2, ['pageId' => '2'])->getStatusCode());
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function returnsNotFoundForLanguagesTheUserMayNotEdit(): void
    {
        self::assertSame(404, $this->callController(3, ['pageId' => '2', 'languageId' => '1'])->getStatusCode());
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function returnsNotFoundWithoutPageStatisticsPermission(): void
    {
        self::assertSame(404, $this->callController(4, ['pageId' => '2'])->getStatusCode());
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function hidesErrorDetailsFromEditors(): void
    {
        $this->matomoStatus = 401;
        $this->matomoResponse = '{"result":"error","message":"token_auth is not valid"}';

        $response = $this->callController(3, ['pageId' => '2']);

        self::assertSame(200, $response->getStatusCode());
        $html = $this->html($response);
        self::assertStringContainsString('The statistics could not be loaded from Matomo.', $html);
        self::assertStringNotContainsString('token_auth is not valid', $html);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function callController(int $backendUserId, array $queryParams): ResponseInterface
    {
        $backendUser = $this->setUpBackendUser($backendUserId);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $request = (new ServerRequest('https://example.com/typo3/ajax/analytics-matomo/statistics-bar'))
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $this->get(StatisticsBarController::class)($request);
    }

    private function html(ResponseInterface $response): string
    {
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsString($data['html']);
        return $data['html'];
    }
}
