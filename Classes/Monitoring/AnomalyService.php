<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Monitoring;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use TYPO3\CMS\Core\Context\Context;

/**
 * Daily snapshots; displaying a badge never contacts Matomo.
 *
 * @phpstan-type Alert array{metric: string, date: string, current: float, baseline: float, change: float}
 */
final readonly class AnomalyService
{
    public function __construct(private MatomoClient $client, private StatisticsCache $cache, private Context $context)
    {
    }

    /** @return list<Alert> */
    public function getCached(MatomoConfiguration $configuration): array
    {
        return $this->cache->get($this->key($configuration))['alerts'] ?? [];
    }

    public function refresh(MatomoConfiguration $configuration): void
    {
        [$series] = $this->client->bulkRequest($configuration, [[
            'method' => 'VisitsSummary.get', 'period' => 'day', 'date' => 'previous29',
            'showColumns' => 'nb_visits,bounce_rate', 'format_metrics' => 0,
        ]]);
        if (!is_array($series) || $series === [] || array_is_list($series)) {
            throw new MatomoApiException('Matomo answered with an unexpected daily visits report', 1794101001);
        }
        foreach ($series as $date => $row) {
            if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || !is_array($row)) {
                throw new MatomoApiException('Matomo answered with an unexpected daily visits row', 1794101002);
            }
        }
        $this->cache->set($this->key($configuration), ['alerts' => AnomalyDetector::detect($series)], 86400);
    }

    /** @return list<string> */
    private function key(MatomoConfiguration $configuration): array
    {
        return ['anomalies', $configuration->getCacheIdentifier(), gmdate('Y-m-d', (int)$this->context->getPropertyFromAspect('date', 'timestamp'))];
    }
}
