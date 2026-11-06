# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Statistics bar in the Page module: page views, bounce rate, average time on page and exit rate of the current page and language, with trend against the previous period and a daily sparkline. Loaded via a backend AJAX route that inherits access from the Page module.
- Periods of 7, 14 and 30 days, link to the Matomo report.
- Site set with the settings `analyticsMatomo.url`, `analyticsMatomo.idSite` and `analyticsMatomo.tokenAuth`, with fallbacks to EXT:matomo_integration, EXT:matomo_widgets and EXT:matomo_tracking.
- Matomo Reporting API client: one `API.getBulkRequest` POST per page and period, token in the request body, cached for an hour.
- Backend group permissions `analytics_matomo:pageStatistics` (Page module bar) and `analytics_matomo:siteStatistics` (dashboard widgets); admins always have both.
- Unit and functional tests, quality tooling and CI for PHP 8.2–8.4 on TYPO3 13.4 and 14.3.
