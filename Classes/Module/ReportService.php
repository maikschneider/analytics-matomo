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

    /** @return Report */
    private function validate(mixed $result): array
    {
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
