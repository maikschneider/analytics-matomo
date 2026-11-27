<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewSource;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewStore;

/**
 * Processed reports of a module report page, one cached bulk request per page. A report
 * of the catalog marked `store` is built from the page view store when it holds every
 * period shown; it then shows the STORE_ROWS most viewed URLs.
 *
 * @phpstan-import-type Report from ReportTableFactory
 */
final readonly class ReportService
{
    public const STORE_ROWS = 1000;

    public function __construct(
        private ReportCatalog $catalog,
        private MatomoClient $client,
        private StatisticsCache $cache,
        private PageViewStore $store,
    ) {
    }

    /** @return list<array{current: Report, previous: Report|null, source: ?PageViewSource}> */
    public function getReports(MatomoConfiguration $configuration, string $section, string $page, Period $period, bool $compare, string $language): array
    {
        if ($section === 'behaviour' && $page === 'siteSearch') {
            $availability = $this->cache->remember(['siteSearchAvailability', $configuration->getCacheIdentifier()], function () use ($configuration): array {
                [$metadata] = $this->client->bulkRequest($configuration, [['method' => 'API.getMetadata', 'apiModule' => 'Actions', 'apiAction' => 'getSiteSearchKeywords']]);
                return ['available' => is_array($metadata) && $metadata !== [] && array_is_list($metadata)];
            });
            if (!$availability['available']) {
                return [];
            }
        }
        return $this->cache->remember(['module', $configuration->getCacheIdentifier(), $section, $page, $period->toMatomoDate(), $compare, $language], function () use ($configuration, $section, $page, $period, $compare, $language): array {
            $definitions = $this->catalog->getReports($section, $page);
            $periods = $compare ? [$period, $period->previous()] : [$period];
            $source = array_filter(array_column($definitions, 'store')) === [] ? null : $this->store->source($configuration, null, ...$periods);
            $complete = $source !== null && $source->isComplete ? $source : null;
            $requests = [];
            foreach ($definitions as $definition) {
                if ($complete !== null && ($definition['store'] ?? false)) {
                    // Only the names of the report and its columns, in the backend language
                    $requests[] = ['method' => 'API.getMetadata', 'apiModule' => $definition['module'], 'apiAction' => $definition['action']];
                    continue;
                }
                foreach ($periods as $range) {
                    $requests[] = [
                        'method' => 'API.getProcessedReport', 'apiModule' => $definition['module'], 'apiAction' => $definition['action'],
                        'period' => 'range', 'date' => $range->toMatomoDate(), 'format_metrics' => 0, 'filter_limit' => -1,
                    ] + ($definition['params'] ?? []);
                }
            }
            if ($requests === []) {
                return [];
            }
            $results = $this->client->bulkRequest($configuration, $requests, $language);
            $reports = [];
            foreach ($definitions as $definition) {
                if ($complete !== null && ($definition['store'] ?? false)) {
                    $metadata = $this->metadata(array_shift($results));
                    // Matomo's summary rows of truncated days are stored with their page views only
                    $visitsShare = array_filter($periods, static fn (Period $range): bool => $complete->of($range)['coverage']->isTruncated()) === [];
                    $reports[] = [
                        'current' => self::storeReport($metadata, $this->store->urlRows($complete, $period, self::STORE_ROWS), $visitsShare),
                        'previous' => $compare ? self::storeReport($metadata, $this->store->urlRows($complete, $period->previous(), self::STORE_ROWS), $visitsShare) : null,
                        'source' => $complete,
                    ];
                    continue;
                }
                $reports[] = [
                    'current' => $this->validate(array_shift($results)),
                    'previous' => $compare ? $this->validate(array_shift($results)) : null,
                    'source' => ($definition['store'] ?? false) ? $source : null,
                ];
            }
            return $reports;
        });
    }

    /**
     * @param array<string, string> $pages page => label
     * @return array<string, string> the pages whose first report the Matomo site offers
     */
    public function filterAvailablePages(MatomoConfiguration $configuration, string $section, array $pages): array
    {
        $optional = array_intersect_key($this->catalog->getOptionalPages($section), $pages);
        if ($optional === []) {
            return $pages;
        }
        $availability = $this->cache->remember(['availability', $configuration->getCacheIdentifier(), implode(',', array_keys($optional))], function () use ($configuration, $optional): array {
            $requests = array_values(array_map(static fn (array $definition): array => ['method' => 'API.getMetadata', 'apiModule' => $definition['module'], 'apiAction' => $definition['action']], $optional));
            // Matomo answers ['value' => false] for a report it does not have, a list of report metadata otherwise
            $results = $this->client->bulkRequest($configuration, $requests);
            return ['missing' => array_keys(array_filter(array_combine(array_keys($optional), $results), static fn (mixed $result): bool => !is_array($result) || $result === [] || !array_is_list($result)))];
        });
        return array_diff_key($pages, array_flip($availability['missing']));
    }

    /**
     * The report metadata of API.getMetadata
     *
     * @return array<string, mixed>
     */
    private function metadata(mixed $result): array
    {
        $metadata = is_array($result) && array_is_list($result) ? $result[0] ?? null : null;
        if (!is_array($metadata) || !is_array($metadata['metrics'] ?? null)) {
            throw new MatomoApiException('Matomo answered with unexpected report metadata', 1794600101);
        }
        return $metadata;
    }

    /**
     * A processed page report from rows of the page view store (PageViewStore::urlRows()),
     * with the columns Matomo's own has
     *
     * @param array<string, mixed> $metadata
     * @param list<array<string, mixed>> $rows
     * @param bool $visitsShare false when the visits of the summary row are unknown
     * @return Report
     */
    private static function storeReport(array $metadata, array $rows, bool $visitsShare): array
    {
        $total = [];
        foreach (PageMetrics::COUNTS as $column) {
            $total[$column] = (int)array_sum(array_column($rows, $column));
        }
        $shares = $visitsShare ? ['nb_hits', 'nb_visits'] : ['nb_hits'];
        $data = $rowMetadata = [];
        foreach ($rows as $row) {
            foreach ($shares as $column) {
                $row[$column . '_percent_of_total'] = $total[$column] > 0 ? round((int)($row[$column] ?? 0) / $total[$column], 3) : 0;
            }
            $rowMetadata[] = isset($row['url']) ? ['url' => $row['url']] : [];
            unset($row['url']);
            $data[] = $row;
        }
        $names = (array)$metadata['metrics'] + (array)($metadata['processedMetrics'] ?? []);
        $columns = ['label' => (string)($metadata['dimension'] ?? '')];
        foreach (['nb_hits', 'nb_visits'] as $column) {
            $columns[$column] = (string)($names[$column] ?? $column);
            if (in_array($column, $shares, true)) {
                $columns[$column . '_percent_of_total'] = $columns[$column] . ' (%)';
                $metadata['metricTypes'][$column . '_percent_of_total'] = 'percent';
            }
        }
        foreach (['avg_time_on_page', 'bounce_rate', 'exit_rate'] as $column) {
            $columns[$column] = (string)($names[$column] ?? $column);
        }
        return ['metadata' => $metadata, 'columns' => $columns, 'reportData' => $data, 'reportMetadata' => $rowMetadata, 'reportTotal' => $total];
    }

    /** @return Report */
    private function validate(mixed $result): array
    {
        // Reports without a dimension (e.g. VisitFrequency.get) answer with one row instead of a list
        if (is_array($result) && is_array($result['reportData'] ?? null) && !array_is_list($result['reportData'])) {
            $result['reportData'] = [$result['reportData']];
        }
        if (!is_array($result) || !is_array($result['metadata'] ?? null) || !is_array($result['columns'] ?? null)
            || !is_array($result['reportData'] ?? null) || !array_is_list($result['reportData'])
            || !is_array($result['reportMetadata'] ?? null) || !is_array($result['reportTotal'] ?? null)) {
            throw new MatomoApiException('Matomo answered with an unexpected processed report', 1793960401);
        }
        foreach ($result['columns'] as $label) {
            if (!is_string($label)) {
                throw new MatomoApiException('Matomo answered with an unexpected report column', 1793960402);
            }
        }
        foreach ([...$result['reportData'], ...$result['reportMetadata']] as $row) {
            if (!is_array($row)) {
                throw new MatomoApiException('Matomo answered with an unexpected report row', 1793960403);
            }
        }
        /** @var Report $result */
        return $result;
    }
}
