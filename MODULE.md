# Matomo Analytics backend module — design

The module is the main place for statistics. Editors should rarely need to open Matomo itself. The dashboard widgets and the Page module bar stay small and link into the module.

Rules for everything here: Matomo core reports only (no premium or marketplace plugins), a token with **view** access, TYPO3 13.4 and 14.3. Work is tracked in the milestones listed under [Roadmap](PLAN.md#roadmap).

## Information architecture

The structure follows Matomo's own menu (`API.getReportPagesMetadata`, checked live on demo.matomo.org, Matomo 6.0.0-rc1). A **section** is a third-level backend module; a **report page** inside a section is chosen in the docheader. Each report page shows one or more reports. The table lists the Matomo methods (all via `API.getProcessedReport`).

### Visitors

| Report page | Content | Matomo |
|---|---|---|
| Overview | KPI tiles (visits, visitors, page views, bounce rate, avg. time, actions per visit), evolution chart with a metric picker, real-time counter "last 30 min" | `API.get` (range + previous range + per day), `Live.getCounters(lastMinutes=30)` |
| Locations | countries, regions, cities, browser languages | `UserCountry.getCountry/getRegion/getCity`, `UserLanguage.getLanguage` |
| Devices | device type, brand, model, screen resolution | `DevicesDetection.getType/getBrand/getModel`, `Resolution.getResolution` |
| Software | OS, browser, browser engine | `DevicesDetection.getOsFamilies/getBrowsers/getBrowserEngines` |
| Times | per local hour, per server hour, per weekday | `VisitTime.getVisitInformationPerLocalTime/PerServerTime/getByDayOfWeek` |
| Engagement | visit duration, pages per visit, visit number, days since last visit, new vs. returning | `VisitorInterest.*`, `VisitFrequency.get` |

Not in the module: Visits log and Real-time visitor list (see [Privacy](#privacy-visits-log-and-real-time)), User IDs (personal data), Custom Variables (deprecated in Matomo), Cohorts and Users Flow (premium), the visitor map (would need a map library; countries table instead).

### Behaviour

| Report page | Content | Matomo |
|---|---|---|
| Pages | page URLs, flat, page-tree filtered (see below) | `Actions.getPageUrls(flat=1)` |
| Entry pages / Exit pages | landing and exit URLs | `Actions.getEntryPageUrls/getExitPageUrls(flat=1)` |
| Page titles | titles, flat | `Actions.getPageTitles(flat=1)` |
| Site search | keywords, no-result keywords, categories | `Actions.getSiteSearchKeywords/getSiteSearchNoResultKeywords/getSiteSearchCategories` |
| Outlinks / Downloads | clicked external links and files | `Actions.getOutlinks/getDownloads(flat=1)` |
| Events | category, action, name (flat with secondary dimension) | `Events.getCategory/getAction/getName(flat=1)` |
| Contents | content names and pieces | `Contents.getContentNames/getContentPieces(flat=1)` |
| Performance | site timing breakdown + slowest pages | `PagePerformance.get`, `Actions.getPageUrls` timing columns |
| Trending pages | risers and fallers | flat `Actions.getPageUrls` of both periods, compared in `TrendingService` (Insights only ranks the top level of the page report, i.e. folders) |

**Tree vs. flat:** no Matomo URL tree. The module already has the TYPO3 page tree: the selected page filters URL reports to the page and its subpages (mapped URL → page, then rootline check). That is the tree view editors know, and it works across languages.

**Transitions** is page-centric, so it lives in the Page details section.

### Acquisition

| Report page | Content | Matomo |
|---|---|---|
| All channels | channel types, all referrers | `Referrers.getReferrerType`, `Referrers.getAll` |
| Websites | referring websites | `Referrers.getWebsites` |
| Search engines & keywords | engines, keywords (mostly "not defined") | `Referrers.getSearchEngines`, `Referrers.getKeywords` |
| Social networks | | `Referrers.getSocials` |
| AI assistants | | `Referrers.getAIAssistants` (newer Matomo; hidden when the method is missing) |
| Campaigns | campaign names and keywords | `Referrers.getCampaigns` (core; the demo's "MarketingCampaignsReporting" is a marketplace plugin and not used) |

### Goals

The section is always in the module menu (third-level modules are static); a Matomo site without goals shows where goals are created instead. Overview: conversions, conversion rate, revenue per goal (`Goals.get`, per day for the chart), revenue hidden while it is zero. One report page per goal: conversions by channel and by entry page (`Goals.get(idGoal)`, `Referrers.getReferrerType(idGoal)`, `Actions.getEntryPageUrls(idGoal)`). Goals are created in Matomo; the extension never writes them. These goal views are not processed reports (`getProcessedReport` with `idGoal` answers "not found", checked live), so they go through the Goals adapter (direct calls with `idGoal`). Rates per row are computed from the counts: on range periods Matomo sums the daily rates of the `goals` columns (checked live).

**Ecommerce: skip.** Matomo core has it, but TYPO3 sites rarely run the shop on the same Matomo site, revenue numbers are business-sensitive (a new permission story), and the reports (products, carts) are a module of their own. Revisit if someone asks.

### Page details (TYPO3 section)

The target of the bar's "details" link and of every "page details" row action. One page URL, the language from the docheader:

- the bar metrics as tiles + evolution chart (`Actions.getPageUrl` per day)
- Transitions: previous/next pages, referrers, outlinks, downloads (`Transitions.getTransitionsForAction`)
- language comparison (`Actions.getPageUrl` per language URL, one bulk)
- page load and server time with sample count, time on page vs. reading time
- goal conversions on this page (`Actions.getPageUrls(idGoal)` row of this URL)

## Global controls (docheader)

| Control | Behaviour |
|---|---|
| Section | TYPO3's own submodule dropdown (`ModuleTemplate::makeDocHeaderModuleMenu`) |
| Report page | dropdown with the report pages of the section |
| Site | from the selected page (page tree). Without a page: dropdown of the sites the user may see (same rule as the widgets) |
| Language | "all languages" + the site languages the user may access. Filters **URL reports only** (rows whose URL the mapper resolves to that `SiteLanguage`; a URL prefix check can't tell a default base `/` from `/de/`). The language is not a segment, so device/country/channel reports are always all languages; the dropdown says so |
| Period | 7, 14, 30, 90 days, this month, last month, this year, custom range (native date inputs). Granularity of the chart is automatic: day up to 62 days, week up to a year, month above |
| Compare | toggle "compare to previous period": change columns in tables, dashed previous series in the chart. Done with a second sub-request for the previous range; Matomo's `compareDates[]` is UI-only and returned no comparison in the API (checked live) |
| Segment | only stored segments that Matomo pre-processes (`SegmentEditor.getAll`, `auto_archive=1`, this site or all sites) and that the token user can see; kept per site in the user's uc; sent as `segment` with every sub-request of reports, overview and page details. **No segment builder**: ad-hoc segments are slow or disabled on many instances. Hidden without stored segments |
| Export | CSV of the current table, served by the module from the cached report (never a Matomo URL with the token) |

Site, period and compare are stored per user in `uc` (`analytics_matomo.dashboard`) and shared with the dashboard widgets: switching the site in a widget or the module changes both. Language and report page are module data (`moduleData`).

## TYPO3 integration

- **Every internal page URL row resolves to a TYPO3 page** (page, entry/exit, performance, goal and trending reports) via the shared URL-to-page mapper (scheme-less labels, double slashes, trailing slash, language bases, `cHash`, `excluded_parameters`). Row actions: page details, Page module (page + language), edit page properties, view in frontend. Rows of pages the user may not read are hidden for non-admins (as top pages today); rows without a page are shown for admins only.
- **Outlinks and downloads** (reports and Transitions) are shown as they are, unless they point into a TYPO3 site: then non-admins need access to its language and page, or to the site for URLs without a page (files). Sites whose base has no domain claim no links of other sites.
- **Page tree aware:** the selected page filters URL reports to its subtree and picks the site. The root page or no page = whole site.
- **Missing ≠ 0:** a page not in a capped Matomo report shows "–" (unknown), not 0.
- **Permissions**
  - Sections are third-level modules, so access per section is TYPO3's normal module access in the backend group (`groupMods`): grant the parent `analytics_matomo` plus the wanted sections (without the parent the module does not appear in the menu, checked in the spike). No custom code; proven in the spike for v14.
  - `customPermOptions` stay for things that are not a section: `pageStatistics` (bar), `siteStatistics` (widgets), `siteSearch` (keywords can contain personal data), `visitsLog` (later). Admins have all.
  - Site-wide numbers still cover the whole Matomo site: one Matomo site per TYPO3 site remains the recommendation when editors of different sites must not see each other's numbers.

## Privacy: Visits log and real-time

`Live.getLastVisitsDetails` returns one row per visit with, among others: `visitIp` (anonymised only if Matomo is configured so), `visitorId`, `fingerprint`, `userId`, `city`, `latitude`/`longitude`, `resolution`, device model, `referrerUrl` and `referrerKeyword`, site search keywords, and every action with URL, title and timestamp (checked live, Matomo 6.0.0-rc1). That is a pseudonymous browsing profile, personal data under GDPR.

Proposal (Backlog, needs Maik's OK before it is built):

- off by default: site setting `analyticsMatomo.visitsLog` (false) **and** custom option `analytics_matomo:visitsLog`
- never shown: IP, visitor ID, fingerprint, user ID, coordinates, city; country, device type, channel, action list only
- respects Matomo's own "disable visits log / visitor profile" setting (error → empty state)

`Live.getCounters` (visits, actions, visitors in the last N minutes) is aggregated and safe; it is part of Visitors → Overview.

## Widgets and the Page module bar afterwards

| Widget | Decision |
|---|---|
| Site performance | **stays**, footer link "details" → Visitors / Overview |
| Visits over time | **stays**, link → Visitors / Overview |
| Top pages | **stays**, link → Behaviour / Pages; rows link to page details |
| Channels | **removed** → Acquisition / All channels |
| Devices | **removed** → Visitors / Devices |
| Browsers | **removed** → Visitors / Software |
| Countries | **removed** → Visitors / Locations |

No migration: the extension is unreleased (0.x, not on TER/Packagist). The dashboard preset shrinks to the three widgets. Later admin widgets (multi-site overview) follow the same rule: a few numbers + a link into the module. Native per-widget settings on v14 are dropped: one shared selection for widgets and module is simpler and consistent.

The **Page module bar stays** and gets a "details" link to Page details with the page, language and period preselected (like t3g's "details" link).

## Technical approach

### Module registration

`Configuration/Backend/Modules.php` (shape proven in the spike, see below):

```php
'analytics_matomo' => [
    'parent' => 'web',              // alias of 'content' on v14
    'access' => 'user',
    'path' => '/module/analytics-matomo',
    'iconIdentifier' => 'module-analytics-matomo',
    'labels' => 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang_mod.xlf',
    'navigationComponent' => '@typo3/backend/tree/page-tree-element',
],
'analytics_matomo_visitors' => [
    'parent' => 'analytics_matomo',
    'access' => 'user',
    'path' => '/module/analytics-matomo/visitors',
    'labels' => ['title' => 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang_mod.xlf:section.visitors'],
    'routes' => ['_default' => ['target' => ReportModuleController::class . '::handleRequest']],
    'moduleData' => ['report' => 'overview', 'language' => -1],
],
// behaviour, acquisition, goals, page: same, one entry each
```

The parent has no route; TYPO3 opens the last used (or first allowed) section on both versions.

### One controller, one catalog, two components

```
ReportModuleController::handleRequest (all sections)
  ModuleSelectionResolver: site (page tree or dropdown), language, period, compare (uc + moduleData)
  ReportCatalog: section → report pages → reports (plain PHP arrays, no per-report class)
  ReportService ─► StatisticsCache ─► MatomoClient: ONE API.getBulkRequest per report page
      API.getProcessedReport for every catalog report (+ previous period when compare is on)
  ReportTableFactory: rows → view model (format, change, URL → page via mapper, access filter,
      search/sort/paging in PHP on the cached full report)
  Fluid: Module/Report.html renders a list of
      Partials/Module/ReportTable.html   (the only table)
      Partials/Module/EvolutionChart.html (the only chart)
      Partials/Tile.html                  (existing KPI tiles)
```

A catalog entry is data only:

```php
'devices' => [
    'label' => 'module.report.devices',
    'reports' => [
        ['module' => 'DevicesDetection', 'action' => 'getType'],
        ['module' => 'DevicesDetection', 'action' => 'getBrand'],
        ['module' => 'Resolution', 'action' => 'getResolution'],
    ],
],
'pages' => [
    'label' => 'module.report.pages',
    'reports' => [['module' => 'Actions', 'action' => 'getPageUrls', 'params' => ['flat' => 1], 'rows' => 'url']],
],
```

`'rows' => 'url'` switches on page mapping, page-tree filter and language filter. Reports that `API.getProcessedReport` does not know (it answers "not found in the list of available reports", checked live) get a small adapter and their own template section: Visitors overview (`API.get`, `Live.getCounters`), Page details (`Actions.getPageUrl`, `Transitions.getTransitionsForAction`), Trending pages (`Insights.getMoversAndShakers`) and the Goals section. Everything else is a catalog entry. `flat=1` and similar options go on the `getProcessedReport` sub-request itself, not into `apiParameters` (that breaks the report lookup, checked live).

- **Why `API.getProcessedReport`:** it returns translated column names (`language` of the backend user), metric documentation (column tooltips), `metricTypes`, the report name, totals and per-row metadata with the full `url` (needed for page mapping). So no XLIFF per report, and new reports cost one catalog line. Columns that are not in the report metadata (timing columns, the nested `goals` object) are dropped unless `showRawMetrics=1` is set; catalog entries that need them list them in `columns` with own labels (Performance, goal columns). Checked live.
- **Table paging/sort/search:** the full report (`filter_limit=-1`, capped by Matomo's archive row limit anyway) is fetched once and cached; sorting, search and paging are done in PHP on the cached rows with plain links (`?sort=nb_hits&dir=desc&q=bike&page=2`). No JS, no extra Matomo call per click. 25 rows per page.
- **Formatting:** `format_metrics=0` (raw numbers for sorting), one `MetricFormatter` driven by Matomo's `metricTypes` (`number`, `percent`, `duration_s`, `money`, …); only the explicit `columns` of a catalog entry carry their own type. The bar's `TileFactory` keeps its own formatting.
- **Caching:** existing `StatisticsCache` (1 h, key includes URL + site ID + token hash, report page, period, language, compare, page URL for Page details and the segment definition). Errors are not cached. Transitions ≥ 1 h. Goal list 1 h. `Live.getCounters` gets its own 60 s lifetime, otherwise "last 30 min" could be an hour old.
- **Rendering:** server-side in the module request. The module is the destination, so waiting for one bulk request is acceptable (Matomo's own UI loads widgets one by one; one bulk is faster). Unreachable Matomo → flash message, details for admins only.
- **Docheader dropdowns:** `GeneralUtility::makeInstance(DropDownButton::class)` + `DropDownRadio` works on 13.4 and 14.3. `ButtonBar::make*()`, `MenuRegistry::makeMenu()` and `ComponentFactory` are deprecated (v14) or v14-only, so don't use them.
- **Demo data:** `DemoData` answers `API.getProcessedReport` generically (labels per report from a small list, numbers from the existing seeded generator, URL rows from `DemoPageUrls`). Every report page must show data after `ddev init-typo3`.
- **Tests:** per report page a functional test with a recorded `getProcessedReport` fixture (multi-site, translated URLs), access tests per section (module access) and per custom option, unit tests for the formatter and table paging.

### Chart library: Chart.js from EXT:dashboard

The evolution chart uses the Chart.js that EXT:dashboard ships (`@typo3/dashboard/contrib/chartjs.js`, also mapped as `chart.js` in the import map on v14). EXT:dashboard is already a hard requirement and the traffic widget uses it, so this adds no dependency and no vendored file. It gives tooltips, legends, multiple series, a dashed previous period and responsive sizing for free. Inline SVG (t3g style) stays for the small sparklines in tiles, where it is lighter and already done. One ES module `evolution-chart.js` reads the series from a `data-` attribute; the metric picker switches series client-side (all metrics are already in the payload).

### Spike

A throwaway skeleton (not committed) proved on TYPO3 14.3 in a functional test: a second-level module under `web` with page tree and two third-level modules renders through `ModuleTemplate`, the docheader shows TYPO3's section dropdown with `id` carried over, and a group with `analytics_matomo` + `analytics_matomo_visitors` in `groupMods` sees only that section (without the parent the module is not in the menu). 13.4: the same API exists (`makeDocHeaderModuleMenu`, submodule redirect in `BackendModuleValidator`, core's `web_info` uses the same pattern); checked in the 13.4 source, not run.

## Issues

| Part | Issues | Milestone |
|---|---|---|
| Foundation: module + controls, report table, chart + Visitors overview, mapper, permissions | #37, #38, #39, #26, #17 | 0.3.0 |
| Visitors, Behaviour, Acquisition, site search; widgets as entry points (after their target reports exist) | #41, #42, #43, #44, #24, #40 | 0.4.0 |
| Page details (Transitions, languages, speed, reading time), Goals, trending pages | #45, #14, #18, #21, #20, #25, #22, #23 | 0.5.0 |
| All sites, CSV export, stored segments (+ health/token checks in the Reports module) | #30, #46, #47, #13, #19 | 0.6.0 |
| Annotations as chart markers, content review | #27, #29 | 0.7.0 |
| Visits log | #48 | Backlog |
