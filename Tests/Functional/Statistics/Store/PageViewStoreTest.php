<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics\Store;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewStore;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use MaikSchneider\AnalyticsMatomo\Tests\Functional\PageViewStoreTrait;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageViewStoreTest extends FunctionalTestCase
{
    use PageViewStoreTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<string> token of every access check sent to Matomo */
    private array $accessChecks = [];
    private string $timezone = 'UTC';
    private bool $unreachable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database.csv');
        $this->setNow('2026-11-23 14:30:00');
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            if ($this->unreachable) {
                return new FulfilledPromise(new Response(500, [], 'Internal Server Error'));
            }
            parse_str((string)$request->getBody(), $body);
            $results = [];
            foreach (is_array($body['urls'] ?? null) ? $body['urls'] : [] as $url) {
                parse_str(is_string($url) ? $url : '', $parameters);
                /** @var array<string, string> $parameters */
                self::assertSame('SitesManager.getSiteFromId', $parameters['method']);
                $token = is_string($body['token_auth'] ?? null) ? $body['token_auth'] : '';
                $this->accessChecks[] = $token;
                // Matomo's answer for a site the token may not view
                $results[] = $token === 'secret-token'
                    ? ['idsite' => (string)$parameters['idSite'], 'timezone' => $this->timezone, 'ts_created' => '2020-01-01 00:00:00']
                    : ['result' => 'error', 'message' => "You can't access this resource as it requires 'view' access for the website id = " . $parameters['idSite'] . '.'];
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    #[Test]
    public function theStoreAnswersWhenItHoldsEveryDayOfEveryPeriod(): void
    {
        self::assertNull($this->store()->source($this->configuration(), null, $this->week()));
        self::assertSame([], $this->accessChecks);

        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [self::row('https://example.com/about', 2)]), []);

        $source = $this->store()->source($this->configuration(), null, $this->week());
        self::assertNotNull($source);
        self::assertTrue($source->isComplete);
        self::assertSame(14, $source->of($this->week())['coverage']->totalHits);
        self::assertSame([20261117, 20261123], [$source->oldestDay, $source->newestDay]);

        $source = $this->store()->source($this->configuration(), null, $this->week(), $this->week()->previous());
        self::assertNotNull($source);
        self::assertFalse($source->isComplete);
    }

    #[Test]
    public function aTokenWithoutViewAccessToTheMatomoSiteReadsNoStore(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [self::row('https://example.com/about', 2)]), []);
        // Another TYPO3 site on the same Matomo site, with a token of another Matomo user
        $other = new MatomoConfiguration('https://matomo.example.com/', 3, 'token-of-another-user');

        self::assertNull($this->store()->source($other, null, $this->week()));
        self::assertNull($this->store()->source($other, null, $this->week()));
        self::assertNotNull($this->store()->source($this->configuration(), null, $this->week()));
        // Checked once per token, then cached
        self::assertSame(['token-of-another-user', 'secret-token'], $this->accessChecks);
    }

    #[Test]
    public function anUnansweredAccessCheckReadsNoStoreAndIsAskedAgain(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [self::row('https://example.com/about', 2)]), []);
        $this->unreachable = true;

        self::assertNull($this->store()->source($this->configuration(), null, $this->week()));

        $this->unreachable = false;
        self::assertNotNull($this->store()->source($this->configuration(), null, $this->week()));
    }

    #[Test]
    public function segmentsAndDemoDataReadNoStore(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [self::row('https://example.com/about', 2)]), []);

        self::assertNull($this->store()->source($this->configuration()->withSegment('deviceType==smartphone'), null, $this->week()));
        self::assertNull($this->store()->source(MatomoConfiguration::demo('https://matomo.example.com/', 3), null, $this->week()));
        self::assertSame([], $this->accessChecks);
    }

    #[Test]
    public function daysAfterTodayOfTheMatomoSiteCountAsHeld(): void
    {
        // 02:00 in UTC is still the evening of the day before in New York: Matomo has no views for today yet
        $this->setNow('2026-11-24 02:00:00');
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-18', '2026-11-23', [self::row('https://example.com/about', 2)]), []);
        $week = Period::lastDays(7, new \DateTimeImmutable('2026-11-24'));

        self::assertFalse($this->store()->source($this->configuration(), null, $week)?->isComplete);

        $this->get(CacheManager::class)->getCache('analytics_matomo')->flush();
        $this->timezone = 'America/New_York';
        $source = $this->store()->source($this->configuration(), null, $week);
        self::assertTrue($source->isComplete);
        self::assertSame(['from' => 20261118, 'to' => 20261123], array_intersect_key($source->of($week), ['from' => true, 'to' => true]));
    }

    #[Test]
    public function aSiteWhoseUrlsAreNotMappedYetIsNotComplete(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [self::row('https://example.com/about', 2)]), []);

        self::assertFalse($this->store()->source($this->configuration(), $this->site('main'), $this->week())?->isComplete);
        self::assertTrue($this->store()->source($this->configuration(), null, $this->week())?->isComplete);

        $this->fillPageViewStore($this->configuration(), [], [$this->site('main')]);
        self::assertTrue($this->store()->source($this->configuration(), $this->site('main'), $this->week())?->isComplete);
    }

    #[Test]
    public function urlRowsEqualMatomosRangeReportOfTheSameDays(): void
    {
        $recorded = $this->fixture('PageUrlsWeek');
        $this->fillPageViewStore($this->configuration(), $recorded['days'], []);
        $period = Period::fromPreset(Period::CUSTOM, new \DateTimeImmutable('2026-11-23'), '2026-11-13', '2026-11-19');
        $source = $this->store()->source($this->configuration(), null, $period);
        self::assertTrue($source?->isComplete);

        $columns = ['nb_hits', 'nb_visits', 'sum_time_spent', 'entry_nb_visits', 'entry_bounce_count', 'exit_nb_visits', 'bounce_rate', 'avg_time_on_page', 'exit_rate'];
        $expected = $actual = [];
        foreach ($recorded['range'] as $row) {
            $expected[(string)UrlDayRows::key($row['url'])] = array_map(static fn (mixed $value): int|float => is_numeric($value) ? $value + 0 : 0, array_intersect_key($row, array_flip($columns)));
        }
        foreach ($this->store()->urlRows($source, $period, 1000) as $row) {
            $actual[(string)UrlDayRows::key($row['url'])] = array_map(static fn (mixed $value): int|float => $value + 0, array_intersect_key($row, array_flip($columns)));
        }
        ksort($expected);
        ksort($actual);
        self::assertEquals($expected, $actual);
        self::assertSame(array_sum(array_column($recorded['range'], 'nb_hits')), $source->of($period)['coverage']->totalHits);
    }

    #[Test]
    public function urlRowsEndWithASummaryRowWhenCutOrTruncated(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [
            self::row('https://example.com/about', 3),
            self::row('https://example.com/', 2),
            self::row('https://example.com/imprint', 1),
        ]), []);
        $source = $this->store()->source($this->configuration(), null, $this->week());
        self::assertNotNull($source);

        $rows = $this->store()->urlRows($source, $this->week(), 3);
        self::assertSame(['/about', '/', '/imprint'], array_column($rows, 'label'));
        self::assertSame([21, 14, 7], array_column($rows, 'nb_hits'));

        $rows = $this->store()->urlRows($source, $this->week(), 1);
        self::assertSame(['/about', '-1'], array_column($rows, 'label'));
        self::assertSame([21, 21], array_column($rows, 'nb_hits'));
        self::assertSame(1, $rows[1]['is_summary']);
    }

    #[Test]
    public function urlRowsCountMatomosSummaryRowsOfTruncatedDays(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [
            self::row('https://example.com/about', 3),
            ['label' => -1, 'nb_hits' => 5],
        ]), []);
        $source = $this->store()->source($this->configuration(), null, $this->week());
        self::assertNotNull($source);

        $rows = $this->store()->urlRows($source, $this->week(), 10);
        self::assertSame(['/about', '-1'], array_column($rows, 'label'));
        self::assertSame([21, 35], array_column($rows, 'nb_hits'));
    }

    #[Test]
    public function pageReportSumsTheUrlsOfEachPageOfTheSite(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [
            self::row('https://example.com/about', 3),
            self::row('http://example.com/about?utm_source=news', 1),
            self::row('https://example.com/de/ueber-uns', 2),
            self::row('https://example.com/missing', 4),
            self::row('https://shop.example.org/products/', 5),
        ]), [$this->site('main')]);
        $source = $this->store()->source($this->configuration(), $this->site('main'), $this->week());
        self::assertTrue($source?->isComplete);

        $report = $this->store()->pageReport($source, $this->site('main'), $this->week());
        self::assertFalse($report->isTruncated);
        self::assertSame([28], array_column($report->rowsOfPage(2, 0) ?? [], 'nb_hits'));
        self::assertSame([14], array_column($report->rowsOfPage(2, 1) ?? [], 'nb_hits'));
        self::assertSame([], $report->rowsOfPage(7, 0));
    }

    #[Test]
    public function rowsOfUrlsAnswerZeroForUrlsWithoutViews(): void
    {
        $this->fillPageViewStore($this->configuration(), self::everyDay('2026-11-17', '2026-11-23', [self::row('https://example.com/about', 3)]), []);
        $source = $this->store()->source($this->configuration(), null, $this->week());
        self::assertNotNull($source);

        $rows = $this->store()->rowsOfUrls($source, $this->week(), ['about' => 'http://example.com/about', 'new' => 'https://example.com/new']);
        self::assertSame(21, $rows['about']['nb_hits']);
        self::assertSame(0, $rows['new']['nb_hits']);
    }

    /**
     * @return array<string, int|string>
     */
    private static function row(string $url, int $hits): array
    {
        return [
            'label' => (string)parse_url($url, PHP_URL_PATH), 'url' => $url, 'nb_hits' => $hits, 'nb_visits' => $hits,
            'sum_time_spent' => 10 * $hits, 'entry_nb_visits' => $hits, 'entry_bounce_count' => 0, 'exit_nb_visits' => $hits,
        ];
    }

    private function configuration(): MatomoConfiguration
    {
        return new MatomoConfiguration('https://matomo.example.com/', 3, 'secret-token');
    }

    private function week(): Period
    {
        return Period::lastDays(7, new \DateTimeImmutable('2026-11-23'));
    }

    private function store(): PageViewStore
    {
        return $this->get(PageViewStore::class);
    }

    private function site(string $identifier): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier($identifier);
    }

    private function setNow(string $now): void
    {
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable($now)));
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../../Fixtures/Matomo/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}
