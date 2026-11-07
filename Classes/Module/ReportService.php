<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;

/** @phpstan-import-type Report from ReportTableFactory */
final readonly class ReportService
{
    public function __construct(
        private ReportCatalog $catalog,
        private MatomoClient $client,
        private StatisticsCache $cache,
    ) {
    }

    /** @return list<array{current: Report, previous: Report|null}> */
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
            $requests = [];
            foreach ($this->catalog->getReports($section, $page) as $definition) {
                foreach ($compare ? [$period, $period->previous()] : [$period] as $range) {
                    $requests[] = [
                        'method' => 'API.getProcessedReport', 'apiModule' => $definition['module'], 'apiAction' => $definition['action'],
                        'period' => 'range', 'date' => $range->toMatomoDate(), 'format_metrics' => 0, 'filter_limit' => -1,
                    ] + ($definition['params'] ?? []);
                }
            }
            if ($requests === []) {
                return [];
            }
            $results = array_map($this->validate(...), $this->client->bulkRequest($configuration, $requests, $language));
            $reports = [];
            for ($index = 0; $index < count($results); $index += $compare ? 2 : 1) {
                $reports[] = ['current' => $results[$index], 'previous' => $compare ? $results[$index + 1] : null];
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
