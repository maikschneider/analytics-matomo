<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;

/**
 * The numbers of one page URL for the Page details section, in one cached bulk request.
 */
final readonly class PageDetailsService
{
    public function __construct(private MatomoClient $client, private StatisticsCache $cache, private EvolutionChartFactory $chartFactory)
    {
    }

    /**
     * @return array{current: array<string, mixed>, previous: array<string, mixed>|null, chart: array<string, mixed>}
     * @throws MatomoApiException
     */
    public function getDetails(MatomoConfiguration $configuration, string $pageUrl, Period $period, bool $compare): array
    {
        return $this->cache->remember(['pageDetails', $configuration->getCacheIdentifier(), $pageUrl, $period->toMatomoDate(), $compare], function () use ($configuration, $pageUrl, $period, $compare): array {
            $requests = [];
            foreach ($compare ? [$period, $period->previous()] : [$period] as $range) {
                $request = ['method' => 'Actions.getPageUrl', 'pageUrl' => $pageUrl, 'format_metrics' => 0, 'date' => $range->toMatomoDate()];
                $requests[] = $request + ['period' => 'range'];
                $requests[] = $request + ['period' => $period->granularity()];
            }
            $results = $this->client->bulkRequest($configuration, $requests);
            return [
                'current' => $this->row($results[0]),
                'previous' => $compare ? $this->row($results[2]) : null,
                'chart' => $this->chartFactory->create($period, $this->series($results[1]), $compare ? $this->series($results[3]) : null, EvolutionChartFactory::PAGE_METRICS),
            ];
        });
    }

    /**
     * Matomo answers with a list of the one matching row, or an empty list for a page without data
     *
     * @return array<string, mixed>
     */
    private function row(mixed $result): array
    {
        if (!is_array($result) || !array_is_list($result) || ($result !== [] && !is_array($result[0]))) {
            throw new MatomoApiException('Matomo answered with unexpected page details', 1794040001);
        }
        return $result[0] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    private function series(mixed $result): array
    {
        if (!is_array($result) || ($result !== [] && array_is_list($result))) {
            throw new MatomoApiException('Matomo answered with an unexpected page evolution', 1794040002);
        }
        $series = [];
        foreach ($result as $date => $rows) {
            // Matomo labels calendar weeks/months as "from,to"; use the bucket start.
            $series[explode(',', (string)$date)[0]] = $this->row($rows);
        }
        return $series;
    }
}
