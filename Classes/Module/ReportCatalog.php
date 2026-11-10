<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Report pages per section. A report may have its own title and columns (label keys of locallang_mod.xlf),
 * hide rows below a minimum, set a default sort column, explain an empty result (label key) and name the row
 * attribute with the country code whose flag precedes the label.
 * Optional pages are only offered when the site's Matomo has their first report; pages with a permission
 * only to users with that custom option, pages with an extension only when it is installed.
 *
 * @phpstan-type Definition array{module: string, action: string, params?: array<string, int|string>, rows?: string, title?: string, columns?: array<string, array{0: string, 1: string, 2?: string}>, minimum?: array{string, int}, sort?: string, empty?: string, truncated?: bool, flag?: string}
 */
final readonly class ReportCatalog
{
    /** Timing averages from fewer measured page views are skewed by single outliers */
    public const MINIMUM_TIMING_SAMPLES = 5;
    private const PAGES = [
        'visitors' => [
            'overview' => ['reports' => []],
            'sites' => ['reports' => []],
            'locations' => ['reports' => [
                ['module' => 'UserCountry', 'action' => 'getCountry', 'flag' => 'code'],
                ['module' => 'UserCountry', 'action' => 'getRegion', 'flag' => 'country'],
                ['module' => 'UserCountry', 'action' => 'getCity', 'flag' => 'country'],
                ['module' => 'UserLanguage', 'action' => 'getLanguage'],
            ]],
            'devices' => ['reports' => [
                ['module' => 'DevicesDetection', 'action' => 'getType'],
                ['module' => 'DevicesDetection', 'action' => 'getBrand'],
                ['module' => 'DevicesDetection', 'action' => 'getModel'],
                ['module' => 'Resolution', 'action' => 'getResolution'],
            ]],
            'software' => ['reports' => [
                ['module' => 'DevicesDetection', 'action' => 'getOsFamilies'],
                ['module' => 'DevicesDetection', 'action' => 'getBrowsers'],
                ['module' => 'DevicesDetection', 'action' => 'getBrowserEngines'],
            ]],
            'times' => ['reports' => [
                ['module' => 'VisitTime', 'action' => 'getVisitInformationPerLocalTime'],
                ['module' => 'VisitTime', 'action' => 'getVisitInformationPerServerTime'],
                ['module' => 'VisitTime', 'action' => 'getByDayOfWeek'],
            ]],
            'engagement' => ['reports' => [
                ['module' => 'VisitFrequency', 'action' => 'get'],
                ['module' => 'VisitorInterest', 'action' => 'getNumberOfVisitsPerVisitDuration'],
                ['module' => 'VisitorInterest', 'action' => 'getNumberOfVisitsPerPage'],
                ['module' => 'VisitorInterest', 'action' => 'getNumberOfVisitsByVisitCount'],
                ['module' => 'VisitorInterest', 'action' => 'getNumberOfVisitsByDaysSinceLast'],
            ]],
        ],
        'behaviour' => [
            'pages' => ['reports' => [['module' => 'Actions', 'action' => 'getPageUrls', 'params' => ['flat' => 1], 'rows' => 'url']]],
            // Compared in TrendingService; Matomo has no processed report for it
            'trending' => ['reports' => []],
            // Joined with the page tree in ContentReviewService; an admin task, so behind its own option
            'review' => ['permission' => StatisticsPermission::CONTENT_REVIEW, 'reports' => []],
            'entryPages' => ['reports' => [['module' => 'Actions', 'action' => 'getEntryPageUrls', 'params' => ['flat' => 1], 'rows' => 'url']]],
            'exitPages' => ['reports' => [['module' => 'Actions', 'action' => 'getExitPageUrls', 'params' => ['flat' => 1], 'rows' => 'url']]],
            'pageTitles' => ['reports' => [['module' => 'Actions', 'action' => 'getPageTitles', 'params' => ['flat' => 1]]]],
            // Page titles of the 404 page, joined with the page tree in NotFoundService; suggests sys_redirect records
            'notFound' => ['extension' => 'redirects', 'reports' => []],
            // Search keywords can contain personal data
            'siteSearch' => ['permission' => StatisticsPermission::SITE_SEARCH, 'reports' => [
                ['module' => 'Actions', 'action' => 'getSiteSearchKeywords', 'empty' => 'empty.siteSearch'],
                ['module' => 'Actions', 'action' => 'getSiteSearchNoResultKeywords', 'empty' => 'empty.siteSearchNoResults'],
                ['module' => 'Actions', 'action' => 'getSiteSearchCategories', 'empty' => 'empty.siteSearch'],
            ]],
            'performance' => ['reports' => [
                ['module' => 'PagePerformance', 'action' => 'get'],
                [
                    'module' => 'Actions', 'action' => 'getPageUrls', 'params' => ['flat' => 1, 'showRawMetrics' => 1], 'rows' => 'url',
                    'title' => 'report.slowestPages',
                    // Timing columns are raw metrics of the pages report
                    'columns' => [
                        'nb_hits' => ['column.nb_hits', 'number'],
                        'avg_page_load_time' => ['column.avg_page_load_time', 'duration_s'],
                        'avg_time_network' => ['column.avg_time_network', 'duration_s'],
                        'avg_time_server' => ['column.avg_time_server', 'duration_s'],
                        'avg_time_transfer' => ['column.avg_time_transfer', 'duration_s'],
                        'avg_time_dom_processing' => ['column.avg_time_dom_processing', 'duration_s'],
                        'avg_time_dom_completion' => ['column.avg_time_dom_completion', 'duration_s'],
                        'avg_time_on_load' => ['column.avg_time_on_load', 'duration_s'],
                        'nb_hits_with_time_network' => ['column.nb_hits_with_time_network', 'number'],
                    ],
                    'minimum' => ['nb_hits_with_time_network', self::MINIMUM_TIMING_SAMPLES],
                    'sort' => 'avg_page_load_time',
                ],
            ]],
            'outlinks' => ['reports' => [['module' => 'Actions', 'action' => 'getOutlinks', 'params' => ['flat' => 1], 'rows' => 'link', 'empty' => 'empty.linkTracking']]],
            'downloads' => ['reports' => [['module' => 'Actions', 'action' => 'getDownloads', 'params' => ['flat' => 1], 'rows' => 'link', 'empty' => 'empty.linkTracking']]],
            'events' => ['reports' => [
                ['module' => 'Events', 'action' => 'getCategory', 'params' => ['flat' => 1], 'empty' => 'empty.eventTracking'],
                ['module' => 'Events', 'action' => 'getAction', 'params' => ['flat' => 1], 'empty' => 'empty.eventTracking'],
                ['module' => 'Events', 'action' => 'getName', 'params' => ['flat' => 1], 'empty' => 'empty.eventTracking'],
            ]],
            'contents' => ['reports' => [
                ['module' => 'Contents', 'action' => 'getContentNames', 'params' => ['flat' => 1], 'empty' => 'empty.contentTracking'],
                ['module' => 'Contents', 'action' => 'getContentPieces', 'params' => ['flat' => 1], 'empty' => 'empty.contentTracking'],
            ]],
        ],
        'acquisition' => [
            'channels' => ['reports' => [['module' => 'Referrers', 'action' => 'getReferrerType'], ['module' => 'Referrers', 'action' => 'getAll']]],
            'websites' => ['reports' => [['module' => 'Referrers', 'action' => 'getWebsites']]],
            'searchEngines' => ['reports' => [['module' => 'Referrers', 'action' => 'getSearchEngines'], ['module' => 'Referrers', 'action' => 'getKeywords']]],
            'socialNetworks' => ['reports' => [['module' => 'Referrers', 'action' => 'getSocials']]],
            // Newer Matomo versions only
            'aiAssistants' => ['optional' => true, 'reports' => [['module' => 'Referrers', 'action' => 'getAIAssistants']]],
            // The MarketingCampaignsReporting plugin replaces the core report
            'campaigns' => ['optional' => true, 'reports' => [
                ['module' => 'Referrers', 'action' => 'getCampaigns'],
                ['module' => 'Referrers', 'action' => 'getCampaigns', 'params' => ['flat' => 1], 'title' => 'report.campaignKeywords'],
            ]],
        ],
    ];

    /** @return array<string, string> the pages the user may see */
    public function getPages(string $section, BackendUserAuthentication $user): array
    {
        $pages = [];
        foreach (self::PAGES[$section] ?? [] as $page => $definition) {
            if (isset($definition['permission']) && !StatisticsPermission::isGranted($user, $definition['permission'])) {
                continue;
            }
            if (isset($definition['extension']) && !ExtensionManagementUtility::isLoaded($definition['extension'])) {
                continue;
            }
            $pages[$page] = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang_mod.xlf:report.' . $page;
        }
        return $pages;
    }

    /**
     * @return array<string, Definition> pages whose first report not every Matomo offers
     */
    public function getOptionalPages(string $section): array
    {
        $pages = array_filter(self::PAGES[$section] ?? [], static fn (array $page): bool => $page['optional'] ?? false);
        return array_map(static fn (array $page): array => $page['reports'][0], $pages);
    }

    /** @return list<Definition> */
    public function getReports(string $section, string $page): array
    {
        return self::PAGES[$section][$page]['reports'] ?? [];
    }
}
