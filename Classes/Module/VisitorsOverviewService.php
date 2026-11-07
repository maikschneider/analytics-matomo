<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;

final readonly class VisitorsOverviewService
{
    public function __construct(private MatomoClient $client, private StatisticsCache $cache, private EvolutionChartFactory $chartFactory)
    {
    }

    /** @return array{current: array<string, mixed>, previous: array<string, mixed>|null, chart: array<string, mixed>} */
    public function getOverview(MatomoConfiguration $configuration, Period $period, bool $compare): array
    {
        return $this->cache->remember(['overview', $configuration->getCacheIdentifier(), $period->toMatomoDate(), $compare], function () use ($configuration, $period, $compare): array {
            $requests = [];
            foreach ($compare ? [$period, $period->previous()] : [$period] as $range) {
                $request = ['method' => 'API.get', 'format_metrics' => 0, 'date' => $range->toMatomoDate()];
                $requests[] = $request + ['period' => 'range'];
                $requests[] = $request + ['period' => $period->granularity()];
            }
            $results = $this->client->bulkRequest($configuration, $requests);
            $current = $this->row($results[0]);
            $evolution = $this->series($results[1]);
            $previous = $compare ? $this->row($results[2]) : null;
            $previousEvolution = $compare ? $this->series($results[3]) : null;
            return ['current' => $current, 'previous' => $previous, 'chart' => $this->chartFactory->create($period, $evolution, $previousEvolution)];
        });
    }

    /** @return array<string, string> */
    public function getCounters(MatomoConfiguration $configuration): array
    {
        return $this->cache->remember(['liveCounters', $configuration->getCacheIdentifier()], function () use ($configuration): array {
            [$result] = $this->client->bulkRequest($configuration, [['method' => 'Live.getCounters', 'lastMinutes' => 30]]);
            if (!is_array($result) || !array_is_list($result) || count($result) !== 1) {
                throw new MatomoApiException('Matomo answered with unexpected live counters', 1793960401);
            }
            $row = $this->row($result[0]);
            $counters = [];
            foreach (['visits', 'actions', 'visitors'] as $key) {
                $counters[$key] = (new MetricFormatter())->format($row[$key] ?? null, 'number');
            }
            return $counters;
        }, 60);
    }

    /** @return array<string, array<string, mixed>> */
    private function series(mixed $result): array
    {
        $rows = $this->row($result);
        $series = [];
        foreach ($rows as $date => $row) {
            // Matomo labels calendar weeks/months as "from,to"; use the bucket start.
            $series[explode(',', $date)[0]] = $this->row($row);
        }
        return $series;
    }

    /** @return array<string, mixed> */
    private function row(mixed $result): array
    {
        if (!is_array($result) || ($result !== [] && array_is_list($result)) || ($result['result'] ?? null) === 'error') {
            throw new MatomoApiException('Matomo answered with unexpected overview data', 1793960402);
        }
        return $result;
    }
}
