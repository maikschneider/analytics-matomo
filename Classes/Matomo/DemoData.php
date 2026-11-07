<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Matomo;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Dashboard\Breakdown;

/**
 * Generated Reporting API answers for trying out the extension without a Matomo
 * instance. Numbers depend only on site, method, URL and date, so they stay the
 * same on every reload and a range is the sum of its days. Top pages are the
 * pages of the site; deeper pages get fewer views.
 */
final readonly class DemoData
{
    private const FALLBACK_PATHS = ['', 'about', 'products', 'news', 'contact', 'jobs', 'blog', 'imprint'];
    private const LABELS = [
        Breakdown::CHANNEL => ['Search Engines' => 52, 'Direct Entry' => 24, 'Websites' => 13, 'Social Networks' => 8, 'Campaigns' => 3],
        Breakdown::DEVICES => ['Desktop' => 58, 'Smartphone' => 36, 'Tablet' => 5, 'Phablet' => 1],
        Breakdown::BROWSERS => ['Chrome' => 46, 'Safari' => 24, 'Firefox' => 12, 'Microsoft Edge' => 10, 'Samsung Internet' => 5, 'Opera' => 3],
        Breakdown::COUNTRIES => ['Germany' => 61, 'Austria' => 12, 'Switzerland' => 10, 'United States' => 7, 'Netherlands' => 6, 'France' => 4],
    ];
    /** Daily views of a page by the number of segments of its path */
    private const PAGE_HITS = [[25, 60], [5, 20], [3, 12]];
    private const DEEP_PAGE_HITS = [2, 6];

    public function __construct(
        private ?DemoPageUrls $pageUrls = null,
    ) {
    }

    /**
     * @param list<array<string, int|string>> $requests
     * @return list<mixed>
     */
    public function results(array $requests, MatomoConfiguration $configuration): array
    {
        $pageUrls = [];
        if (in_array('Actions.getPageUrls', array_column($requests, 'method'), true) || in_array('getPageUrls', array_column($requests, 'apiAction'), true)) {
            $pageUrls = $this->pageUrls?->get($configuration->idSite) ?? [];
            if ($pageUrls === []) {
                $pageUrls = array_map(static fn (string $path): string => rtrim($configuration->url, '/') . '/' . $path, self::FALLBACK_PATHS);
            }
        }
        return array_map(fn (array $request): mixed => $this->result($request, $configuration->url, $pageUrls), $requests);
    }

    /**
     * @param array<string, int|string> $request
     * @param list<string> $pageUrls
     */
    private function result(array $request, string $site, array $pageUrls): mixed
    {
        $dates = $this->dates((string)($request['date'] ?? ''));
        $granularity = (string)($request['period'] ?? '');
        if (in_array($granularity, ['day', 'week', 'month'], true)) {
            $result = [];
            $first = new \DateTimeImmutable($dates[0]);
            $last = new \DateTimeImmutable($dates[count($dates) - 1]);
            $first = match ($granularity) {
                'week' => $first->modify('monday this week'),
                'month' => $first->modify('first day of this month'),
                default => $first,
            };
            for ($date = $first; $date <= $last; $date = $date->modify('+1 ' . $granularity)) {
                $end = $date->modify('+1 ' . $granularity)->modify('-1 day');
                $key = $date->format('Y-m-d') . ($granularity === 'day' ? '' : ',' . $end->format('Y-m-d'));
                $result[$key] = $this->report($request, $this->dates($date->format('Y-m-d') . ',' . $end->format('Y-m-d')), $site, $pageUrls);
            }
            return $result;
        }
        return $this->report($request, $dates, $site, $pageUrls);
    }

    /**
     * @param array<string, int|string> $request
     * @param list<string> $dates
     * @param list<string> $pageUrls
     * @return array<mixed>
     */
    private function report(array $request, array $dates, string $site, array $pageUrls): array
    {
        $method = (string)($request['method'] ?? '');
        $visits = $this->sum($dates, fn (string $date): int => $this->visits($site, $date));

        return match ($method) {
            'API.get' => $this->visitsSummary($site, $dates, $visits) + [
                'nb_pageviews' => $this->pageViews($site, $dates),
                'nb_actions_per_visit' => $visits > 0 ? round($this->pageViews($site, $dates) / $visits, 2) : 0,
            ],
            'Live.getCounters' => [['visits' => $this->between('liveVisits' . $site, 2, 20), 'actions' => $this->between('liveActions' . $site, 30, 80), 'visitors' => $this->between('liveVisitors' . $site, 1, 10)]],
            'API.getProcessedReport' => $this->processedReport($request, $site, $dates, $pageUrls),
            'VisitsSummary.get' => $this->visitsSummary($site, $dates, $visits),
            'VisitFrequency.get' => ['nb_visits_returning' => intdiv($visits * 3, 10)],
            'Actions.get' => ['nb_pageviews' => $this->pageViews($site, $dates)],
            'Actions.getPageUrl' => [$this->pageRow($site, (string)($request['pageUrl'] ?? ''), $dates)],
            'Actions.getPageUrls' => $this->pageUrls($site, $pageUrls, $dates, (int)($request['filter_limit'] ?? -1)),
            Breakdown::CHANNEL, Breakdown::DEVICES, Breakdown::BROWSERS, Breakdown::COUNTRIES => $this->breakdown($site, $method, $dates),
            default => throw new MatomoApiException('The demo data have no answer for ' . $method, 1793960301),
        };
    }

    /**
     * @param array<string, int|string> $request
     * @param list<string> $dates
     * @param list<string> $pageUrls
     * @return array<string, mixed>
     */
    private function processedReport(array $request, string $site, array $dates, array $pageUrls): array
    {
        $method = (string)($request['apiModule'] ?? '') . '.' . (string)($request['apiAction'] ?? '');
        $isUrl = $method === 'Actions.getPageUrls';
        $rows = $isUrl ? $this->pageUrls($site, $pageUrls, $dates, -1) : $this->breakdown($site, $method, $dates);
        $columns = $isUrl ? ['label' => 'Page URL', 'nb_hits' => 'Page views', 'bounce_rate' => 'Bounce rate', 'avg_time_on_page' => 'Average time on page'] : ['label' => 'Type', 'nb_visits' => 'Visits'];
        $metadata = [];
        foreach ($rows as &$row) {
            $metadata[] = isset($row['url']) ? ['url' => $row['url']] : [];
            unset($row['url']);
        }
        unset($row);
        return [
            'metadata' => [
                'name' => $isUrl ? 'Page URLs' : (string)($request['apiModule'] ?? 'Report'),
                'metricTypes' => ['nb_hits' => 'number', 'nb_visits' => 'number', 'bounce_rate' => 'percent', 'avg_time_on_page' => 'duration_s'],
                'metricsDocumentation' => ['nb_hits' => 'Number of page views.', 'nb_visits' => 'Number of visits.'],
            ],
            'columns' => $columns, 'reportData' => $rows, 'reportMetadata' => $metadata,
            'reportTotal' => [$isUrl ? 'nb_hits' : 'nb_visits' => array_sum(array_column($rows, $isUrl ? 'nb_hits' : 'nb_visits'))],
        ];
    }

    /**
     * @param list<string> $dates
     * @return array<string, int|float>
     */
    private function visitsSummary(string $site, array $dates, int $visits): array
    {
        $bounceRate = $this->between('bounce' . $site, 30, 60);
        $bounces = $this->sum($dates, fn (string $date): int => intdiv($this->visits($site, $date) * $this->between('bounce' . $site . $date, $bounceRate - 8, $bounceRate + 8), 100));
        $length = $this->sum($dates, fn (string $date): int => $this->visits($site, $date) * $this->between('length' . $site . $date, 90, 240));

        return [
            'nb_uniq_visitors' => intdiv($visits * 4, 5),
            'nb_visits' => $visits,
            'nb_actions' => $this->pageViews($site, $dates),
            'bounce_count' => $bounces,
            'sum_visit_length' => $length,
            'bounce_rate' => $visits > 0 ? round($bounces / $visits, 2) : 0,
            'avg_time_on_site' => $visits > 0 ? intdiv($length, $visits) : 0,
        ];
    }

    /**
     * @param list<string> $dates
     * @return array<string, int|float|string>
     */
    private function pageRow(string $site, string $url, array $dates): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        [$min, $max] = self::PAGE_HITS[count(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''))] ?? self::DEEP_PAGE_HITS;
        // Some pages are more popular than others of the same depth
        $percent = $this->between('popularity' . $url, 30, 100) * $this->scale($site);
        $hits = $this->sum($dates, fn (string $date): int => intdiv($this->between($url . $date, $min, $max) * $percent + 5000, 10000));
        return [
            'label' => $path,
            'url' => $url,
            'nb_hits' => $hits,
            'bounce_rate' => $this->between('bounce' . $url, 20, 70) / 100,
            'avg_time_on_page' => $this->between('time' . $url, 20, 180),
            'exit_rate' => $this->between('exit' . $url, 15, 60) / 100,
        ];
    }

    /**
     * @param list<string> $pageUrls
     * @param list<string> $dates
     * @return list<array<string, int|float|string>>
     */
    private function pageUrls(string $site, array $pageUrls, array $dates, int $limit): array
    {
        $rows = array_map(fn (string $url): array => $this->pageRow($site, $url, $dates), $pageUrls);
        usort($rows, static fn (array $a, array $b): int => $b['nb_hits'] <=> $a['nb_hits']);
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * @param list<string> $dates
     * @return list<array{label: string, nb_visits: int}>
     */
    private function breakdown(string $site, string $method, array $dates): array
    {
        $rows = [];
        foreach (self::LABELS[$method] ?? ['First' => 55, 'Second' => 30, 'Other' => 15] as $label => $percent) {
            $visits = $this->sum($dates, fn (string $date): int => intdiv($this->visits($site, $date) * $percent * $this->between($label . $site . $date, 70, 130), 10000));
            $rows[] = ['label' => $label, 'nb_visits' => $visits];
        }
        usort($rows, static fn (array $a, array $b): int => $b['nb_visits'] <=> $a['nb_visits']);
        return $rows;
    }

    /**
     * @param list<string> $dates
     */
    private function pageViews(string $site, array $dates): int
    {
        return $this->sum($dates, fn (string $date): int => intdiv($this->visits($site, $date) * $this->between('actions' . $site . $date, 300, 450), 100));
    }

    private function visits(string $site, string $date): int
    {
        $weekend = (int)(new \DateTimeImmutable($date))->format('N') >= 6;
        return intdiv(($this->between('visits' . $site . $date, 90, 160) - ($weekend ? 40 : 0)) * $this->scale($site), 100);
    }

    /**
     * Traffic of a site in percent of an average one, so every site has its own numbers
     */
    private function scale(string $site): int
    {
        return $this->between('scale' . $site, 60, 250);
    }

    /**
     * @param list<string> $dates
     * @param \Closure(string): int $perDay
     */
    private function sum(array $dates, \Closure $perDay): int
    {
        return array_sum(array_map($perDay, $dates));
    }

    private function between(string $seed, int $min, int $max): int
    {
        return $min + crc32($seed) % ($max - $min + 1);
    }

    /**
     * @return list<string> the days of a Matomo date "Y-m-d" or "Y-m-d,Y-m-d"
     */
    private function dates(string $date): array
    {
        $parts = explode(',', $date);
        $day = new \DateTimeImmutable($parts[0]);
        $last = new \DateTimeImmutable($parts[1] ?? $parts[0]);
        $dates = [];
        for (; $day <= $last; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }
}
