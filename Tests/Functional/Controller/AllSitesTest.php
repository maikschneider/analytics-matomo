<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AllSitesTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<list<array<string, string>>> sub-requests of every HTTP request */
    private array $bulks = [];
    private bool $shopFails = false;
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_visitors',
        ], ['uid' => 1]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $segments = $this->answerWithoutSegments($request);
            if ($segments !== null) {
                return $segments;
            }
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $calls = [];
            $results = [];
            foreach ($body['urls'] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $calls[] = $parameters + ['token_auth' => is_string($body['token_auth'] ?? null) ? $body['token_auth'] : ''];
                $results[] = $this->answer($parameters);
            }
            $this->bulks[] = $calls;
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    protected function tearDown(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN');
        if ($this->previousRequest === null) {
            unset($GLOBALS['TYPO3_REQUEST']);
        } else {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        }
        parent::tearDown();
    }

    #[Test]
    public function oneRowPerSiteWithItsOwnConnectionAndALinkToTheSite(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'sites'])->getBody();

        self::assertSame(['Root', 'Shop', 'Trial'], $this->siteLabels($html));
        self::assertMatchesRegularExpression('#<td>Root</td>\s*<td>32</td>\s*<td>96</td>#', $html);
        self::assertMatchesRegularExpression('#<td>Shop</td>\s*<td>7</td>\s*<td>21</td>#', $html);
        self::assertMatchesRegularExpression('#/module/analytics-matomo/visitors\?[^"]*id=10&(amp;)?report=overview#', $html);
        // One bulk per Matomo connection; the demo site needs none
        self::assertCount(2, $this->bulks);
        $sites = [];
        foreach ($this->bulks as $bulk) {
            self::assertSame(['API.get'], array_values(array_unique(array_column($bulk, 'method'))));
            $sites[$bulk[0]['idSite']] = $bulk[0]['token_auth'];
            self::assertSame('2026-10-27,2026-11-02', $bulk[0]['date']);
        }
        self::assertSame(['3' => 'secret-token', '7' => ''], $sites);
    }

    #[Test]
    public function comparisonAddsThePreviousPeriodOfEverySite(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'sites', 'compare' => '1'])->getBody();

        self::assertMatchesRegularExpression('#<td>Root</td>\s*<td>32</td>\s*<td>\+100.0\s?%</td>#', $html);
        foreach ($this->bulks as $bulk) {
            self::assertSame(['2026-10-27,2026-11-02', '2026-10-20,2026-10-26'], array_column($bulk, 'date'));
        }
    }

    #[Test]
    public function aFailingConnectionLeavesTheOtherSites(): void
    {
        $this->shopFails = true;
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'sites'])->getBody();

        self::assertMatchesRegularExpression('#<td>Root</td>\s*<td>32</td>#', $html);
        self::assertMatchesRegularExpression('#<td>Shop</td>\s*<td>–</td>#', $html);
        self::assertStringNotContainsString('no view access', $html);
    }

    #[Test]
    public function editorsSeeOnlyTheirSitesAndNoPageForASingleSite(): void
    {
        $html = (string)$this->render(6, ['id' => '1', 'report' => 'sites'])->getBody();
        self::assertSame(['Root', 'Shop'], $this->siteLabels($html));

        $this->bulks = [];
        $single = (string)$this->render(5, ['id' => '1', 'report' => 'sites'])->getBody();
        self::assertStringNotContainsString('All sites', $single);
        self::assertSame([], $this->siteLabels($single));
    }

    #[Test]
    public function rowsAreCachedPerConnection(): void
    {
        $this->render(1, ['id' => '1', 'report' => 'sites']);
        $html = (string)$this->render(1, ['id' => '10', 'report' => 'sites'])->getBody();

        self::assertSame(['Root', 'Shop', 'Trial'], $this->siteLabels($html));
        self::assertCount(2, $this->bulks);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function answer(array $parameters): mixed
    {
        if ($parameters['idSite'] === '7' && $this->shopFails) {
            return json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/ErrorNoViewAccess.json'), true)[0];
        }
        $current = $parameters['date'] === '2026-10-27,2026-11-02';
        return $parameters['idSite'] === '3'
            ? ['nb_visits' => $current ? 32 : 16, 'nb_pageviews' => 96, 'bounce_rate' => 0.72, 'avg_time_on_site' => 125]
            : ['nb_visits' => $current ? 7 : 7, 'nb_pageviews' => 21, 'bounce_rate' => 0.5, 'avg_time_on_site' => 40];
    }

    /** @return list<string> */
    private function siteLabels(string $html): array
    {
        preg_match_all('#<tr>\s*<td>(Root|Shop|Trial|Unconfigured)</td>#', $html, $matches);
        return $matches[1];
    }
}
