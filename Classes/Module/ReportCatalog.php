<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

final readonly class ReportCatalog
{
    private const PAGES = [
        'visitors' => ['overview', 'locations', 'devices', 'software', 'times', 'engagement'],
        'behaviour' => ['pages', 'entryPages', 'exitPages', 'pageTitles', 'siteSearch', 'outlinks', 'downloads', 'events', 'contents', 'performance', 'trendingPages'],
        'acquisition' => ['channels', 'websites', 'searchEngines', 'socialNetworks', 'aiAssistants', 'campaigns'],
        'goals' => ['overview'],
        'page' => ['overview'],
    ];

    /**
     * @return array<string, string> report page identifiers and their label references
     */
    public function getPages(string $section): array
    {
        $pages = [];
        foreach (self::PAGES[$section] ?? [] as $page) {
            $pages[$page] = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang_mod.xlf:report.' . $page;
        }
        return $pages;
    }
}
