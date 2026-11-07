# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Generic report tables for Behaviour / Pages, Acquisition / All channels and Visitors / Devices: translated Matomo columns and metric documentation, search, sorting, 25-row paging and comparison with the previous period. URL rows respect page access, site language and the selected page-tree subtree, with links to page details, the Page module, page properties and the frontend. Demo mode supplies all three reports.
- Matomo Analytics backend module with page tree, five sections and global report, site, language, period and comparison controls. Site, period and comparison are shared with the dashboard widgets. Custom ranges use native date inputs; charts follow separately.

- Statistics bar in the Page module: page views, bounce rate, average time on page and exit rate of the current page and language, with trend against the previous period and a daily sparkline. Loaded via a backend AJAX route that inherits access from the Page module.
- Periods of 7, 14 and 30 days, link to the Matomo report.
- Site set with the settings `analyticsMatomo.url`, `analyticsMatomo.idSite` and `analyticsMatomo.tokenAuth`, with fallbacks to EXT:matomo_integration, EXT:matomo_widgets and EXT:matomo_tracking.
- Matomo Reporting API client: one `API.getBulkRequest` POST per page and period, token in the request body, cached for an hour.
- Dashboard widgets in the group **Matomo**: site performance, visits over time, top pages, channels, devices, browsers and countries, and the dashboard preset **Matomo overview**. All widgets share the site and period chosen by the user; only sites with a Matomo connection whose root page the user may see are offered.
- Site setting `analyticsMatomo.demoData`: generated statistics instead of a Matomo connection, marked with a badge, to try out the extension. Top pages are the pages of the site in every language, numbers differ by page, language and site.
- Backend group permissions `analytics_matomo:pageStatistics` (Page module bar) and `analytics_matomo:siteStatistics` (dashboard widgets); admins always have both.
- Unit and functional tests, quality tooling and CI for PHP 8.2–8.4 on TYPO3 13.4 and 14.3.
