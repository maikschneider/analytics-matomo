# analytics_matomo — plan

Show Matomo statistics in the TYPO3 backend — a statistics bar for the current page in the Page module and dashboard widgets for whole sites — with the look and feel of [TYPO3GmbH/analytics](https://github.com/TYPO3GmbH/analytics), but with data from your own Matomo.

Research behind this plan: TYPO3GmbH/analytics 1.1.1 (`2cd555b`), pagemachine/typo3-matomo-tracking 2.0.0 (`0567f8c`), brotkrueml/typo3-matomo-widgets and matomo_integration, Matomo Reporting API checked live against demo.matomo.org (Matomo 6.0.0-rc1).

Work is tracked as [GitHub issues](https://github.com/maikschneider/analytics-matomo/issues) in the milestones [0.2.0](https://github.com/maikschneider/analytics-matomo/milestone/1), [0.3.0](https://github.com/maikschneider/analytics-matomo/milestone/2) and [1.0.0](https://github.com/maikschneider/analytics-matomo/milestone/3).

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
| Traffic sources widget (v13: one widget, v14: section setting channel/devices/browser/countries) | `TrafficSourcesWidget(V14)` | `Referrers.getReferrerType`, `DevicesDetection.getType`, `DevicesDetection.getBrowsers`, `UserCountry.getCountry` | **done** ([#9](https://github.com/maikschneider/analytics-matomo/issues/9)) as four widgets on both versions; labels translated by Matomo (`language`) |
| Site/period selection in widgets | v13: none per widget, v14: native widget settings | – | **done** as one selection per user (site + period, stored in `uc`), shared by all widgets; native v14 settings: [#12](https://github.com/maikschneider/analytics-matomo/issues/12) |
| Multi-site | `AnalyticsSiteProvider`: sites with a website ID | sites with a Matomo connection | **done**: only sites whose root page the user may see; several sites per Matomo site are told apart by URL |
| Multi-language | page URL per `SiteLanguage` | same | **done**: bar uses the URL of the selected language, top pages show the language flag |
| Demo data | `DemoAnalyticsDataClient` | generated Reporting API answers | **done** ([#10](https://github.com/maikschneider/analytics-matomo/issues/10)): site setting `analyticsMatomo.demoData` |
| Backend module (iframe of the SaaS dashboard, registration, status, plans) | `BackendModuleController`, `InstanceRegistrationService`, `PlansService` | Matomo's own UI | **skip**: Matomo has its own UI, every view links to it. Instead a connection status in the Reports module ([#13](https://github.com/maikschneider/analytics-matomo/issues/13)) |
| Tracking code injection | `TrackingCodeMiddleware` | – | **skip**: tracking is the job of matomo_integration / matomo_tracking, which this extension reuses the connection of |
| Instance secret, HMAC, API keys, encryption wizard | `CipherService`, `HmacSigner`, `ApiKeyService`, upgrade wizard | `token_auth` | **skip**: one view-only token in the site settings (`%env()%`), sent in the POST body only |
| Manager permission | `tx_analytics:manager` custom option | – | **skip**: no module to manage; view permissions instead (#4) |
| Transitions (where from / where to) | – | `Transitions.getTransitionsForAction` | **extra** ([#14](https://github.com/maikschneider/analytics-matomo/issues/14)) |

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
  MatomoWidget (one class, option "report" = performance|traffic|topPages|channel|devices|browsers|countries)
    DashboardSelectionResolver: permission, sites with connection + readable root page, site/period from uc
    SiteStatisticsService ─► StatisticsCache ─► MatomoClient (1 bulk POST per widget)
    PageUrlMatcher: Matomo URL → site language + page (top pages)
  dashboard-widget.js ─► POST /typo3/ajax/analytics-matomo/dashboard-selection, then refreshes all Matomo widgets
```

Why a placeholder + AJAX: a slow or unreachable Matomo must never slow down the Page module. TYPO3GmbH/analytics does the same; dashboard widgets are loaded asynchronously by the dashboard itself.

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
| `Dashboard\PageUrlMatcher` | URL of a top page → site language and page |
| `Dashboard\Widget\MatomoWidget` | all widgets |

No Extbase, no TCA, no database tables of our own (only the cache tables TYPO3 creates for the cache). Therefore no tca_api.

## Matomo API calls per view

Every view is one HTTP request (`API.getBulkRequest`, `format_metrics=0`). No segments: ad-hoc segments are slow or disabled on many Matomo instances (they returned nothing on demo.matomo.org).

| View | Calls |
|---|---|
| Statistics bar | `Actions.getPageUrl&pageUrl=<url>` for the range, the previous range and per day |
| Site performance | `VisitsSummary.get` + `Actions.get` (`nb_pageviews`) for the range, the previous range and per day |
| Visits over time | `VisitsSummary.get` + `VisitFrequency.get` (`nb_visits_returning`) per day |
| Top pages | `Actions.getPageUrls` (`flat=1`, sorted by `nb_hits`) for the range and the previous range, `Actions.get` for the total |
| Channels, devices, browsers, countries | the report for the range and the previous range, `language` of the backend user on the outer request (Matomo ignores it inside the single requests) |

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

Access is opt-in per backend group: `analytics_matomo:pageStatistics` for the bar, `analytics_matomo:siteStatistics` for the widgets; admins have both. The bar route inherits access from `web_layout`; on top, the page must be readable (`readPageAccess` with `PAGE_SHOW`, incl. web mounts) and the language allowed (`checkLanguageAccess`). Widgets offer only sites whose root page the user may see, and link top pages only if the page and its language are accessible. Matomo error details are shown to admins only.

### Decision: no dependency on pagemachine/matomo-tracking

`pagemachine/matomo-tracking` is a PHP library for the Matomo *Tracking* API; the TYPO3 extension is `pagemachine/typo3-matomo-tracking`. Neither has a Reporting API client, their token is a tracking token (least privilege says: separate view-only token), the Matomo URL is global extension configuration, and requiring it would force a server-side tracker on sites that track with JavaScript. So: no Composer dependency, but its URL and site ID are read as a fallback (same for matomo_integration and matomo_widgets). Cost: ~15 lines in `MatomoConfigurationResolver`.

## Caching

TYPO3 cache `analytics_matomo` (default `VariableFrontend` + database backend), `defaultLifetime` 3600 s, overridable in `$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']`. Key: view, Matomo URL, site ID, page URL or report, period (which contains today's date), language. Errors are not cached. Matomo's own freshness depends on its archiving (cron vs. browser), so ~1 h staleness is fine.

## Version matrix

| | Supported | Notes |
|---|---|---|
| PHP | 8.2, 8.3, 8.4 | CI matrix |
| TYPO3 | 13.4, 14.3 | v13 reads language/view from module data, v14 from `PageContext`; requires EXT:dashboard |
| Matomo | 5.x, 6.x | API surface is long-standing; checked live against 6.0.0-rc1 only ([#15](https://github.com/maikschneider/analytics-matomo/issues/15)) |

## Milestones

1. **0.1 — statistics bar**: config + fallbacks, client, bar with 4 tiles, period select, link to Matomo, caching, tests, CI. *Done.*
2. **0.2.0 — parity with t3g/analytics**: strict SCA (#1), permissions (#4), parity matrix (#3), dashboard widgets (#5–#9), demo data (#10), fixtures (#11). *Done.*
3. **0.3.0**: native widget settings on v14 (#12), connection status in the Reports module (#13), Transitions (#14).
4. **1.0.0**: verify against real Matomo 5 + 6 with a view token (#15), TER + Packagist release after Maik's go (#16).
