<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Matomo;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;

/**
 * Generated Reporting API answers for trying out the extension without a Matomo
 * instance. Numbers depend only on site, method, URL and date, so they stay the
 * same on every reload and a range is the sum of its days. Top pages are the
 * pages of the site; deeper pages get fewer views.
 */
final readonly class DemoData
{
    public const VERSION = '5.4.0';
    private const STATUS_METHODS = [
        'API.getMatomoVersion', 'SitesManager.getSiteFromId', 'SitesManager.getSiteUrlsFromId', 'Live.getLastVisitsDetails',
        'SitesManager.getSitesIdWithAdminAccess', 'SitesManager.getSitesIdWithAtLeastViewAccess', 'UsersManager.getSitesAccessFromUser',
    ];
    private const FALLBACK_PATHS = ['', 'about', 'products', 'news', 'contact', 'jobs', 'blog', 'imprint'];
    /** Codes Matomo attaches to location rows, keyed by the country name ending a demo location label */
    private const COUNTRY_CODES = ['Germany' => 'de', 'Austria' => 'at', 'Switzerland' => 'ch', 'United States' => 'us', 'Netherlands' => 'nl', 'France' => 'fr', 'Unknown' => 'xx'];
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
        'Actions.getOutlinks' => ['Outlinks', 'Clicked Outlink', 'clicks', ['typo3.org/' => 6, 'matomo.org/docs/' => 4, 'github.com/maikschneider/analytics-matomo' => 3, 'www.linkedin.com/company/' => 2, 'www.openstreetmap.org/' => 1]],
        'Actions.getDownloads' => ['Downloads', 'Download URL', 'clicks', ['fileadmin/downloads/product-sheet.pdf' => 5, 'fileadmin/downloads/price-list-2026.pdf' => 3, 'fileadmin/downloads/terms.pdf' => 2, 'fileadmin/press/logo-package.zip' => 1]],
        'Events.getCategory' => ['Event Categories', 'Event Category', 'events', ['Video' => 9, 'Newsletter' => 4, 'Contact form' => 3, 'Accordion' => 2]],
        'Events.getAction' => ['Event Actions', 'Event Action', 'events', ['play' => 7, 'open' => 5, 'submit' => 4, 'pause' => 2]],
        'Events.getName' => ['Event Names', 'Event Name', 'events', ['Image film' => 7, 'Footer sign-up' => 4, 'Contact page' => 3, 'FAQ' => 2]],
        'Contents.getContentNames' => ['Content Name', 'Content Name', 'contents', ['Hero banner' => 40, 'Newsletter teaser' => 22, 'Job offers' => 9]],
        'Contents.getContentPieces' => ['Content Piece', 'Content Piece', 'contents', ['fileadmin/hero/spring.jpg' => 32, 'Sign up now' => 22, 'fileadmin/hero/team.jpg' => 8, 'We are hiring' => 9]],
        'Actions.getSiteSearchKeywords' => ['Site Search Keywords', 'Keyword', 'searches', ['opening hours' => 4, 'jobs' => 3, 'contact' => 2, 'price list' => 1, 'typo3' => 1]],
        'Actions.getSiteSearchNoResultKeywords' => ['Search Keywords with No Results', 'Keyword with No Search Result', 'searches', ['parking' => 1, 'gift card' => 1]],
        'Actions.getSiteSearchCategories' => ['Search Categories', 'Search Category', 'searches', ['News' => 5, 'Products' => 3, 'Jobs' => 2]],
        'Referrers.getAll' => ['All Channels', 'Referrer', 'visits', ['Keyword not defined' => 38, 'typo3.org' => 6, 'ChatGPT' => 4, 'LinkedIn' => 3, 'newsletter' => 3, 'xing.com' => 2]],
        'Referrers.getWebsites' => ['Websites', 'Website', 'visits', ['typo3.org' => 6, 'docs.typo3.org' => 3, 'xing.com' => 2, 'dresden.de' => 1, 'example-partner.de' => 1]],
        'Referrers.getSearchEngines' => ['Search Engines', 'Search Engine', 'visits', ['Google' => 44, 'Bing' => 4, 'DuckDuckGo' => 2, 'Ecosia' => 1, 'Qwant' => 1]],
        'Referrers.getKeywords' => ['Keywords', 'Keyword', 'visits', ['Keyword not defined' => 50, 'typo3 agency dresden' => 1, 'matomo typo3' => 1]],
        'Referrers.getSocials' => ['Social Networks', 'Social Network', 'visits', ['LinkedIn' => 4, 'Mastodon' => 2, 'Facebook' => 1, 'YouTube' => 1]],
        'Referrers.getAIAssistants' => ['AI Assistants', 'AI Assistant', 'visits', ['ChatGPT' => 3, 'Perplexity' => 1, 'Gemini' => 1, 'Copilot' => 1]],
        'Referrers.getCampaigns' => ['Campaigns', 'Campaign', 'visits', ['newsletter' => 3, 'spring-sale' => 2, 'job-ads' => 1]],
        'Referrers.getCampaigns.flat' => ['Campaigns', 'Campaign', 'visits', ['newsletter - november' => 2, 'newsletter - october' => 1, 'spring-sale - social' => 1, 'spring-sale - Keyword not defined' => 1, 'job-ads - stepstone' => 1]],
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
        'clicks' => ['nb_visits' => ['Unique Clicks', 'number'], 'nb_hits' => ['Clicks', 'number']],
        'events' => ['nb_events' => ['Events', 'number'], 'nb_visits' => ['Visits', 'number'], 'sum_event_value' => ['Event value', 'number'], 'avg_event_value' => ['The average of all values for this event', 'number']],
        'contents' => ['nb_impressions' => ['Impressions', 'number'], 'nb_interactions' => ['Content Interactions', 'number'], 'interaction_rate' => ['Interaction Rate', 'percent']],
        'searches' => ['nb_visits' => ['Searches', 'number'], 'exit_rate' => ['% Search Exits', 'percent']],
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
    /** Where visitors of a page come from: label, share of the entries in percent and details with their share */
    /** Goals: name, conversions per 1000 visits from and to, revenue per conversion */
    private const GOALS = [
        1 => ['Booking request', 8, 22, 40],
        2 => ['Newsletter signup', 3, 12, 0],
    ];
    private const TRANSITION_REFERRERS = [
        'search' => ['From search engines', 45, ['Google' => 78, 'Bing' => 12, 'DuckDuckGo' => 7]],
        'direct' => ['Direct entries', 30, []],
        'website' => ['From websites', 15, ['typo3.org' => 60, 'github.com' => 40]],
        'social' => ['From social networks', 10, ['LinkedIn' => 70, 'Mastodon' => 30]],
    ];
    /** Stored segments: name, definition, pre-processed by archiving, share of the visits in percent */
    private const SEGMENTS = [
        1 => ['Mobile users', 'deviceType==Smartphone,deviceType==Tablet', true, 41],
        2 => ['Returning visitors', 'visitorType==returning', true, 30],
        3 => ['New visitors', 'visitorType==new', false, 70],
    ];

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
        if (array_intersect(['Actions.getPageUrls', 'Actions.getEntryPageUrls', 'Transitions.getTransitionsForAction', 'Annotations.getAll'], array_column($requests, 'method')) !== [] || array_intersect(['getPageUrls', 'getEntryPageUrls', 'getExitPageUrls', 'getPageTitles'], array_column($requests, 'apiAction')) !== []) {
            $pageUrls = $this->pageUrls?->get($configuration->idSite) ?? [];
            if ($pageUrls === []) {
                $pageUrls = array_map(static fn (string $path): string => rtrim($configuration->url, '/') . '/' . $path, self::FALLBACK_PATHS);
            }
        }
        // A segment is the same site with its own seed and a smaller share of the traffic
        $site = $configuration->url . ($configuration->segment === '' ? '' : '#' . $configuration->segment);
        return array_map(fn (array $request): mixed => in_array($request['method'] ?? '', self::STATUS_METHODS, true) ? $this->status((string)$request['method'], $configuration) : $this->result($request, $site, $pageUrls), $requests);
    }

    /**
     * Answers of the connection check: a healthy site with a view-only token
     */
    private function status(string $method, MatomoConfiguration $configuration): mixed
    {
        return match ($method) {
            'API.getMatomoVersion' => ['value' => self::VERSION],
            'SitesManager.getSiteFromId' => [
                'idsite' => $configuration->idSite, 'main_url' => $configuration->url, 'ts_created' => '2024-01-15 09:00:00',
                'timezone' => 'Europe/Berlin', 'exclude_unknown_urls' => 1, 'sitesearch' => 1,
            ],
            'SitesManager.getSiteUrlsFromId' => [$configuration->url],
            'Live.getLastVisitsDetails' => [['lastActionTimestamp' => time() - 420]],
            'SitesManager.getSitesIdWithAdminAccess' => [],
            'SitesManager.getSitesIdWithAtLeastViewAccess' => [$configuration->idSite],
            'UsersManager.getSitesAccessFromUser' => ['result' => 'error', 'message' => "You can't access this resource as it requires a 'superuser' access."],
            default => throw new MatomoApiException('The demo data have no answer for ' . $method, 1793960302),
        };
    }

    /**
     * @param array<string, int|string> $request
     * @param list<string> $pageUrls
     */
    private function result(array $request, string $site, array $pageUrls): mixed
    {
        if (($request['method'] ?? '') === 'Annotations.getAll') {
            return [$this->annotations((string)($request['date'] ?? ''), $site, $pageUrls)];
        }
        if (($request['method'] ?? '') === 'SegmentEditor.getAll') {
            return array_map(static fn (int $id, array $segment): array => [
                'idsegment' => $id, 'name' => $segment[0], 'definition' => $segment[1],
                'enable_only_idsite' => 0, 'auto_archive' => (int)$segment[2], 'deleted' => 0,
            ], array_keys(self::SEGMENTS), self::SEGMENTS);
        }
        if (($request['method'] ?? '') === 'VisitsSummary.get' && ($request['date'] ?? '') === 'previous29') {
            $last = new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Berlin'));
            $series = [];
            for ($offset = 28; $offset >= 0; $offset--) {
                $series[$last->modify('-' . $offset . ' days')->format('Y-m-d')] = [
                    'nb_visits' => $offset === 0 ? ($this->between('anomaly' . $site, 0, 1) === 0 ? 60 : 400) : 200,
                    'bounce_rate' => 0.4,
                ];
            }
            return $series;
        }
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
            // The demo Matomo offers every report of the catalog
            'API.getMetadata' => [['module' => (string)($request['apiModule'] ?? ''), 'action' => (string)($request['apiAction'] ?? '')]],
            'VisitsSummary.get' => $this->visitsSummary($site, $dates, $visits),
            'VisitFrequency.get' => ['nb_visits_returning' => intdiv($visits * 3, 10)],
            'Actions.get' => ['nb_pageviews' => $this->pageViews($site, $dates)],
            'Actions.getPageUrl' => [$this->pageRow($site, (string)($request['pageUrl'] ?? ''), $dates)],
            'Actions.getPageUrls' => array_map(fn (array $row): array => $row + ['goals' => $this->pageGoals($site, (string)$row['url'], $dates)], $this->filter($request, array_map($this->withTimings(...), $this->pageUrls($site, $pageUrls, $dates, (int)($request['filter_limit'] ?? -1))))),
            'Transitions.getTransitionsForAction' => $this->transitions($site, (string)($request['actionName'] ?? ''), $pageUrls, $dates),
            'Goals.getGoals' => array_map(static fn (int $id, array $goal): array => ['idgoal' => $id, 'name' => $goal[0], 'revenue' => $goal[3]], array_keys(self::GOALS), self::GOALS),
            'Goals.get' => $this->goal($site, $dates, $visits, isset($request['idGoal']) ? [(int)$request['idGoal']] : array_keys(self::GOALS)),
            'Referrers.getReferrerType' => $this->goalRows($site, $dates, (int)($request['idGoal'] ?? 0), self::REPORTS[$method][3], 'nb_visits', 'nb_conversions', fn (string $label, int $share): int => $this->labelVisits($site, $label, $share, $dates)),
            'Actions.getEntryPageUrls' => $this->goalRows($site, $dates, (int)($request['idGoal'] ?? 0), array_fill_keys($pageUrls, 0), 'entry_nb_visits', 'nb_conversions_entry', fn (string $url): int => intdiv((int)$this->pageRow($site, $url, $dates)['nb_hits'] * $this->between('entries' . $url, 30, 60), 100)),
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
        if (isset(self::REPORTS[$method . '.flat']) && (int)($request['flat'] ?? 0) === 1) {
            $method .= '.flat';
        }
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
        $rowMetadata = [];
        foreach ($this->labels($method) as $label => $percent) {
            $label = (string)$label;
            $visits = $this->labelVisits($site, $label, $percent, $dates);
            $rows[] = ['label' => $label] + $this->metrics($set, $label . $site, $visits, $total);
            $parts = explode(', ', $label);
            $code = self::COUNTRY_CODES[end($parts)] ?? '';
            $rowMetadata[] = match (true) {
                !str_starts_with($method, 'UserCountry.') => [],
                $method === 'UserCountry.getCountry' => ['code' => $code],
                default => ['country' => $code],
            };
        }
        return [
            'metadata' => $metadata, 'columns' => ['label' => $dimension] + $columns,
            'reportData' => $rows, 'reportMetadata' => $rowMetadata,
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
            $row = $this->withTimings($row + [
                'entry_nb_visits' => $entries,
                'entry_bounce_count' => intdiv($entries * (int)round((float)$row['bounce_rate'] * 100), 100),
                'exit_nb_visits' => intdiv($hits * (int)round((float)$row['exit_rate'] * 100), 100),
            ]);
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
     * @param array<string, int|float|string> $row page URL row with url and nb_hits
     * @return array<string, int|float|string>
     */
    private function withTimings(array $row): array
    {
        $measured = intdiv((int)$row['nb_hits'] * $this->between('measured' . $row['url'], 50, 95), 100);
        return $row + ['nb_hits_with_time_network' => $measured, 'nb_hits_with_time_server' => $measured] + $this->timings((string)$row['url']);
    }

    /**
     * Matomo's generic pattern filter, which page details use to pick one URL from the page URL report
     *
     * @param array<string, int|string> $request
     * @param list<array<string, int|float|string>> $rows
     * @return list<array<string, int|float|string>>
     */
    private function filter(array $request, array $rows): array
    {
        if (!isset($request['filter_column'], $request['filter_pattern'])) {
            return $rows;
        }
        $pattern = '/' . str_replace('/', '\/', (string)$request['filter_pattern']) . '/i';
        // Matomo stores absolute URLs; demo rows of a site with a relative base have no host
        return array_values(array_filter($rows, static fn (array $row): bool => preg_match($pattern, (string)preg_replace('#^/#', 'https://localhost/', (string)($row[(string)$request['filter_column']] ?? ''))) === 1));
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
        $interactions = intdiv($visits * $this->between('interactions' . $seed, 2, 12), 100);
        $returning = intdiv($visits * 3, 10);
        return match ($set) {
            'visits' => [
                'nb_visits' => $visits, 'nb_actions' => $actions, 'nb_actions_per_visit' => $visits > 0 ? round($actions / $visits, 1) : 0,
                'avg_time_on_site' => $this->between('time' . $seed, 20, 320), 'bounce_rate' => $this->between('bounce' . $seed, 25, 75) / 100,
            ],
            'share' => ['nb_visits' => $visits, 'nb_visits_percent_of_total' => $total > 0 ? round($visits / $total, 3) : 0],
            'clicks' => ['nb_visits' => $visits, 'nb_hits' => intdiv($visits * $this->between('clicks' . $seed, 100, 140), 100)],
            'events' => [
                'nb_events' => intdiv($visits * $this->between('events' . $seed, 100, 180), 100), 'nb_visits' => $visits,
                'sum_event_value' => $visits * 2, 'avg_event_value' => 2,
            ],
            'contents' => ['nb_impressions' => $visits, 'nb_interactions' => $interactions, 'interaction_rate' => $visits > 0 ? round($interactions / $visits, 3) : 0],
            'searches' => ['nb_visits' => $visits, 'exit_rate' => $this->between('searchExit' . $seed, 10, 60) / 100],
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
     * Previous and next pages are other pages of the site, labelled without scheme like Matomo does
     *
     * @param list<string> $pageUrls
     * @param list<string> $dates
     * @return array<string, mixed>
     */
    private function transitions(string $site, string $url, array $pageUrls, array $dates): array
    {
        $views = (int)$this->pageRow($site, $url, $dates)['nb_hits'];
        $entries = intdiv($views * $this->between('entries' . $url, 20, 50), 100);
        $pages = function (string $direction) use ($url, $pageUrls, $views): array {
            $rows = [];
            foreach (array_slice(array_values(array_diff($pageUrls, [$url])), 0, 6) as $other) {
                $rows[] = ['label' => (string)preg_replace('#^https?://#', '', $other), 'referrals' => max(1, intdiv($views * $this->between($direction . $url . $other, 2, 15), 100))];
            }
            usort($rows, static fn (array $a, array $b): int => $b['referrals'] <=> $a['referrals']);
            return $rows;
        };
        $clicks = function (string $method, int $min) use ($url, $views): array {
            $labels = self::REPORTS[$method][3];
            $rows = [];
            foreach (array_slice(is_array($labels) ? array_keys($labels) : [], 0, $this->between($method . $url, $min, 3)) as $label) {
                $rows[] = ['label' => $label, 'referrals' => intdiv($views * $this->between($method . $url . $label, 1, 8) + 99, 100)];
            }
            return $rows;
        };
        $referrers = [];
        foreach (self::TRANSITION_REFERRERS as $shortName => [$label, $percent, $details]) {
            $visits = intdiv($entries * $percent, 100);
            $referrers[] = [
                'label' => $label, 'shortName' => $shortName, 'visits' => $visits,
                'details' => array_map(static fn (string $detail, int $share): array => ['label' => $detail, 'referrals' => (string)intdiv($visits * $share, 100)], array_keys($details), $details),
            ];
        }
        return [
            'previousPages' => $pages('previous'),
            'followingPages' => $pages('following'),
            'outlinks' => $clicks('Actions.getOutlinks', 1),
            'downloads' => $clicks('Actions.getDownloads', 0),
            'referrers' => $referrers,
            'pageMetrics' => ['loops' => intdiv($views, 20), 'pageviews' => $views, 'entries' => $entries, 'exits' => intdiv($views * $this->between('exit' . $url, 15, 60), 100)],
        ];
    }

    /**
     * @param list<string> $dates
     * @param list<int> $goalIds
     * @return array<string, int|float>
     */
    private function goal(string $site, array $dates, int $visits, array $goalIds): array
    {
        $conversions = 0;
        $revenue = 0;
        foreach ($goalIds as $id) {
            $goalConversions = $this->conversions($site, $dates, $id);
            $conversions += $goalConversions;
            $revenue += $goalConversions * self::GOALS[$id][3];
        }
        return [
            'nb_conversions' => $conversions, 'nb_visits_converted' => $conversions, 'revenue' => $revenue,
            'conversion_rate' => $visits > 0 ? round($conversions / $visits, 4) : 0,
        ];
    }

    /**
     * Rows of a report by goal: the conversions of the goal are spread over the rows by their visits
     *
     * @param list<string> $dates
     * @param array<string, int> $labels label => share of the visits in percent
     * @param \Closure(string, int): int $rowVisits
     * @return list<array<string, mixed>>
     */
    private function goalRows(string $site, array $dates, int $goalId, array $labels, string $visitsColumn, string $conversionsColumn, \Closure $rowVisits): array
    {
        $visits = [];
        foreach ($labels as $label => $share) {
            $visits[$label] = $rowVisits((string)$label, $share);
        }
        $total = $this->conversions($site, $dates, $goalId);
        $sum = max(1, array_sum($visits));
        $conversions = array_map(static fn (int $rowVisits): int => intdiv($total * $rowVisits, $sum), $visits);
        $conversions[array_key_first($conversions)] += $total - array_sum($conversions);
        $rows = [];
        foreach ($visits as $label => $rowVisits) {
            $goal = [$conversionsColumn => $conversions[$label], 'nb_conversions' => $conversions[$label], 'revenue' => $conversions[$label] * self::GOALS[$goalId][3]];
            $row = str_contains((string)$label, '://') ? ['label' => (string)parse_url((string)$label, PHP_URL_PATH) ?: '/', 'url' => $label] : ['label' => $label];
            $rows[] = $row + [$visitsColumn => $rowVisits, 'goals' => ['idgoal=' . $goalId => $goal]];
        }
        return $rows;
    }

    /**
     * Conversions after a view of the page: a share of each goal's conversions
     *
     * @param list<string> $dates
     * @return array<string, array<string, int|float>>
     */
    private function pageGoals(string $site, string $url, array $dates): array
    {
        $goals = [];
        foreach (array_keys(self::GOALS) as $id) {
            $viewedBefore = intdiv($this->conversions($site, $dates, $id) * $this->between('viewedBefore' . $id . $url, 20, 70), 100);
            $goals['idgoal=' . $id] = [
                'nb_conversions_page_uniq' => $viewedBefore,
                'nb_conversions_attrib' => round($viewedBefore * $this->between('attributed' . $id . $url, 30, 90) / 100, 2),
                'nb_conversions_entry' => intdiv($viewedBefore * $this->between('entry' . $id . $url, 10, 50), 100),
            ];
        }
        return $goals;
    }

    /**
     * @param list<string> $dates
     */
    private function conversions(string $site, array $dates, int $goalId): int
    {
        [, $min, $max] = self::GOALS[$goalId];
        return $this->sum($dates, fn (string $date): int => intdiv($this->visits($site, $date) * $this->between('goal' . $goalId . $site . $date, $min, $max), 1000));
    }

    /**
     * @param list<string> $dates
     * @return array<string, int|float|string>
     */
    private function pageRow(string $site, string $url, array $dates): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        [$min, $max] = self::PAGE_HITS[count(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''))] ?? self::DEEP_PAGE_HITS;
        // Some pages are more popular than others of the same depth, and some get no views at all
        $percent = trim($path, '/') !== '' && $this->between('unvisited' . $url, 0, 6) === 0 ? 0 : $this->between('popularity' . $url, 30, 100) * $this->scale($site);
        // A weekly momentum per page, so pages rise and fall between periods
        $hits = $this->sum($dates, fn (string $date): int => intdiv($this->between($url . $date, $min, $max) * $percent * $this->between('momentum' . $url . (new \DateTimeImmutable($date))->format('oW'), 60, 150) + 500000, 1000000));
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
        // Matomo lists only pages with views
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['nb_hits'] > 0));
        usort($rows, static fn (array $a, array $b): int => $b['nb_hits'] <=> $a['nb_hits']);
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
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
        [$base, $segment] = explode('#', $site, 2) + [1 => ''];
        $share = 100;
        foreach (self::SEGMENTS as [, $definition, , $segmentShare]) {
            $share = $definition === $segment ? $segmentShare : $share;
        }
        return intdiv($this->between('scale' . $base, 60, 250) * $share, 100);
    }

    /**
     * @param list<string> $dates
     * @param \Closure(string): int $perDay
     */
    private function sum(array $dates, \Closure $perDay): int
    {
        return array_sum(array_map($perDay, $dates));
    }

    /**
     * Changes of every third page and a campaign of the site within the last two weeks before today,
     * in the shape of Annotations.getAll
     *
     * @param list<string> $pageUrls
     * @return list<array{date: string, note: string, starred: int, user: string}>
     */
    private function annotations(string $date, string $site, array $pageUrls): array
    {
        $today = new \DateTimeImmutable('today');
        $notes = [$today->modify('-9 days')->format('Y-m-d') => ['Newsletter sent']];
        foreach ($pageUrls as $index => $url) {
            if ($index % 3 === 0) {
                $notes[$today->modify('-' . $this->between('annotation' . $site . $url, 3, 13) . ' days')->format('Y-m-d')][] = 'TYPO3: ' . $url . ' edited by editor';
            }
        }
        $days = array_flip($this->dates($date));
        ksort($notes);
        $annotations = [];
        foreach ($notes as $day => $texts) {
            foreach (isset($days[$day]) ? $texts : [] as $text) {
                $annotations[] = ['date' => $day, 'note' => $text, 'starred' => 0, 'user' => 'typo3'];
            }
        }
        return $annotations;
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
