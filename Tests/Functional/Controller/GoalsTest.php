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

final class GoalsTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<array<string, string>> parameters of every sub-request sent to Matomo */
    private array $calls = [];
    private int $requests = 0;
    /** @var array<string, mixed> recorded answers by name */
    private array $fixtures = [];
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'groupMods' => 'analytics_matomo,analytics_matomo_goals',
        ], ['uid' => 1]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $this->fixtures = $this->fixture('Goals');
        $this->fixtures['goal1EntryPages'] = $this->fixture('SyntheticGoalEntryPages');
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise {
            $this->requests++;
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $results = [];
            foreach ($body['urls'] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->calls[] = $parameters;
                $results[] = $this->answer($parameters);
            }
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
    public function withoutGoalsTheSectionTellsWhereGoalsAreDefined(): void
    {
        $this->fixtures['goals'] = [];
        $html = (string)$this->render(1, ['id' => '1'], 'goals')->getBody();

        self::assertStringContainsString('This Matomo site has no goals.', $html);
        self::assertSame(['Goals.getGoals'], array_column($this->calls, 'method'));
    }

    #[Test]
    public function overviewShowsConversionsOfAllGoals(): void
    {
        $html = (string)$this->render(1, ['id' => '1'], 'goals')->getBody();

        self::assertStringContainsString('tx-analytics-matomo-metric--conversions', $html);
        self::assertStringContainsString('tx-analytics-matomo-metric--conversionRate', $html);
        self::assertStringContainsString('20.7 %', $html);
        // Every goal tracked without revenue
        self::assertStringNotContainsString('tx-analytics-matomo-metric--revenue', $html);
        self::assertSame([0, 1, 1, 1, 0, 3, 0], $this->chartData($html)['metrics']['conversions']['current']);
        self::assertMatchesRegularExpression('#<td[^>]*>\s*View Photos\s*</td>\s*<td[^>]*>\s*4\s*</td>#', $html);
        self::assertMatchesRegularExpression('#<td[^>]*>\s*View Videos\s*</td>\s*<td[^>]*>\s*2\s*</td>#', $html);
        self::assertMatchesRegularExpression('#href="[^"]*/module/analytics-matomo/goals\?[^"]*report=goal2"#', $html);
        // The goal list is cached on its own; the overview is one bulk request
        self::assertSame(2, $this->requests);
    }

    #[Test]
    public function goalPageShowsConversionsByChannelAndEntryPage(): void
    {
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'goal1'], 'goals')->getBody();

        self::assertSame('1', array_column(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'Actions.getEntryPageUrls'), 'idGoal')[0] ?? null);
        self::assertStringContainsString('<h1>View Photos</h1>', $html);
        self::assertStringContainsString('Conversions by channel', $html);
        self::assertMatchesRegularExpression('#Search Engines\s*</td>\s*<td[^>]*>\s*23\s*</td>\s*<td[^>]*>\s*4\s*</td>#', $html);
        self::assertStringContainsString('Conversions by entry page', $html);
        self::assertMatchesRegularExpression('#<th scope="col"[^>]*><a[^>]*sort=label[^>]*>\s*Entry page#', $html);
        self::assertStringContainsString('The total number of goal conversions where this page was the entry page.', $html);
        self::assertMatchesRegularExpression('#<td[^>]*>/about</td>\s*<td[^>]*>25</td>\s*<td[^>]*>2</td>\s*<td[^>]*>0.08</td>#', $html);
        self::assertMatchesRegularExpression('#href="[^"]*/module/analytics-matomo/page\?[^"]*id=2[&"]#', $html);
        self::assertStringContainsString('>/de/ueber-uns</td>', $html);
        self::assertStringContainsString('>/storage</td>', $html);
    }

    #[Test]
    public function channelsShowTheirShareOfTheGoalsConversions(): void
    {
        $this->fixtures['goal2Channels'] = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/GoalChannelsAttribution.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->fixtures['goal2EntryPages'] = $this->fixtures['goal1EntryPages'];
        $this->fixtures['goal2Days'] = $this->fixtures['goal1Days'];
        $html = (string)$this->render(1, ['id' => '1', 'report' => 'goal2'], 'goals')->getBody();
        [$channels] = explode('Conversions by entry page', $html);

        self::assertStringContainsString('Share of conversions', $channels);
        // Matomo credits 6 of the goal's 91 conversions to the campaigns of 2 visits
        self::assertMatchesRegularExpression('#Campaigns\s*</td>\s*<td[^>]*>\s*2\s*</td>\s*<td[^>]*>\s*6\s*</td>\s*<td[^>]*>\s*6.6 %\s*</td>#', $channels);
        preg_match_all('#([\d.,]+) %#', $channels, $percentages);
        self::assertNotEmpty($percentages[1]);
        foreach ($percentages[1] as $percentage) {
            self::assertLessThanOrEqual(100, (float)str_replace(',', '', $percentage));
        }
    }

    #[Test]
    public function goalChartsUseCalendarWeekAndMonthResponses(): void
    {
        foreach ([
            ['week', '2026-08-01', '2026-07-27,2026-08-02'],
            ['month', '2025-08-01', '2025-08-01,2025-08-31'],
        ] as [$granularity, $from, $bucket]) {
            $this->fixtures['overviewDays'] = [$bucket => ['nb_conversions' => 7, 'conversion_rate' => 0.5]];
            $html = (string)$this->render(1, ['site' => 'main', 'report' => 'overview', 'period' => 'custom', 'from' => $from, 'to' => '2026-11-02'], 'goals')->getBody();
            $chart = $this->chartData($html);
            self::assertSame($granularity, $chart['granularity']);
            self::assertSame(7, $chart['metrics']['conversions']['current'][0]);
        }
    }

    #[Test]
    public function repeatedConversionsAreCountsPerEntranceNotAShareOfVisits(): void
    {
        $this->fixtures['goal1EntryPages'] = [[
            'label' => '/about', 'url' => 'https://example.com/about', 'entry_nb_visits' => 1,
            'goals' => ['idgoal=1' => ['nb_conversions_entry' => 3]],
        ]];
        $html = (string)$this->render(1, ['site' => 'main', 'report' => 'goal1'], 'goals')->getBody();
        [, $entrances] = explode('<h2>Conversions by entry page</h2>', $html);
        self::assertStringContainsString('Conversions per entrance', $entrances);
        self::assertMatchesRegularExpression('#<td>/about</td>\s*<td>1</td>\s*<td>3</td>\s*<td>3</td>#', $entrances);
        self::assertStringNotContainsString('300.0 %', $entrances);
    }

    #[Test]
    public function goalEntryPagesShowEditorsOnlyPagesTheyMaySee(): void
    {
        $html = (string)$this->render(3, ['id' => '1', 'report' => 'goal1'], 'goals')->getBody();

        self::assertStringContainsString('>/about</td>', $html);
        self::assertStringNotContainsString('>/de/ueber-uns</td>', $html);
        self::assertStringNotContainsString('>/storage</td>', $html);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function answer(array $parameters): mixed
    {
        $goal = isset($parameters['idGoal']) ? 'goal' . $parameters['idGoal'] : 'overview';
        return match ($parameters['method']) {
            'Goals.getGoals' => $this->fixtures['goals'],
            'Goals.get' => $this->fixtures[$goal . ($parameters['period'] !== 'range' ? 'Days' : '')] ?? self::fail('No fixture for ' . $goal),
            'Referrers.getReferrerType' => $this->fixtures[$goal . 'Channels'],
            'Actions.getEntryPageUrls' => $this->fixtures[$goal . 'EntryPages'],
            default => self::fail('Unexpected Matomo call ' . $parameters['method']),
        };
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @return array<string, mixed> */
    private function chartData(string $html): array
    {
        self::assertSame(1, preg_match('/data-evolution-chart="([^"]+)"/', $html, $matches));
        return json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }
}
