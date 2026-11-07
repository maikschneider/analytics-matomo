# Matomo report fixtures

`ProcessedChannels.json` and `ProcessedPages.json` are recorded responses from anonymous requests to `https://demo.matomo.org/`:

- `method=API.getProcessedReport`, `idSite=1`, `period=range`, `date=2026-10-01,2026-10-31`
- `format=json`, `format_metrics=0`, `filter_limit=10`, `flat=1`
- `apiModule` / `apiAction`: `Referrers/getReferrerType`, `Actions/getPageUrls`

Each response is wrapped in a list, matching the bulk client's decoded result. Metric types and metric documentation live inside `metadata`; `reportMetadata` is a separate, index-aligned array with row URLs and other attributes. Percent metrics are raw ratios.

`Processed<Section><Page>.json` (e.g. `ProcessedVisitorsLocations.json`) hold one recorded response per report of a module report page, in catalog order, with the same parameters but `idSite=3` and without `flat=1`. Reports without a dimension (`VisitFrequency.get`) answer with a single row object instead of a list.

`SyntheticProcessedPages.json` follows that response shape but uses the functional tests' two TYPO3 sites, translated URLs, unknown pages and an archive summary row. Its second report is the previous period. `SyntheticProcessedUnsafe.json` tests Fluid escaping of untrusted names, labels and documentation; `SyntheticProcessedInvalid.json` tests malformed-response rejection and retry. These synthetic fixtures are not recordings.

`SyntheticOverview.json` is hand-crafted, not recorded. It contains named `API.get` range and date-map responses (current and previous) and a one-row `Live.getCounters` response. The HTTP test handler selects these responses by method, period and date and wraps them as a bulk response. Missing evolution buckets and a missing live visitors value deliberately exercise unknown values. Calendar week response keys (`from,to`) and the one-row live response shape were also checked against anonymous `demo.matomo.org` requests; those probes are not committed fixtures.

`ProcessedBehaviourPerformance.json` asks the pages report with `showRawMetrics=1`, which adds the timing columns. `SyntheticProcessedPerformance.json` keeps its recorded overview and replaces the pages with URLs of the functional test sites.

`ProcessedBehaviourOutlinks/Downloads/Events/Contents.json` use `date=2026-01-01,2026-11-06`, because the demo site tracks interactions only rarely. Downloads and contents have no rows on any demo site; their fixtures prove the empty state, and the demo data use the column names of the recorded metadata.

`ProcessedAcquisition*.json` are recorded like the visitor reports (site 3, October 2026, not flat). The demo has no core `Referrers.getCampaigns` (the MarketingCampaignsReporting plugin replaces it), so `SyntheticProcessedCampaigns.json` reuses the recorded referrer metadata with made-up campaign rows. `MetadataAvailability.json` is a recorded `API.getBulkRequest` of `API.getMetadata` for `Referrers.getAIAssistants` (present) and `Referrers.getCampaigns` (missing).

`ProcessedBehaviourSiteSearch.json` is recorded with `date=2026-01-01,2026-11-06`; the demo has no site search rows, so `SyntheticProcessedSiteSearch.json` adds made-up rows to the recorded metadata.

`Transitions.json` is a recorded `Transitions.getTransitionsForAction` (`actionType=url`, `actionName=http://www.virtual-drums.com/videos.php`, site 3, `date=2026-01-01,2026-11-06`) from an anonymous bulk request; page labels have no scheme, outlink labels keep it. `TransitionsNoData.json` is the recorded answer for a page without visits: the error `NoDataForAction` instead of an empty result. The demo has no downloads on any page, so `SyntheticTransitions.json` replaces the pages of the recording with URLs of the functional test sites and adds a made-up download in the shape of the outlinks.

`Goals.json` holds recorded answers of site 3 for 2026-10-27,2026-11-02 (anonymous bulk request, `format_metrics=0`): `Goals.getGoals`, `Goals.get` as range and per day, for all goals and per goal, and `Referrers.getReferrerType` and flat `Actions.getEntryPageUrls` with `idGoal=1`. Matomo sums the daily rates of the nested `goals` columns on range periods (`nb_conversions_entry_rate` is 0.7 for 2 conversions in 25 entrances). `SyntheticGoalEntryPages.json` is the recorded entry-page answer with the URLs of the functional test site.

`GoalChannelsAttribution.json` is a recorded `Referrers.getReferrerType` of site 3 with `idGoal=2`, `period=range`, `date=2026-01-01,2026-11-06`, `format_metrics=0`, `filter_limit=-1` (anonymous request). Matomo credits conversions to the referrer saved with them, so its campaigns row has 6 conversions of goal 2 (also 6 visits converted) in 2 visits.

`SyntheticProcessedLinks.json` keeps the recorded outlinks metadata with made-up links: external, into the other test site (page and file), a file of the site, an unreadable page and a German page.

`Trending.json` holds the flat `Actions.getPageUrls` of site 3 for 2026-10-27,2026-11-02 (`current`) and the week before (`previous`), reduced to `nb_hits` (`showColumns=nb_hits`). `SyntheticTrending.json` maps such rows to the test site, with a rising, a new, a falling, a vanished page and pages below the thresholds.

`Segments.json` is the recorded `SegmentEditor.getAll` of site 3 (anonymous request): one pre-processed "Mobile users" segment. `SyntheticSegments.json` adds a segment for all sites, one processed in real time (`auto_archive=0`) and one of another site. `ProcessedVisitorsDevicesSegment.json` is the device type report of site 3 for October 2026 recorded with `segment=deviceType==Smartphone,deviceType==Tablet`.
