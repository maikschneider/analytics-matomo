<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

/** @phpstan-type Definition array{module: string, action: string, params?: array<string, int|string>, rows?: string} */
final readonly class ReportCatalog
{
    private const PAGES = [
        'visitors' => [
            'overview' => ['reports' => []],
            'locations' => ['reports' => [
                ['module' => 'UserCountry', 'action' => 'getCountry'],
                ['module' => 'UserCountry', 'action' => 'getRegion'],
                ['module' => 'UserCountry', 'action' => 'getCity'],
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
        ],
        'acquisition' => [
            'channels' => ['reports' => [['module' => 'Referrers', 'action' => 'getReferrerType']]],
        ],
    ];

    /** @return array<string, string> */
    public function getPages(string $section): array
    {
        $pages = [];
        foreach (array_keys(self::PAGES[$section] ?? []) as $page) {
            $pages[$page] = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang_mod.xlf:report.' . $page;
        }
        return $pages;
    }

    /** @return list<Definition> */
    public function getReports(string $section, string $page): array
    {
        return self::PAGES[$section][$page]['reports'] ?? [];
    }
}
