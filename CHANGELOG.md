# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Page details in the module: tiles and evolution chart for the frontend URL of the selected page and language, in one bulk request. The statistics bar links there with page, language and period preselected.
- Page load and server time in page details, with the number of measured page views; no average below five measurements.
- Language comparison in page details: views, bounce rate and time on page per translation the user may access, in the same bulk request; a click switches the language.
- Transitions in page details, loaded on demand: channels and referrers, previous and next pages linked to the Page module (editors see only pages they may access), outlinks and downloads (links into a TYPO3 site only for editors with access to its site and page). Pages without data show an empty state.
- Reading time in page details: average time on page against an estimate from the words of the visible content elements, with the new site setting `analyticsMatomo.wordsPerMinute` (default 220). No estimate below 100 words.
- Goals section in the module: conversions, conversion rate and revenue of all goals with their evolution, and a page per goal with conversions by channel (with each channel's share) and by entry page (editors see only entry pages they may access). Page details show per goal the conversions after the page, attributed to it and entered on it, from the same bulk request; hidden without goals. Demo mode has two goals.
- Trending pages in Behaviour: pages that rose or fell against the previous period, with thresholds against noise on small sites, and a rising or falling hint in page details. Editors see only pages they may access. Works without Matomo's Insights plugin; demo pages rise and fall from week to week.
- Visitors report pages Locations, Devices, Software, Times and Engagement in the module, from catalog entries only. Reports without a dimension (returning visits) render as a metric list. Demo mode answers every report.
- Behaviour report pages Entry pages, Exit pages, Page titles and Performance. URL reports follow the selected page subtree and language; the slowest pages need at least five measured page views. Durations under a minute show fractions of seconds.
- Behaviour report pages Outlinks, Downloads, Events and Contents. Without data, they name the tracking code they need.
- Acquisition report pages All channels (with all referrers), Websites, Search engines & keywords, Social networks, AI assistants and Campaigns. Pages for reports a Matomo lacks (AI assistants on older versions, core campaigns replaced by a plugin) are hidden; the check is cached per Matomo site.
- Site search report page with keywords, keywords without results and categories, behind the new custom option `analytics_matomo:siteSearch`. Docs show `trackSiteSearch` snippets for indexed_search and Solr.
- Per-section backend group access through TYPO3 module permissions, independently of the dashboard widgets' `siteStatistics` option. The widget selection AJAX route rejects all requests without that option; site and page access remain enforced.
- Visitors overview with six KPI tiles, a Chart.js evolution chart and a client-side metric picker. Comparison adds tile changes and a dashed previous-period series; chart points use days, weeks or months automatically. Aggregated live counters cover the last 30 minutes and are cached separately for 60 seconds. Demo mode supplies the overview without HTTP.
- Generic report tables for Behaviour / Pages, Acquisition / All channels and Visitors / Devices: translated Matomo columns and metric documentation, search, sorting, 25-row paging and comparison with the previous period. URL rows respect page access, site language and the selected page-tree subtree, with links to page details, the Page module, page properties and the frontend. Demo mode supplies all three reports.
- Matomo Analytics backend module with page tree, five sections and global report, site, language, period and comparison controls. Site, period and comparison are shared with the dashboard widgets. Custom ranges use native date inputs.

- Statistics bar in the Page module: page views, bounce rate, average time on page and exit rate of the current page and language, with trend against the previous period and a daily sparkline. Loaded via a backend AJAX route that inherits access from the Page module.
- Periods of 7, 14 and 30 days, link to the Matomo report.
- Site set with the settings `analyticsMatomo.url`, `analyticsMatomo.idSite` and `analyticsMatomo.tokenAuth`, with fallbacks to EXT:matomo_integration, EXT:matomo_widgets and EXT:matomo_tracking.
- Matomo Reporting API client: one `API.getBulkRequest` POST per page and period, token in the request body, cached for an hour in the `system` cache group so `cache:flush` clears it.
- Dashboard widgets in the group **Matomo**: site performance, visits over time and top pages, each with a details link to its module report page, and the dashboard preset **Matomo overview**. All widgets share the site and period chosen by the user; only sites with a Matomo connection whose root page the user may see are offered. Channels, devices, browsers and countries are module reports instead of widgets.
- Site setting `analyticsMatomo.demoData`: generated statistics instead of a Matomo connection, marked with a badge, to try out the extension. Top pages are the pages of the site in every language, numbers differ by page, language and site.
- Backend group permissions `analytics_matomo:pageStatistics` (Page module bar) and `analytics_matomo:siteStatistics` (dashboard widgets); admins always have both.
- Unit and functional tests, quality tooling and CI for PHP 8.2–8.4 on TYPO3 13.4 and 14.3.
