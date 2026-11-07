<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;

/**
 * Goals of a Matomo site. Matomo has no processed reports for goals, so the
 * goal tables are built from the raw answers in the processed report shape
 * that ReportTableFactory renders.
 *
 * @phpstan-import-type Definition from ReportCatalog
 * @phpstan-import-type Report from ReportTableFactory
 */
final readonly class GoalsService
{
    public const METRICS = [
        'conversions' => ['nb_conversions', 'number', 'actions-check-circle'],
        'conversionRate' => ['conversion_rate', 'percent', 'actions-percent'],
        'revenue' => ['revenue', 'money', 'actions-wallet'],
    ];

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private EvolutionChartFactory $chartFactory,
    ) {
    }

    /**
     * @return array<int, string> goal names by ID, in Matomo's order
     * @throws MatomoApiException
     */
    public function getGoals(MatomoConfiguration $configuration): array
    {
        return $this->cache->remember(['goals', $configuration->getCacheIdentifier()], function () use ($configuration): array {
            [$result] = $this->client->bulkRequest($configuration, [['method' => 'Goals.getGoals']]);
            return self::names($result);
        });
    }

    /**
     * @return array<int, string> goal names by ID from a Goals.getGoals answer
     */
    public static function names(mixed $result): array
    {
        $goals = [];
        foreach (is_array($result) ? $result : [] as $goal) {
            if (is_array($goal) && isset($goal['idgoal'])) {
                $goals[(int)$goal['idgoal']] = (string)($goal['name'] ?? $goal['idgoal']);
            }
        }
        return $goals;
    }

    /**
     * Totals and chart of all goals or one goal, with the tables of that page
     *
     * @param array<int, string> $goals
     * @return array{current: array<string, mixed>, previous: array<string, mixed>|null, chart: array<string, mixed>, reports: list<array{definition: Definition, current: Report, previous: Report|null}>}
     * @throws MatomoApiException
     */
    public function getPage(MatomoConfiguration $configuration, array $goals, ?int $goalId, Period $period, bool $compare): array
    {
        return $this->cache->remember(['goalPage', $configuration->getCacheIdentifier(), $goalId ?? 0, $period->toMatomoDate(), $compare], function () use ($configuration, $goals, $goalId, $period, $compare): array {
            $ranges = $compare ? ['current' => $period, 'previous' => $period->previous()] : ['current' => $period];
            $goal = $goalId === null ? [] : ['idGoal' => $goalId];
            $requests = [];
            foreach ($ranges as $name => $range) {
                $date = ['date' => $range->toMatomoDate(), 'format_metrics' => 0];
                $requests[$name] = ['method' => 'Goals.get', 'period' => 'range'] + $goal + $date;
                $requests[$name . 'Series'] = ['method' => 'Goals.get', 'period' => $period->granularity()] + $goal + $date;
                if ($goalId === null) {
                    foreach (array_keys($goals) as $id) {
                        $requests[$name . 'Goal' . $id] = ['method' => 'Goals.get', 'period' => 'range', 'idGoal' => $id] + $date;
                    }
                } else {
                    $requests[$name . 'Channels'] = ['method' => 'Referrers.getReferrerType', 'period' => 'range', 'idGoal' => $goalId, 'filter_limit' => -1] + $date;
                    $requests[$name . 'EntryPages'] = ['method' => 'Actions.getEntryPageUrls', 'period' => 'range', 'idGoal' => $goalId, 'flat' => 1, 'filter_limit' => -1] + $date;
                }
            }
            $results = array_combine(array_keys($requests), $this->client->bulkRequest($configuration, array_values($requests)));
            $table = fn (string $name, \Closure $rows): array => [
                'definition' => self::definition($name),
                'current' => $this->report($rows('current')),
                'previous' => $compare ? $this->report($rows('previous')) : null,
            ];
            $reports = $goalId === null
                ? [$table('goals', fn (string $range): array => array_map(fn (int $id): array => ['label' => $goals[$id]] + $this->object($results[$range . 'Goal' . $id]), array_keys($goals)))]
                : [
                    $table('channels', fn (string $range): array => $this->goalRows($results[$range . 'Channels'], $goalId)),
                    $table('entryPages', fn (string $range): array => $this->goalRows($results[$range . 'EntryPages'], $goalId)),
                ];
            return [
                'current' => $this->object($results['current']),
                'previous' => $compare ? $this->object($results['previous']) : null,
                'chart' => $this->chartFactory->create($period, $this->object($results['currentSeries']), $compare ? $this->object($results['previousSeries']) : null, self::METRICS),
                'reports' => $reports,
            ];
        });
    }

    /** @return Definition */
    private static function definition(string $table): array
    {
        return match ($table) {
            'goals' => ['module' => 'Goals', 'action' => 'get', 'title' => 'report.goals.title', 'columns' => [
                'label' => ['column.goal', 'string'],
                'nb_conversions' => ['column.nb_conversions', 'number'],
                'conversion_rate' => ['column.conversion_rate', 'percent'],
                'revenue' => ['column.revenue', 'money'],
            ]],
            'channels' => ['module' => 'Referrers', 'action' => 'getReferrerType', 'title' => 'report.goalChannels', 'columns' => [
                'label' => ['column.channelType', 'string'],
                'nb_visits' => ['column.nb_visits', 'number'],
                'nb_conversions' => ['column.nb_conversions', 'number'],
                'conversion_rate' => ['column.conversion_rate', 'percent'],
                'revenue' => ['column.revenue', 'money'],
            ]],
            default => ['module' => 'Actions', 'action' => 'getEntryPageUrls', 'rows' => 'url', 'title' => 'report.goalEntryPages', 'columns' => [
                'label' => ['column.entryPage', 'string'],
                'entry_nb_visits' => ['column.entry_nb_visits', 'number'],
                'nb_conversions_entry' => ['column.nb_conversions_entry', 'number', 'column.nb_conversions_entry.description'],
                'nb_conversions_entry_rate' => ['column.nb_conversions_entry_rate', 'percent', 'column.nb_conversions_entry_rate.description'],
                'revenue_entry' => ['column.revenue_entry', 'money'],
            ]],
        };
    }

    /**
     * Rows with the metrics of one goal next to their own, and a conversion rate per visit
     *
     * @return list<array<string, mixed>>
     */
    private function goalRows(mixed $result, int $goalId): array
    {
        $rows = [];
        foreach (is_array($result) ? $result : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $goal = $row['goals']['idgoal=' . $goalId] ?? [];
            $row = (is_array($goal) ? $goal : []) + $row;
            $visits = (int)($row['nb_visits'] ?? 0);
            $row['nb_conversions'] = (int)($goal['nb_conversions'] ?? 0);
            $row['conversion_rate'] = $visits > 0 ? $row['nb_conversions'] / $visits : 0;
            // Matomo sums the daily rates of a range, so they are computed here
            $entries = (int)($row['entry_nb_visits'] ?? 0);
            $row['nb_conversions_entry'] = (int)($goal['nb_conversions_entry'] ?? 0);
            $row['nb_conversions_entry_rate'] = $entries > 0 ? $row['nb_conversions_entry'] / $entries : 0;
            unset($row['goals']);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return Report
     */
    private function report(array $rows): array
    {
        $metadata = [];
        foreach ($rows as $index => $row) {
            if (isset($row['url'])) {
                $metadata[$index] = ['url' => $row['url']];
            }
        }
        return ['metadata' => [], 'columns' => ['label' => ''], 'reportData' => $rows, 'reportMetadata' => $metadata, 'reportTotal' => []];
    }

    /** @return array<string, mixed> */
    private function object(mixed $result): array
    {
        return is_array($result) ? $result : [];
    }
}
