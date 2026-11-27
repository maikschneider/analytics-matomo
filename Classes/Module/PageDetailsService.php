<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewSource;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewStore;
use Psr\Log\LoggerInterface;

/**
 * The numbers of one page URL for the Page details section, cached. The totals of the page,
 * its languages and the site come from the page view store when it holds both periods.
 * Timings and goal conversions are only in Matomo's page URL report of the period: with the
 * store they are asked on their own, and the page shows without them when Matomo does not
 * answer in time. Without the store everything is one bulk request.
 *
 * @phpstan-type Details array{current: array<string, mixed>, previous: array<string, mixed>|null, chart: array<string, mixed>, pageRow: array<string, mixed>|null, languages: array<int, array<string, mixed>>, goals: array<int, string>, trend: array{direction: 'up'|'down', difference: int}|null, source: ?PageViewSource}
 */
final readonly class PageDetailsService
{
    private const FAILURE_LIFETIME = 300;

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private EvolutionChartFactory $chartFactory,
        private PageViewStore $store,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, string> $languageUrls URLs of the page in its other languages, by language ID
     * @return Details pageRow is null when Matomo did not answer the page URL report
     * @throws MatomoApiException
     */
    public function getDetails(MatomoConfiguration $configuration, string $pageUrl, Period $period, bool $compare, array $languageUrls = []): array
    {
        $key = ['pageDetails', $configuration->getCacheIdentifier(), $pageUrl, $period->toMatomoDate(), $compare, ...$languageUrls];
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            /** @var Details $cached */
            return $cached;
        }
        // The store knows URLs with their host only, as Matomo tracks them
        $source = array_filter([$pageUrl, ...$languageUrls], static fn (string $url): bool => !str_contains($url, '://')) === []
            ? $this->store->source($configuration, null, $period, $period->previous())
            : null;
        $complete = $source !== null && $source->isComplete ? $source : null;

        $request = ['method' => 'Actions.getPageUrl', 'format_metrics' => 0];
        // Only the page URL report carries timings and goal conversions; Actions.getPageUrl drops them
        $pageRowsRequest = [
            'method' => 'Actions.getPageUrls', 'flat' => 1, 'format_metrics' => 0, 'period' => 'range', 'date' => $period->toMatomoDate(),
            'filter_column' => 'url', 'filter_pattern' => $this->urlPattern($pageUrl), 'filter_limit' => -1,
        ];
        $live = $complete === null;
        $requests = array_filter([
            'current' => $live ? $request + ['pageUrl' => $pageUrl, 'period' => 'range', 'date' => $period->toMatomoDate()] : null,
            'currentSeries' => $request + ['pageUrl' => $pageUrl, 'period' => $period->granularity(), 'date' => $period->toMatomoDate()],
            // The previous total is needed for the trend hint even without comparison
            'previous' => $live ? $request + ['pageUrl' => $pageUrl, 'period' => 'range', 'date' => $period->previous()->toMatomoDate()] : null,
            // The page report total is the base of the trend's impact threshold, as in the trending list
            'siteViews' => $live ? [
                'method' => 'API.getProcessedReport', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls', 'flat' => 1, 'period' => 'range',
                'date' => $period->toMatomoDate(), 'format_metrics' => 0, 'filter_limit' => 1, 'showColumns' => 'nb_hits',
            ] : null,
            'previousSeries' => $compare ? $request + ['pageUrl' => $pageUrl, 'period' => $period->granularity(), 'date' => $period->previous()->toMatomoDate()] : null,
            'pageRows' => $live ? $pageRowsRequest : null,
        ]);
        foreach ($live ? $languageUrls : [] as $languageId => $languageUrl) {
            $requests['language' . $languageId] = $request + ['pageUrl' => $languageUrl, 'period' => 'range', 'date' => $period->toMatomoDate()];
        }
        $requests['goals'] = ['method' => 'Goals.getGoals'];
        $results = array_combine(array_keys($requests), $this->client->bulkRequest($configuration, array_values($requests)));

        if ($complete !== null) {
            $languages = $this->store->rowsOfUrls($complete, $period, [-1 => $pageUrl] + $languageUrls);
            $current = $languages[-1];
            unset($languages[-1]);
            $previous = $this->store->rowsOfUrls($complete, $period->previous(), [$pageUrl])[0];
            $siteViews = $complete->of($period)['coverage']->totalHits;
        } else {
            $languages = [];
            foreach (array_keys($languageUrls) as $languageId) {
                $languages[$languageId] = $this->row($results['language' . $languageId]);
            }
            $current = $this->row($results['current']);
            $previous = $this->row($results['previous']);
            $siteViews = is_array($results['siteViews']) && is_array($results['siteViews']['reportTotal'] ?? null) ? (int)($results['siteViews']['reportTotal']['nb_hits'] ?? 0) : 0;
        }
        $previousViews = (int)($previous['nb_hits'] ?? 0);
        $currentViews = (int)($current['nb_hits'] ?? 0);
        $trend = TrendingService::trend($previousViews, $currentViews, $siteViews);
        $pageRow = $complete === null ? $this->busiest($this->rows($results['pageRows'])) : $this->pageRow($configuration, $pageRowsRequest);
        $details = [
            'current' => $current,
            'previous' => $compare ? $previous : null,
            'chart' => $this->chartFactory->create($period, $this->series($results['currentSeries']), $compare ? $this->series($results['previousSeries']) : null, EvolutionChartFactory::PAGE_METRICS),
            'pageRow' => $pageRow,
            'languages' => $languages,
            'goals' => GoalsService::names($results['goals']),
            'trend' => $trend === null ? null : ['direction' => $trend, 'difference' => $currentViews - $previousViews],
            'source' => $source,
        ];
        $this->cache->set($key, $details, $pageRow === null ? self::FAILURE_LIFETIME : null);
        return $details;
    }

    /**
     * The row of the page in Matomo's page URL report, asked on its own; null when Matomo
     * did not answer, e.g. as it builds the report of the whole site for the period first.
     *
     * @param array<string, int|string> $request
     * @return array<string, mixed>|null
     */
    private function pageRow(MatomoConfiguration $configuration, array $request): ?array
    {
        try {
            [$result] = $this->client->bulkRequest($configuration, [$request]);
            return $this->busiest($this->rows($result));
        } catch (MatomoApiException $exception) {
            $this->logger->warning('Loading the Matomo page URL report failed: {message}', ['message' => $exception->getMessage(), 'pattern' => $request['filter_pattern']]);
            return null;
        }
    }

    /**
     * A site tracked under http and https has a row per scheme; the busier one wins
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function busiest(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => (int)($b['nb_hits'] ?? 0) <=> (int)($a['nb_hits'] ?? 0));
        return $rows[0] ?? [];
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
