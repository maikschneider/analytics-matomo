# analytics_matomo — plan

Show Matomo statistics in the TYPO3 backend — a "Matomo Analytics" backend module for exploring the core Matomo reports, a statistics bar for the current page in the Page module and a few dashboard widgets — with the look and feel of [TYPO3GmbH/analytics](https://github.com/TYPO3GmbH/analytics), but with data from your own Matomo. The module is designed in [MODULE.md](MODULE.md).

Research behind this plan: TYPO3GmbH/analytics 1.1.1 (`2cd555b`), pagemachine/typo3-matomo-tracking 2.0.0 (`0567f8c`), brotkrueml/typo3-matomo-widgets and matomo_integration, Matomo Reporting API checked live against demo.matomo.org (Matomo 6.0.0-rc1).

Work is tracked as [GitHub issues](https://github.com/maikschneider/analytics-matomo/issues) in the milestones listed under [Roadmap](#roadmap).

## Feature parity with TYPO3GmbH/analytics

t3g/analytics is the backend of a SaaS: it registers the TYPO3 instance, injects its tracking code and reads statistics from its own API. This extension reads the Reporting API of a Matomo the site already has, so everything that belongs to the SaaS has no pendant.

| Feature | t3g implementation | Matomo pendant | Decision |
|---|---|---|---|
| Page performance bar | `PagePerformanceBarListener` + AJAX, 4 tiles with trend and sparkline | `Actions.getPageUrl` (range, previous range, per day) | **done** in 0.1 |
| Bar metrics | page views, bounce rate, time on page, continuation rate (100 − bounce) | `nb_hits`, `bounce_rate`, `avg_time_on_page`, `exit_rate` | **done**: exit rate instead of continuation rate, as it is Matomo's own number |
| Periods | `DashboardPeriods` (7/14/30, configurable) | `period=range&date=…` | **done**: 7/14/30, `Period` value object |
| Access to the bar | route inherits `web_layout`, `readPageAccess` | same, plus language access | **done** + opt-in backend group permission ([#4](https://github.com/maikschneider/analytics-matomo/issues/4)) |
| Widget group + preset | `DashboardWidgetGroups.php`, `DashboardPresets.php` | – | **done** ([#5](https://github.com/maikschneider/analytics-matomo/issues/5)) |
| Site performance widget | `SitePerformanceWidget(V14)` | `VisitsSummary.get`, `Actions.get` (range, previous, per day) | **done** ([#6](https://github.com/maikschneider/analytics-matomo/issues/6)) |
| Traffic graph widget | `TrafficGraphWidget(V14)`, Chart.js | `VisitsSummary.get` + `VisitFrequency.get` per day | **done** ([#7](https://github.com/maikschneider/analytics-matomo/issues/7)): visits, visitors, new and returning visits |
| Top pages widget | `TopPagesWidget(V14)` | `Actions.getPageUrls` (`flat=1`, current + previous) + `Actions.get` for the share | **done** ([#8](https://github.com/maikschneider/analytics-matomo/issues/8)): only URLs of the selected site, linked to the Page module when readable |
| Traffic sources widget (v13: one widget, v14: section setting channel/devices/browser/countries) | `TrafficSourcesWidget(V14)` | `Referrers.getReferrerType`, `DevicesDetection.getType`, `DevicesDetection.getBrowsers`, `UserCountry.getCountry` | **done** in the module ([#40](https://github.com/maikschneider/analytics-matomo/issues/40)); the four former widgets are removed, labels translated by Matomo (`language`) |
| Site/period selection in widgets | v13: none per widget, v14: native widget settings | – | **done** as one selection per user (site + period, stored in `uc`), shared by all widgets and later the module; native v14 settings dropped ([#12](https://github.com/maikschneider/analytics-matomo/issues/12)) |
| Multi-site | `AnalyticsSiteProvider`: sites with a website ID | sites with a Matomo connection | **done**: only sites whose root page the user may see; several sites per Matomo site are told apart by URL |
| Multi-language | page URL per `SiteLanguage` | same | **done**: bar uses the URL of the selected language, top pages show the language flag |
| Demo data | `DemoAnalyticsDataClient` | generated Reporting API answers | **done** ([#10](https://github.com/maikschneider/analytics-matomo/issues/10)): site setting `analyticsMatomo.demoData` |
| Backend module (iframe of the SaaS dashboard, registration, status, plans) | `BackendModuleController`, `InstanceRegistrationService`, `PlansService` | Matomo's core reports | **different**: no iframe, registration or plans, but an own module with the core Matomo reports ([MODULE.md](MODULE.md), [#37](https://github.com/maikschneider/analytics-matomo/issues/37)); connection status in the Reports module ([#13](https://github.com/maikschneider/analytics-matomo/issues/13)) |
| Tracking code injection | `TrackingCodeMiddleware` | – | **skip**: tracking is the job of matomo_integration / matomo_tracking, which this extension reuses the connection of |
| Instance secret, HMAC, API keys, encryption wizard | `CipherService`, `HmacSigner`, `ApiKeyService`, upgrade wizard | `token_auth` | **skip**: one view-only token in the site settings (`%env()%`), sent in the POST body only |
| Manager permission | `tx_analytics:manager` custom option | – | **skip**: nothing to manage; view permissions (#4), module access per section (#17) |
| Transitions (where from / where to) | – | `Transitions.getTransitionsForAction` | **extra**, done in 0.5.0 ([#14](https://github.com/maikschneider/analytics-matomo/issues/14)): on request in page details, with outlinks and downloads |

## Architecture

```
Page module (web_layout)
  └─ ModifyPageLayoutContentEvent ──► StatisticsBarListener
        PageTargetResolver: permission? page visible? has a URL? site has Matomo config?
        └─ adds a loading placeholder <section data-url="…ajax route…">  (no Matomo call here)

statistics-bar.js (ES module, AjaxRequest)
  └─ GET /typo3/ajax/analytics-matomo/statistics-bar?pageId&languageId&days
        StatisticsBarController (route inherits access from web_layout)
          PageTargetResolver (same checks again, page URL via site router)
          PageStatisticsService ─► StatisticsCache ─► MatomoClient (1 bulk POST)
          TileFactory: format values, trend vs. previous period, sparkline points
          StatisticsBarRenderer: Fluid template StatisticsBar.html
        └─ JSON {html} ──► replaces the placeholder

Dashboard
  MatomoWidget (one class, option "report" = performance|traffic|topPages; details links into the module)
    DashboardSelectionResolver: permission, sites with connection + readable root page, site/period from uc
    SiteStatisticsService ─► StatisticsCache ─► MatomoClient (1 bulk POST per widget)
    PageUrlMapper: Matomo URL → site language + page (top pages)
  dashboard-widget.js ─► POST /typo3/ajax/analytics-matomo/dashboard-selection, then refreshes all Matomo widgets
```

Why a placeholder + AJAX: a slow or unreachable Matomo must never slow down the Page module. TYPO3GmbH/analytics does the same; dashboard widgets are loaded asynchronously by the dashboard itself.

The backend module (milestone 0.3.0 on) is designed in [MODULE.md](MODULE.md#technical-approach): one controller for all sections, a report catalog and one generic table and chart.

| Class | Job |
|---|---|
| `Configuration\MatomoConfigurationResolver` | URL, site ID and token of a site (site settings + fallbacks, demo data) |
| `Matomo\MatomoClient` | `API.getBulkRequest` POST, token in body, error handling (`MatomoApiException`) |
| `Matomo\DemoData` | generated answers for `analyticsMatomo.demoData` |
| `Authorization\StatisticsPermission` | backend group permissions `pageStatistics` / `siteStatistics` |
| `Statistics\PageTargetResolver` | access check (`readPageAccess`), doktype, site, language, absolute page URL |
| `Statistics\PageStatisticsService` | the 3 API calls per page |
| `Statistics\Period`, `Statistics\StatisticsCache` | periods, caching shared by bar and widgets |
| `Statistics\TileFactory` | view model of the metric tiles (bar and site performance) |
| `Dashboard\SiteStatisticsService` | API calls of the widgets |
| `Dashboard\DashboardSelectionResolver` | sites a user may choose, stored selection |
| `Statistics\PageUrlMapper` | Matomo URL → site language and page (incl. hidden pages, scheme-less labels, double slashes, encoded slugs); report rows by page, "unknown" when Matomo cut the report at its row limit |
| `Module\ReportCatalog` | data-only report pages, Matomo methods, parameters and URL-row mapping per section |
| `Module\ReportService` | one cached bulk of processed reports per report page, with previous ranges when comparing; language of the backend user |
| `Module\ReportTableFactory` | Matomo metadata to table view model, page/language/subtree access filtering, search, sort and 25-row paging in PHP |
| `Module\MetricFormatter` | raw metric values formatted by Matomo metric type; unknown values remain unknown |
| `Module\VisitorsOverviewService` | cached `API.get` range totals and evolution in one bulk, optional previous period; separate `Live.getCounters` cache (60 s) |
| `Module\EvolutionChartFactory` | calendar day/week/month buckets, unknown values as null and previous buckets aligned by position; shared chart payload for the metric picker |
| `Controller\ReportModuleController` | module docheader, page-tree site, shared selection, report/language module data and empty states |
| `Dashboard\Widget\MatomoWidget` | all widgets |

No Extbase, no TCA, no database tables of our own (only the cache tables TYPO3 creates for the cache). Therefore no tca_api.

## Matomo API calls per view

Every view is one HTTP request (`API.getBulkRequest`, `format_metrics=0`). No ad-hoc segments: they are slow or disabled on many Matomo instances (they returned nothing on demo.matomo.org); the module may later offer stored, pre-processed segments (#47). Module calls: [MODULE.md](MODULE.md).

| View | Calls |
|---|---|
| Statistics bar | `Actions.getPageUrl&pageUrl=<url>` for the range, the previous range and per day |
| Visitors overview | `API.get` range + day/week/month evolution, plus previous range/evolution when comparing; `Live.getCounters(lastMinutes=30)` in a separate bulk with a 60 s cache |
| Site performance | `VisitsSummary.get` + `Actions.get` (`nb_pageviews`) for the range, the previous range and per day |
| Visits over time | `VisitsSummary.get` + `VisitFrequency.get` (`nb_visits_returning`) per day |
| Top pages | `Actions.getPageUrls` (`flat=1`, sorted by `nb_hits`) for the range and the previous range, `Actions.get` for the total |
| Channels, devices, browsers, countries and other catalog reports | `API.getProcessedReport` for the range and optionally the previous range, `language` of the backend user on the outer request; optional Acquisition pages use cached `API.getMetadata` availability checks |

Bar tiles: page views (`nb_hits`), bounce rate (`bounce_rate`, entry based), avg. time on page (`avg_time_on_page`), exit rate (`exit_rate`). Exit rate replaces t3g's "continuation rate" (100 − bounce): it is the Matomo-native number for "visitors left after this page".

## Configuration and auth

Site set `maikschneider/analytics-matomo`, site settings `analyticsMatomo.url`, `analyticsMatomo.idSite`, `analyticsMatomo.tokenAuth`, `analyticsMatomo.demoData`.

Without an own URL, URL and site ID are taken **together** from the first connection that has a URL — never mixed across extensions:

| # | Connection | URL | Site ID | Token |
|---|---|---|---|---|
| 1 | own settings | `analyticsMatomo.url` | `analyticsMatomo.idSite` | own |
| 2 | EXT:matomo_integration | `matomoIntegrationUrl` | `matomoIntegrationSiteId` | own |
| 3 | EXT:matomo_widgets | `matomoWidgetsUrl` | `matomoWidgetsIdSite` | own, else `matomoWidgetsTokenAuth` |
| 4 | EXT:matomo_tracking | ext conf `matomoUrl` | `matomoTrackingSiteId` | own |

Token: a Matomo user with **view** access to the one site; put it in `settings.yaml` as `%env(MATOMO_TOKEN)%` (resolved by TYPO3 13.4 and 14). The token is sent in the POST body only, redirects are not followed (a 307/308 would resend it), and it is masked in error messages. The token of EXT:matomo_widgets is only sent to the Matomo of EXT:matomo_widgets.

Access is opt-in per backend group: `analytics_matomo:pageStatistics` for the bar, `analytics_matomo:siteStatistics` for the widgets; admins have both. The bar route inherits access from `web_layout`; on top, the page must be readable (`readPageAccess` with `PAGE_SHOW`, incl. web mounts) and the language allowed (`checkLanguageAccess`). Widgets offer only sites whose root page the user may see. Top pages list only pages and languages the user may access (URLs without a page: admins only). Site-wide numbers cover the whole Matomo site: TYPO3 sites sharing one Matomo site share their numbers (no segments, see above), so one Matomo site per TYPO3 site is recommended when editors of different sites must not see each other's numbers. Matomo error details are shown to admins only. Cache entries are keyed by a hash of URL, site ID and token, so data fetched with one token never answers a request made with another.

### Decision: no dependency on pagemachine/matomo-tracking

`pagemachine/matomo-tracking` is a PHP library for the Matomo *Tracking* API; the TYPO3 extension is `pagemachine/typo3-matomo-tracking`. Neither has a Reporting API client, their token is a tracking token (least privilege says: separate view-only token), the Matomo URL is global extension configuration, and requiring it would force a server-side tracker on sites that track with JavaScript. So: no Composer dependency, but its URL and site ID are read as a fallback (same for matomo_integration and matomo_widgets). Cost: ~15 lines in `MatomoConfigurationResolver`.

## Caching

TYPO3 cache `analytics_matomo` (default `VariableFrontend` + database backend), `defaultLifetime` 3600 s, overridable in `$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']`. Key: view, hash of Matomo URL + site ID + token, page URL or report, period (which contains today's date), language. Errors are not cached. Matomo's own freshness depends on its archiving (cron vs. browser), so ~1 h staleness is fine.

## Version matrix

| | Supported | Notes |
|---|---|---|
| PHP | 8.2, 8.3, 8.4 | CI matrix |
| TYPO3 | 13.4, 14.3 | v13 reads language/view from module data, v14 from `PageContext`; requires EXT:dashboard |
| Matomo | 5.x, 6.x | API surface is long-standing; checked live against 6.0.0-rc1 only ([#15](https://github.com/maikschneider/analytics-matomo/issues/15)) |

## Roadmap

All planned features must work with Matomo core (no premium plugins) and a view-only token. The backend module ([MODULE.md](MODULE.md)) is the main place for statistics; widgets and the page bar link into it.

| Milestone | Scope |
|---|---|
| 0.1 | Statistics bar: config + fallbacks, client, 4 tiles, period select, caching, CI. *Done.* |
| [0.2.0](https://github.com/maikschneider/analytics-matomo/milestone/1) | Parity with t3g/analytics: strict SCA, permissions, dashboard widgets, demo data, fixtures (#1–#11). *Done.* |
| [0.3.0](https://github.com/maikschneider/analytics-matomo/milestone/2) | Module foundation: module + global controls (#37), report table (#38), evolution chart + Visitors overview (#39), URL-to-page mapper (#26), permissions (#17), hidden-page fix (#36) |
| [0.4.0](https://github.com/maikschneider/analytics-matomo/milestone/4) | Module reports: Visitors (#41), Behaviour pages (#42) and interactions (#43), Acquisition (#44), site search (#24), widgets as entry points into these reports (#40). *Done.* |
| [0.5.0](https://github.com/maikschneider/analytics-matomo/milestone/5) | Page details (#45) with Transitions (#14, #18), languages (#21), page speed (#20), reading time (#25); Goals (#22); trending pages (#23). *Done.* |
| [0.6.0](https://github.com/maikschneider/analytics-matomo/milestone/6) | Admin: connection status (#13), token check (#19), multi-site overview (#30), CSV export (#46), stored segments (#47) |
| [0.7.0](https://github.com/maikschneider/analytics-matomo/milestone/8) | Content workflow and monitoring: annotations (#27), page tree badges (#28), content review (#29), anomaly alerts (#31), weekly digest (#32) |
| [1.0.0](https://github.com/maikschneider/analytics-matomo/milestone/3) | Real Matomo 5 + 6 with a view token (#15), site-side setup docs (#33), first TER + Packagist release (#16) |
| [Backlog](https://github.com/maikschneider/analytics-matomo/milestone/7) | 404 → redirect suggestions (#34), privacy-safe visits log (#48, needs a decision) |

Dropped: native per-widget settings on v14 (#12): widgets and module share one selection per user. Ecommerce reports: see [MODULE.md](MODULE.md#goals).

Not planned, as Matomo core has no built-in report for them or a view token can't read them: consent rate (declined visitors send nothing), scroll depth and media clicks (only custom events without a per-page report; Heatmaps and Media Analytics are premium), exact archiving status (needs a superuser token).
