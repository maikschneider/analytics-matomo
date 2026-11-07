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
     * @param array<int, string> $languageUrls URLs of the page in its other languages, by language ID
     * @return array{current: array<string, mixed>, previous: array<string, mixed>|null, chart: array<string, mixed>, pageRow: array<string, mixed>, languages: array<int, array<string, mixed>>, goals: array<int, string>, trend: array{direction: 'up'|'down', difference: int}|null}
     * @throws MatomoApiException
     */
    public function getDetails(MatomoConfiguration $configuration, string $pageUrl, Period $period, bool $compare, array $languageUrls = []): array
    {
        return $this->cache->remember(['pageDetails', $configuration->getCacheIdentifier(), $pageUrl, $period->toMatomoDate(), $compare, ...$languageUrls], function () use ($configuration, $pageUrl, $period, $compare, $languageUrls): array {
            $request = ['method' => 'Actions.getPageUrl', 'format_metrics' => 0];
            $requests = [
                'current' => $request + ['pageUrl' => $pageUrl, 'period' => 'range', 'date' => $period->toMatomoDate()],
                'currentSeries' => $request + ['pageUrl' => $pageUrl, 'period' => $period->granularity(), 'date' => $period->toMatomoDate()],
            ];
            // The previous total is needed for the trend hint even without comparison
            $requests['previous'] = $request + ['pageUrl' => $pageUrl, 'period' => 'range', 'date' => $period->previous()->toMatomoDate()];
            // The page report total is the base of the trend's impact threshold, as in the trending list
            $requests['siteViews'] = [
                'method' => 'API.getProcessedReport', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls', 'flat' => 1, 'period' => 'range',
                'date' => $period->toMatomoDate(), 'format_metrics' => 0, 'filter_limit' => 1, 'showColumns' => 'nb_hits',
            ];
            if ($compare) {
                $requests['previousSeries'] = $request + ['pageUrl' => $pageUrl, 'period' => $period->granularity(), 'date' => $period->previous()->toMatomoDate()];
            }
            // Only the page URL report carries timings and goal conversions; Actions.getPageUrl drops them
            $requests['pageRows'] = [
                'method' => 'Actions.getPageUrls', 'flat' => 1, 'format_metrics' => 0, 'period' => 'range', 'date' => $period->toMatomoDate(),
                'filter_column' => 'url', 'filter_pattern' => $this->urlPattern($pageUrl), 'filter_limit' => -1,
            ];
            foreach ($languageUrls as $languageId => $languageUrl) {
                $requests['language' . $languageId] = $request + ['pageUrl' => $languageUrl, 'period' => 'range', 'date' => $period->toMatomoDate()];
            }
            $requests['goals'] = ['method' => 'Goals.getGoals'];
            $results = array_combine(array_keys($requests), $this->client->bulkRequest($configuration, array_values($requests)));
            $pageRows = $this->rows($results['pageRows']);
            usort($pageRows, static fn (array $a, array $b): int => (int)($b['nb_hits'] ?? 0) <=> (int)($a['nb_hits'] ?? 0));
            $languages = [];
            foreach (array_keys($languageUrls) as $languageId) {
                $languages[$languageId] = $this->row($results['language' . $languageId]);
            }
            $current = $this->row($results['current']);
            $previousViews = (int)($this->row($results['previous'])['nb_hits'] ?? 0);
            $currentViews = (int)($current['nb_hits'] ?? 0);
            $siteViews = is_array($results['siteViews']) && is_array($results['siteViews']['reportTotal'] ?? null) ? (int)($results['siteViews']['reportTotal']['nb_hits'] ?? 0) : 0;
            $trend = TrendingService::trend($previousViews, $currentViews, $siteViews);
            return [
                'current' => $current,
                'previous' => $compare ? $this->row($results['previous']) : null,
                'chart' => $this->chartFactory->create($period, $this->series($results['currentSeries']), $compare ? $this->series($results['previousSeries']) : null, EvolutionChartFactory::PAGE_METRICS),
                // A site tracked under http and https has a row per scheme; the busier one wins
                'pageRow' => $pageRows[0] ?? [],
                'languages' => $languages,
                'goals' => GoalsService::names($results['goals']),
                'trend' => $trend === null ? null : ['direction' => $trend, 'difference' => $currentViews - $previousViews],
            ];
        });
    }

    /**
     * Matomo keeps the scheme of the tracked URL; a site with a relative base has no host to match
     */
    private function urlPattern(string $pageUrl): string
    {
        return str_starts_with($pageUrl, '/')
            ? '^https?://[^/]+' . preg_quote($pageUrl) . '$'
            : '^https?://' . preg_quote((string)preg_replace('#^[a-z]+://#i', '', $pageUrl)) . '$';
    }

    /**
     * Matomo answers with a list of the one matching row, or an empty list for a page without data
     *
     * @return array<string, mixed>
     */
    private function row(mixed $result): array
    {
        return $this->rows($result)[0] ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $result): array
    {
        if (!is_array($result) || !array_is_list($result) || array_filter($result, is_array(...)) !== $result) {
            throw new MatomoApiException('Matomo answered with unexpected page details', 1794040001);
        }
        return $result;
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
