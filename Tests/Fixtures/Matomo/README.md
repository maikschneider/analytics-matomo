# Processed report fixtures

`ProcessedDevices.json`, `ProcessedChannels.json` and `ProcessedPages.json` are recorded responses from anonymous requests to `https://demo.matomo.org/`:

- `method=API.getProcessedReport`, `idSite=1`, `period=range`, `date=2026-10-01,2026-10-31`
- `format=json`, `format_metrics=0`, `filter_limit=10`, `flat=1`
- `apiModule` / `apiAction`: `DevicesDetection/getType`, `Referrers/getReferrerType`, `Actions/getPageUrls`

Each response is wrapped in a list, matching the bulk client's decoded result. Metric types and metric documentation live inside `metadata`; `reportMetadata` is a separate, index-aligned array with row URLs and other attributes. Percent metrics are raw ratios.

`SyntheticProcessedPages.json` follows that response shape but uses the functional tests' two TYPO3 sites, translated URLs, unknown pages and an archive summary row. Its second report is the previous period. `SyntheticProcessedUnsafe.json` tests Fluid escaping of untrusted names, labels and documentation; `SyntheticProcessedInvalid.json` tests malformed-response rejection and retry. These synthetic fixtures are not recordings.
