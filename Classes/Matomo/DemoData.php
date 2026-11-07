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
    /**
     * Processed reports: name, dimension (empty for reports without one), metric set and the labels
     * with their share of the visits in percent, in Matomo's order
     */
    private const REPORTS = [
        'Referrers.getReferrerType' => ['Channel Type', 'Channel Type', 'visits', ['Search Engines' => 52, 'Direct Entry' => 24, 'Websites' => 13, 'Social Networks' => 8, 'Campaigns' => 3]],
        'UserCountry.getCountry' => ['Country', 'Country', 'visits', ['Germany' => 61, 'Austria' => 12, 'Switzerland' => 10, 'United States' => 7, 'Netherlands' => 6, 'France' => 4]],
        'UserCountry.getRegion' => ['Region', 'Region', 'visits', ['Saxony, Germany' => 24, 'Bavaria, Germany' => 18, 'North Rhine-Westphalia, Germany' => 15, 'Berlin, Germany' => 12, 'Vienna, Austria' => 9, 'Zurich, Switzerland' => 7, 'Unknown' => 15]],
        'UserCountry.getCity' => ['City', 'City', 'visits', ['Dresden, Saxony, Germany' => 17, 'Munich, Bavaria, Germany' => 12, 'Berlin, Berlin, Germany' => 12, 'Leipzig, Saxony, Germany' => 7, 'Vienna, Vienna, Austria' => 9, 'Cologne, North Rhine-Westphalia, Germany' => 8, 'Unknown' => 35]],
        'UserLanguage.getLanguage' => ['Web-browser language', 'Language', 'visits', ['German' => 68, 'English' => 21, 'French' => 4, 'Dutch' => 4, 'Italian' => 3]],
        'DevicesDetection.getType' => ['Device type', 'Device type', 'visits', ['Desktop' => 58, 'Smartphone' => 36, 'Tablet' => 5, 'Phablet' => 1]],
        'DevicesDetection.getBrand' => ['Device brand', 'Device brand', 'visits', ['Unknown' => 52, 'Apple' => 26, 'Samsung' => 11, 'Google' => 5, 'Xiaomi' => 4, 'Huawei' => 2]],
        'DevicesDetection.getModel' => ['Device model', 'Device model', 'visits', ['Generic Desktop' => 52, 'iPhone' => 21, 'Galaxy S24' => 7, 'iPad' => 5, 'Pixel 8' => 5, 'Generic Smartphone' => 10]],
        'Resolution.getResolution' => ['Screen Resolution', 'Resolution', 'visits', ['1920x1080' => 31, '390x844' => 18, '412x915' => 14, '1536x864' => 12, '2560x1440' => 10, '1366x768' => 8, '768x1024' => 7]],
        'DevicesDetection.getOsFamilies' => ['Operating System families', 'Operating system family', 'visits', ['Windows' => 41, 'iOS' => 24, 'Android' => 18, 'Mac' => 11, 'GNU/Linux' => 6]],
        'DevicesDetection.getBrowsers' => ['Browsers', 'Browser', 'visits', ['Chrome' => 46, 'Safari' => 24, 'Firefox' => 12, 'Microsoft Edge' => 10, 'Samsung Internet' => 5, 'Opera' => 3]],
        'DevicesDetection.getBrowserEngines' => ['Browser engines', 'Browser engine', 'visits', ['Blink (Chrome, Opera)' => 64, 'WebKit (Safari)' => 24, 'Gecko (Firefox)' => 12]],
        'VisitTime.getVisitInformationPerLocalTime' => ['Visits per local time', 'Local time — hour (start of visit)', 'visits', 'hours'],
        'VisitTime.getVisitInformationPerServerTime' => ['Visits per hour in the site\'s timezone', 'Site time — hour (start of visit)', 'visits', 'hours'],
        'VisitTime.getByDayOfWeek' => ['Visits by day of the week', 'Day of the week', 'visits', ['Monday' => 17, 'Tuesday' => 17, 'Wednesday' => 16, 'Thursday' => 16, 'Friday' => 14, 'Saturday' => 10, 'Sunday' => 10]],
        'VisitFrequency.get' => ['Returning Visits', '', 'frequency', []],
        'VisitorInterest.getNumberOfVisitsPerVisitDuration' => ['Length of visits', 'Visit duration', 'share', ['0–10s' => 38, '11–30s' => 12, '31–60s' => 10, '1–2 min' => 12, '2–4 min' => 11, '4–7 min' => 8, '7–10 min' => 4, '10–15 min' => 3, '15–30 min' => 2]],
        'VisitorInterest.getNumberOfVisitsPerPage' => ['Pages per visit', 'Pages per visit', 'share', ['1 page' => 44, '2 pages' => 18, '3 pages' => 12, '4 pages' => 8, '5 pages' => 6, '6–7 pages' => 5, '8–10 pages' => 4, '11–14 pages' => 2, '15–20 pages' => 1]],
        'VisitorInterest.getNumberOfVisitsByVisitCount' => ['Visits by visit number', 'Visits by visit number', 'share', ['1 visit' => 72, '2 visits' => 12, '3 visits' => 6, '4 visits' => 3, '5 visits' => 2, '6–7 visits' => 2, '8–10 visits' => 1, '11–14 visits' => 1, '15–25 visits' => 1]],
        'VisitorInterest.getNumberOfVisitsByDaysSinceLast' => ['Visits by days since last visit', 'Days since last visit', 'share', ['New visits' => 72, '0 days' => 9, '1 day' => 5, '2 days' => 3, '3 days' => 2, '4 days' => 2, '5–7 days' => 2, '8–14 days' => 2, '15–30 days' => 2, '31–60 days' => 1]],
    ];
    /** URL reports: name, dimension and columns */
    private const URL_REPORTS = [
        'Actions.getPageUrls' => ['Page URLs', 'Page URL', ['nb_hits' => 'Page views', 'bounce_rate' => 'Bounce rate', 'avg_time_on_page' => 'Average time on page']],
        'Actions.getEntryPageUrls' => ['Entry pages', 'Entry Page URL', ['entry_nb_visits' => 'Entrances', 'entry_bounce_count' => 'Bounces', 'bounce_rate' => 'Bounce Rate']],
        'Actions.getExitPageUrls' => ['Exit pages', 'Exit Page URL', ['exit_nb_visits' => 'Exits', 'nb_hits' => 'Pageviews', 'exit_rate' => 'Exit rate']],
    ];
    private const TIMINGS = [
        'avg_time_network' => 'Avg. network time', 'avg_time_server' => 'Avg. server time', 'avg_time_transfer' => 'Avg. transfer time',
        'avg_time_dom_processing' => 'Avg. DOM processing time', 'avg_time_dom_completion' => 'Avg. DOM completion time',
        'avg_time_on_load' => 'Avg. on load time', 'avg_page_load_time' => 'Avg. page load time',
    ];
    /** Metric sets of the processed reports: column => [name, Matomo metric type] */
    private const METRICS = [
        'visits' => ['nb_visits' => ['Visits', 'number'], 'nb_actions' => ['Actions', 'number'], 'nb_actions_per_visit' => ['Actions per Visit', 'number'], 'avg_time_on_site' => ['Avg. Time on Website', 'duration_s'], 'bounce_rate' => ['Bounce Rate', 'percent']],
        'share' => ['nb_visits' => ['Visits', 'number'], 'nb_visits_percent_of_total' => ['Visits (%)', 'percent']],
        'frequency' => [
            'nb_visits_new' => ['New Visits', 'number'], 'nb_visits_returning' => ['Returning Visits', 'number'],
            'nb_actions_per_visit_new' => ['Avg. Actions per New Visit', 'number'], 'nb_actions_per_visit_returning' => ['Avg. Actions per Returning Visit', 'number'],
            'avg_time_on_site_new' => ['Avg. Visit Duration for New Visits (in sec)', 'duration_s'], 'avg_time_on_site_returning' => ['Avg. Duration of a Returning Visit (in sec)', 'duration_s'],
            'bounce_rate_new' => ['Bounce Rate for New Visits', 'percent'], 'bounce_rate_returning' => ['Bounce Rate for Returning Visits', 'percent'],
        ],
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
        if (in_array('Actions.getPageUrls', array_column($requests, 'method'), true) || array_intersect(['getPageUrls', 'getEntryPageUrls', 'getExitPageUrls', 'getPageTitles'], array_column($requests, 'apiAction')) !== []) {
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
        if (isset(self::URL_REPORTS[$method])) {
            return $this->urlReport($method, $site, $dates, $pageUrls);
        }
        if ($method === 'Actions.getPageTitles') {
            return $this->titleReport($site, $dates, $pageUrls);
        }
        if ($method === 'PagePerformance.get') {
            $timings = $this->timings($site);
            return [
                'metadata' => ['name' => 'Performance overview', 'metricTypes' => array_fill_keys(array_keys(self::TIMINGS), 'duration_s')],
                'columns' => self::TIMINGS, 'reportData' => $timings, 'reportMetadata' => [], 'reportTotal' => [],
            ];
        }
        [$name, $dimension, $set] = self::REPORTS[$method] ?? throw new MatomoApiException('The demo data have no answer for ' . $method, 1793960302);
        $total = $this->sum($dates, fn (string $date): int => $this->visits($site, $date));
        $metadata = [
            'name' => $name,
            'metricTypes' => array_map(static fn (array $metric): string => $metric[1], self::METRICS[$set]),
            'metricsDocumentation' => ['nb_visits' => 'Number of visits.'],
        ];
        $columns = array_map(static fn (array $metric): string => $metric[0], self::METRICS[$set]);
        if ($dimension === '') {
            return ['metadata' => $metadata, 'columns' => $columns, 'reportData' => $this->metrics($set, $method . $site, $total, $total), 'reportMetadata' => [], 'reportTotal' => []];
        }
        $rows = [];
        foreach ($this->labels($method) as $label => $percent) {
            $label = (string)$label;
            $visits = $this->labelVisits($site, $label, $percent, $dates);
            $rows[] = ['label' => $label] + $this->metrics($set, $label . $site, $visits, $total);
        }
        return [
            'metadata' => $metadata, 'columns' => ['label' => $dimension] + $columns,
            'reportData' => $rows, 'reportMetadata' => array_fill(0, count($rows), []),
            'reportTotal' => ['nb_visits' => array_sum(array_column($rows, 'nb_visits'))],
        ];
    }

    /**
     * @param list<string> $dates
     * @param list<string> $pageUrls
     * @return array<string, mixed>
     */
    private function urlReport(string $method, string $site, array $dates, array $pageUrls): array
    {
        [$name, $dimension, $columns] = self::URL_REPORTS[$method];
        $rows = $this->pageUrls($site, $pageUrls, $dates, -1);
        $metadata = [];
        foreach ($rows as &$row) {
            $metadata[] = ['url' => $row['url']];
            $hits = (int)$row['nb_hits'];
            $entries = intdiv($hits * $this->between('entry' . $row['url'], 20, 70), 100);
            $row += [
                'entry_nb_visits' => $entries,
                'entry_bounce_count' => intdiv($entries * (int)round((float)$row['bounce_rate'] * 100), 100),
                'exit_nb_visits' => intdiv($hits * (int)round((float)$row['exit_rate'] * 100), 100),
                'nb_hits_with_time_network' => intdiv($hits * $this->between('measured' . $row['url'], 50, 95), 100),
            ] + $this->timings((string)$row['url']);
            unset($row['url']);
        }
        unset($row);
        return [
            'metadata' => [
                'name' => $name,
                'metricTypes' => ['nb_hits' => 'number', 'entry_nb_visits' => 'number', 'entry_bounce_count' => 'number', 'exit_nb_visits' => 'number', 'bounce_rate' => 'percent', 'exit_rate' => 'percent', 'avg_time_on_page' => 'duration_s'],
                'metricsDocumentation' => ['nb_hits' => 'Number of page views.'],
            ],
            'columns' => ['label' => $dimension] + $columns,
            'reportData' => $rows, 'reportMetadata' => $metadata,
            'reportTotal' => ['nb_hits' => array_sum(array_column($rows, 'nb_hits'))],
        ];
    }

    /**
     * @param list<string> $dates
     * @param list<string> $pageUrls
     * @return array<string, mixed>
     */
    private function titleReport(string $site, array $dates, array $pageUrls): array
    {
        $rows = [];
        foreach ($this->pageUrls($site, $pageUrls, $dates, -1) as $row) {
            $path = trim((string)$row['label'], '/');
            $title = $path === '' ? 'Home' : ucwords(str_replace(['-', '/'], [' ', ' › '], $path));
            $rows[] = ['label' => $title . ' – ' . (string)parse_url($site, PHP_URL_HOST), 'nb_hits' => $row['nb_hits'], 'bounce_rate' => $row['bounce_rate'], 'avg_time_on_page' => $row['avg_time_on_page'], 'exit_rate' => $row['exit_rate']];
        }
        return [
            'metadata' => ['name' => 'Page titles', 'metricTypes' => ['nb_hits' => 'number', 'bounce_rate' => 'percent', 'avg_time_on_page' => 'duration_s', 'exit_rate' => 'percent']],
            'columns' => ['label' => 'Page Title', 'nb_hits' => 'Pageviews', 'avg_time_on_page' => 'Avg. time on page', 'bounce_rate' => 'Bounce Rate', 'exit_rate' => 'Exit rate'],
            'reportData' => $rows, 'reportMetadata' => array_fill(0, count($rows), []),
            'reportTotal' => ['nb_hits' => array_sum(array_column($rows, 'nb_hits'))],
        ];
    }

    /**
     * @return array<string, float> average timings in seconds
     */
    private function timings(string $seed): array
    {
        $timings = [
            'avg_time_network' => $this->between('network' . $seed, 0, 40) / 100,
            'avg_time_server' => $this->between('server' . $seed, 10, 120) / 100,
            'avg_time_transfer' => $this->between('transfer' . $seed, 1, 20) / 100,
            'avg_time_dom_processing' => $this->between('domProcessing' . $seed, 10, 80) / 100,
            'avg_time_dom_completion' => $this->between('domCompletion' . $seed, 20, 150) / 100,
            'avg_time_on_load' => $this->between('onLoad' . $seed, 0, 5) / 100,
        ];
        return $timings + ['avg_page_load_time' => round(array_sum($timings), 2)];
    }

    /**
     * @return array<int|string, int> label => share of the visits in percent
     */
    private function labels(string $method): array
    {
        $labels = self::REPORTS[$method][3] ?? [];
        if (is_array($labels)) {
            return $labels;
        }
        $hours = [];
        for ($hour = 0; $hour < 24; $hour++) {
            // Most visits during the day
            $hours[sprintf('%02d', $hour)] = $hour >= 8 && $hour <= 20 ? 6 : 2;
        }
        return $hours;
    }

    /**
     * @return array<string, int|float>
     */
    private function metrics(string $set, string $seed, int $visits, int $total): array
    {
        $actions = intdiv($visits * $this->between('actions' . $seed, 110, 450), 100);
        $returning = intdiv($visits * 3, 10);
        return match ($set) {
            'visits' => [
                'nb_visits' => $visits, 'nb_actions' => $actions, 'nb_actions_per_visit' => $visits > 0 ? round($actions / $visits, 1) : 0,
                'avg_time_on_site' => $this->between('time' . $seed, 20, 320), 'bounce_rate' => $this->between('bounce' . $seed, 25, 75) / 100,
            ],
            'share' => ['nb_visits' => $visits, 'nb_visits_percent_of_total' => $total > 0 ? round($visits / $total, 3) : 0],
            'frequency' => [
                'nb_visits_new' => $visits - $returning, 'nb_visits_returning' => $returning,
                'nb_actions_per_visit_new' => $this->between('actionsNew' . $seed, 15, 35) / 10, 'nb_actions_per_visit_returning' => $this->between('actionsReturning' . $seed, 25, 60) / 10,
                'avg_time_on_site_new' => $this->between('timeNew' . $seed, 40, 200), 'avg_time_on_site_returning' => $this->between('timeReturning' . $seed, 120, 400),
                'bounce_rate_new' => $this->between('bounceNew' . $seed, 40, 70) / 100, 'bounce_rate_returning' => $this->between('bounceReturning' . $seed, 20, 45) / 100,
            ],
            default => throw new MatomoApiException('The demo data have no metrics ' . $set, 1793960303),
        };
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
        foreach ($this->labels($method) as $label => $percent) {
            $label = (string)$label;
            $rows[] = ['label' => $label, 'nb_visits' => $this->labelVisits($site, $label, $percent, $dates)];
        }
        usort($rows, static fn (array $a, array $b): int => $b['nb_visits'] <=> $a['nb_visits']);
        return $rows;
    }

    /**
     * @param list<string> $dates
     */
    private function labelVisits(string $site, string $label, int $percent, array $dates): int
    {
        return $this->sum($dates, fn (string $date): int => intdiv($this->visits($site, $date) * $percent * $this->between($label . $site . $date, 70, 130), 10000));
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
