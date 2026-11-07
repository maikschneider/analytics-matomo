<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;

/**
 * How visitors reach a page and where they continue, from Matomo's Transitions
 */
final readonly class TransitionsService
{
    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
    ) {
    }

    /**
     * @return array<string, mixed>|null null if Matomo has no data for the page in that period
     * @throws MatomoApiException
     */
    public function getTransitions(MatomoConfiguration $configuration, string $pageUrl, Period $period): ?array
    {
        return $this->cache->remember(['transitions', $configuration->getCacheIdentifier(), $pageUrl, $period->toMatomoDate()], function () use ($configuration, $pageUrl, $period): ?array {
            try {
                [$result] = $this->client->bulkRequest($configuration, [[
                    'method' => 'Transitions.getTransitionsForAction', 'actionType' => 'url', 'actionName' => $pageUrl,
                    'period' => 'range', 'date' => $period->toMatomoDate(), 'format_metrics' => 0,
                ]]);
            } catch (MatomoApiException $exception) {
                // Matomo answers a page without visits in that period with this error instead of an empty result
                if ($exception->getMessage() === 'Matomo API error: NoDataForAction') {
                    return null;
                }
                throw $exception;
            }
            return is_array($result) ? $result : null;
        });
    }
}
