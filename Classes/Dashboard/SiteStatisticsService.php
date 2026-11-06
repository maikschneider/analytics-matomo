<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;

/**
 * Site-wide numbers for the dashboard widgets: one bulk request per widget,
 * cached per Matomo site, period and language.
 */
final readonly class SiteStatisticsService
{
    private const TOP_PAGES_LIMIT = 50;
    private const PREVIOUS_PAGES_LIMIT = 500;
    private const BREAKDOWN_ROWS = 4;

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
    ) {
    }

    /**
     * @throws MatomoApiException
     */
    public function getPerformance(MatomoConfiguration $configuration, Period $period): SitePerformance
    {
        return $this->cache->remember($this->key('performance', $configuration, $period), function () use ($configuration, $period): SitePerformance {
            $visits = ['method' => 'VisitsSummary.get', 'format_metrics' => 0];
            $pageViews = ['method' => 'Actions.get', 'format_metrics' => 0, 'showColumns' => 'nb_pageviews'];
            [$current, $previous, $currentViews, $previousViews, $dailyVisits, $dailyViews] = $this->client->bulkRequest($configuration, [
                $visits + $this->range($period),
                $visits + $this->range($period->previous()),
                $pageViews + $this->range($period),
                $pageViews + $this->range($period->previous()),
                $visits + $this->days($period),
                $pageViews + $this->days($period),
            ]);
            $dailyVisits = $this->dailyMap($dailyVisits);
            $dailyViews = $this->dailyMap($dailyViews);

            $daily = [];
            foreach ($period->dates() as $date) {
                $daily[$date] = $this->siteMetrics($dailyVisits[$date] ?? [], $dailyViews[$date] ?? []);
            }
            return new SitePerformance(
                $this->siteMetrics($current, $currentViews),
                $this->siteMetrics($previous, $previousViews),
                $daily,
            );
        });
    }

    /**
     * @throws MatomoApiException
     */
    public function getTraffic(MatomoConfiguration $configuration, Period $period): Traffic
    {
        return $this->cache->remember($this->key('traffic', $configuration, $period), function () use ($configuration, $period): Traffic {
            [$visitsPerDay, $returningPerDay] = $this->client->bulkRequest($configuration, [
                ['method' => 'VisitsSummary.get', 'format_metrics' => 0] + $this->days($period),
                ['method' => 'VisitFrequency.get', 'format_metrics' => 0, 'showColumns' => 'nb_visits_returning'] + $this->days($period),
            ]);
            $visitsPerDay = $this->dailyMap($visitsPerDay);
            $returningPerDay = $this->dailyMap($returningPerDay);

            $dates = $period->dates();
            $visits = $visitors = $newVisits = $returningVisits = [];
            foreach ($dates as $date) {
                $row = $this->row($visitsPerDay[$date] ?? []);
                $returning = $this->metric($returningPerDay[$date] ?? [], 'nb_visits_returning');
                $visits[] = (int)($row['nb_visits'] ?? 0);
                $visitors[] = (int)($row['nb_uniq_visitors'] ?? 0);
                $returningVisits[] = $returning;
                $newVisits[] = max(0, (int)($row['nb_visits'] ?? 0) - $returning);
            }
            return new Traffic($dates, $visits, $visitors, $newVisits, $returningVisits);
        });
    }

    /**
     * Most viewed page URLs of the Matomo site, which may also contain URLs of other domains.
     *
     * @return list<TopPage>
     * @throws MatomoApiException
     */
    public function getTopPages(MatomoConfiguration $configuration, Period $period): array
    {
        return $this->cache->remember($this->key('topPages', $configuration, $period), function () use ($configuration, $period): array {
            $pageUrls = [
                'method' => 'Actions.getPageUrls',
                'format_metrics' => 0,
                'flat' => 1,
                'filter_sort_column' => 'nb_hits',
                'filter_sort_order' => 'desc',
                'showColumns' => 'nb_hits',
            ];
            [$current, $previous, $total] = $this->client->bulkRequest($configuration, [
                ['filter_limit' => self::TOP_PAGES_LIMIT] + $pageUrls + $this->range($period),
                ['filter_limit' => self::PREVIOUS_PAGES_LIMIT] + $pageUrls + $this->range($period->previous()),
                ['method' => 'Actions.get', 'format_metrics' => 0, 'showColumns' => 'nb_pageviews'] + $this->range($period),
            ]);
            $totalViews = $this->metric($total, 'nb_pageviews');
            $previousViews = [];
            foreach ($this->rows($previous) as $row) {
                $previousViews[(string)($row['url'] ?? '')] = (int)($row['nb_hits'] ?? 0);
            }

            $pages = [];
            foreach ($this->rows($current) as $row) {
                $url = (string)($row['url'] ?? '');
                $views = (int)($row['nb_hits'] ?? 0);
                if ($url === '' || $views === 0) {
                    continue;
                }
                $pages[] = new TopPage($url, $views, $previousViews[$url] ?? 0, $totalViews > 0 ? $views / $totalViews : 0.0);
            }
            return $pages;
        });
    }

    /**
     * @param string $method one of Breakdown::METHODS
     * @param string $language language of the labels, e.g. 'de'
     * @throws MatomoApiException
     */
    public function getBreakdown(MatomoConfiguration $configuration, Period $period, string $method, string $language): Breakdown
    {
        if (!in_array($method, Breakdown::METHODS, true)) {
            throw new \InvalidArgumentException('Unknown breakdown report ' . $method, 1793960101);
        }
        return $this->cache->remember($this->key($method . '.' . $language, $configuration, $period), function () use ($configuration, $period, $method, $language): Breakdown {
            $request = ['method' => $method, 'format_metrics' => 0, 'filter_limit' => -1, 'showColumns' => 'nb_visits'];
            [$current, $previous] = $this->client->bulkRequest($configuration, [
                $request + $this->range($period),
                $request + $this->range($period->previous()),
            ], $language);

            $currentVisits = $this->visitsByLabel($current);
            $previousVisits = $this->visitsByLabel($previous);
            $total = array_sum($currentVisits);
            $previousTotal = array_sum($previousVisits);
            if ($total === 0) {
                return new Breakdown(0, []);
            }

            $rows = [];
            foreach (array_slice($currentVisits, 0, self::BREAKDOWN_ROWS, true) as $label => $visits) {
                $share = $visits / $total;
                $previousShare = ($previousVisits[$label] ?? 0) > 0 ? $previousVisits[$label] / $previousTotal : null;
                $rows[] = new BreakdownRow((string)$label, $visits, $share, $previousShare === null ? null : $share - $previousShare);
            }
            $otherVisits = array_sum(array_slice($currentVisits, self::BREAKDOWN_ROWS));
            if ($otherVisits > 0) {
                $rows[] = new BreakdownRow('', $otherVisits, $otherVisits / $total, null, true);
            }
            return new Breakdown($total, $rows);
        });
    }

    /**
     * @return array<string, int> visits per label, largest first, without labels without visits
     * @throws MatomoApiException
     */
    private function visitsByLabel(mixed $result): array
    {
        $visits = [];
        foreach ($this->rows($result) as $row) {
            $count = (int)($row['nb_visits'] ?? 0);
            if ($count > 0) {
                $visits[(string)($row['label'] ?? '')] = $count;
            }
        }
        arsort($visits);
        return $visits;
    }

    /**
     * @throws MatomoApiException
     */
    private function siteMetrics(mixed $visits, mixed $pageViews): SiteMetrics
    {
        $row = $this->row($visits);
        return new SiteMetrics(
            visits: (int)($row['nb_visits'] ?? 0),
            pageViews: $this->metric($pageViews, 'nb_pageviews'),
            bounceRate: (float)($row['bounce_rate'] ?? 0),
            averageTimeOnSite: (int)($row['avg_time_on_site'] ?? 0),
        );
    }

    /**
     * One metric of a single-row report. Matomo answers a report with only one
     * column as {"value": n}, a period without data as [].
     *
     * @throws MatomoApiException
     */
    private function metric(mixed $result, string $column): int
    {
        if (is_int($result) || is_float($result)) {
            return (int)$result;
        }
        $row = $this->row($result);
        return (int)($row[$column] ?? $row['value'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     * @throws MatomoApiException
     */
    private function row(mixed $result): array
    {
        if (!is_array($result) || ($result !== [] && array_is_list($result))) {
            throw $this->unexpected($result);
        }
        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     * @throws MatomoApiException
     */
    private function rows(mixed $result): array
    {
        if (!is_array($result) || !array_is_list($result)) {
            throw $this->unexpected($result);
        }
        foreach ($result as $row) {
            if (!is_array($row)) {
                throw $this->unexpected($result);
            }
        }
        return $result;
    }

    /**
     * Matomo answers a range of days with a map of date => result.
     *
     * @return array<string, mixed>
     * @throws MatomoApiException
     */
    private function dailyMap(mixed $result): array
    {
        if (!is_array($result) || ($result !== [] && array_is_list($result))) {
            throw $this->unexpected($result);
        }
        return $result;
    }

    private function unexpected(mixed $result): MatomoApiException
    {
        return new MatomoApiException('Matomo answered with an unexpected result: ' . mb_substr((string)json_encode($result), 0, 200), 1793960102);
    }

    /**
     * @return array{period: string, date: string}
     */
    private function range(Period $period): array
    {
        return ['period' => 'range', 'date' => $period->toMatomoDate()];
    }

    /**
     * @return array{period: string, date: string}
     */
    private function days(Period $period): array
    {
        return ['period' => 'day', 'date' => $period->toMatomoDate()];
    }

    /**
     * @return list<scalar>
     */
    private function key(string $report, MatomoConfiguration $configuration, Period $period): array
    {
        return ['site', $report, $configuration->url, $configuration->idSite, $period->toMatomoDate()];
    }
}
