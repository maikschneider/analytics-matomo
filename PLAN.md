# analytics_matomo — plan

Show Matomo statistics of the current page directly in the TYPO3 Page module, with the look and feel of the page performance bar of [TYPO3GmbH/analytics](https://github.com/TYPO3GmbH/analytics), but with data from your own Matomo.

Research behind this plan: TYPO3GmbH/analytics 1.1.1 (`2cd555b`), pagemachine/typo3-matomo-tracking 2.0.0 (`0567f8c`), brotkrueml/typo3-matomo-widgets and matomo_integration, Matomo Reporting API checked live against demo.matomo.org (Matomo 6.0.0-rc1).

## Architecture

```
Page module (web_layout)
  └─ ModifyPageLayoutContentEvent ──► StatisticsBarListener
        PageTargetResolver: page visible? has a URL? site has Matomo config?
        └─ adds a loading placeholder <section data-url="…ajax route…">  (no Matomo call here)

statistics-bar.js (ES module, AjaxRequest)
  └─ GET /typo3/ajax/analytics-matomo/statistics-bar?pageId&languageId&days
        StatisticsBarController (route inherits access from web_layout)
          PageTargetResolver (same checks again, page URL via site router)
          PageStatisticsService ─► cache "analytics_matomo" ─► MatomoClient (1 bulk POST)
          TileFactory: format values, trend vs. previous period, sparkline points
          StatisticsBarRenderer: Fluid template StatisticsBar.html
        └─ JSON {html} ──► replaces the placeholder
```

Why a placeholder + AJAX: a slow or unreachable Matomo must never slow down the Page module. TYPO3GmbH/analytics does the same.

| Class | Job |
|---|---|
| `Configuration\MatomoConfigurationResolver` | URL, site ID and token of a site (site settings + fallbacks) |
| `Matomo\MatomoClient` | `API.getBulkRequest` POST, token in body, error handling (`MatomoApiException`) |
| `Statistics\PageTargetResolver` | access check (`readPageAccess`), doktype, site, language, absolute page URL |
| `Statistics\PageStatisticsService` | the 3 API calls per page, caching |
| `Statistics\TileFactory` | view model of the 4 tiles |
| `EventListener\StatisticsBarListener` | placeholder in the Page module |
| `Controller\StatisticsBarController` | AJAX endpoint |

No Extbase, no TCA, no database tables of our own (only the cache tables TYPO3 creates for the cache). Therefore no tca_api.

## Matomo API calls per view

Statistics bar (one HTTP request, `API.getBulkRequest`, `format_metrics=0`):

| # | Call | Used for |
|---|---|---|
| 1 | `Actions.getPageUrl&pageUrl=<url>&period=range&date=<today-N+1>,<today>` | tile values |
| 2 | same, `date=<today-2N+1>,<today-N>` | trend vs. previous period |
| 3 | same, `period=day&date=<today-N+1>,<today>` | sparkline per tile (days without data = `[]` → 0) |

Tiles: page views (`nb_hits`), bounce rate (`bounce_rate`, entry based), avg. time on page (`avg_time_on_page`), exit rate (`exit_rate`). Exit rate replaces t3g's "continuation rate" (100 − bounce): it is the Matomo-native number for "visitors left after this page".

No segments: ad-hoc `pageUrl==` segments are slow or disabled on many Matomo instances (they returned nothing on demo.matomo.org). Later views must stay segment-free (see milestones).

## Configuration and auth

Site set `maikschneider/analytics-matomo`, site settings `analyticsMatomo.url`, `analyticsMatomo.idSite`, `analyticsMatomo.tokenAuth`.

Without an own URL, URL and site ID are taken **together** from the first connection that has a URL — never mixed across extensions:

| # | Connection | URL | Site ID | Token |
|---|---|---|---|---|
| 1 | own settings | `analyticsMatomo.url` | `analyticsMatomo.idSite` | own |
| 2 | EXT:matomo_integration | `matomoIntegrationUrl` | `matomoIntegrationSiteId` | own |
| 3 | EXT:matomo_widgets | `matomoWidgetsUrl` | `matomoWidgetsIdSite` | own, else `matomoWidgetsTokenAuth` |
| 4 | EXT:matomo_tracking | ext conf `matomoUrl` | `matomoTrackingSiteId` | own |

Token: a Matomo user with **view** access to the one site; put it in `settings.yaml` as `%env(MATOMO_TOKEN)%` (resolved by TYPO3 13.4 and 14). The token is sent in the POST body only, redirects are not followed (a 307/308 would resend it), and it is masked in error messages. The token of EXT:matomo_widgets is only sent to the Matomo of EXT:matomo_widgets.

Access: the AJAX route inherits access from `web_layout`; on top, the page must be readable (`readPageAccess` with `PAGE_SHOW`, incl. web mounts) and the language allowed (`checkLanguageAccess`). Matomo error details are shown to admins only.

### Decision: no dependency on pagemachine/matomo-tracking

`pagemachine/matomo-tracking` is a PHP library for the Matomo *Tracking* API; the TYPO3 extension is `pagemachine/typo3-matomo-tracking`. Neither has a Reporting API client, their token is a tracking token (least privilege says: separate view-only token), the Matomo URL is global extension configuration, and requiring it would force a server-side tracker on sites that track with JavaScript. So: no Composer dependency, but its URL and site ID are read as a fallback (same for matomo_integration and matomo_widgets). Cost: ~15 lines in `MatomoConfigurationResolver`.

## Caching

TYPO3 cache `analytics_matomo` (default `VariableFrontend` + database backend), `defaultLifetime` 3600 s, overridable in `$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']`. Key: Matomo URL, site ID, page URL, period, today's date. Errors are not cached. Matomo's own freshness depends on its archiving (cron vs. browser), so ~1 h staleness is fine.

## Version matrix

| | Supported | Notes |
|---|---|---|
| PHP | 8.2, 8.3, 8.4 | CI matrix |
| TYPO3 | 13.4, 14.3 | v13 reads language/view from module data, v14 from `PageContext` |
| Matomo | 5.x, 6.x | API surface is long-standing; checked live against 6.0.0-rc1 only |

## Milestones

1. **0.1 — statistics bar (this scaffold)**: config + fallbacks, client, bar with 4 tiles, period select, link to Matomo, caching, tests, CI. *Done.*
2. **0.2 — hardening**: test against a real Matomo 5 + 6 with a view token; URL matching variants (trailing slash, `www`, http/https) if real data shows mismatches; user TSconfig option to hide the bar for editor groups; docs on docs.typo3.org.
3. **0.3 — "where from / where to"**: `Transitions.getTransitionsForAction` (segment-free) as a tooltip or expandable row: top referrers, previous and next pages.
4. **0.4 — optional**: dashboard widget "top pages of this site" (only if matomo_widgets doesn't already cover it), translated pages overview.
5. **1.0**: TER + Packagist release after Maik's go.

## Open questions (none blocks 0.1)

1. Exit rate instead of t3g's "continuation rate" — OK?
2. Who may see statistics: everyone with Page module access (now) or opt-in per backend group?
3. Default periods 7/14/30 days and 1 h cache — OK?
4. Extension key `analytics_matomo` still has to be registered on extensions.typo3.org.
