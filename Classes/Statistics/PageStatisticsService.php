<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use TYPO3\CMS\Core\Context\Context;

/**
 * Fetches the numbers of one page for the last N days, the N days before and
 * each single day, in one bulk request. Results are cached per page, period and day.
 */
final readonly class PageStatisticsService
{
    public function __construct(
        private MatomoClient $client,
        private Context $context,
        private StatisticsCache $cache,
    ) {
    }

    /**
     * @throws MatomoApiException
     */
    public function getStatistics(MatomoConfiguration $configuration, string $pageUrl, int $days): PageStatistics
    {
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $period = Period::lastDays($days, $now);

        return $this->cache->remember(
            ['page', $configuration->getCacheIdentifier(), $pageUrl, $period->toMatomoDate()],
            fn (): PageStatistics => $this->fetch($configuration, $pageUrl, $period),
        );
    }

    /**
     * @throws MatomoApiException
     */
    private function fetch(MatomoConfiguration $configuration, string $pageUrl, Period $period): PageStatistics
    {
        $request = [
            'method' => 'Actions.getPageUrl',
            'pageUrl' => $pageUrl,
            'format_metrics' => 0,
        ];
        [$current, $previous, $daily] = $this->client->bulkRequest($configuration, [
            $request + ['period' => 'range', 'date' => $period->toMatomoDate()],
            $request + ['period' => 'range', 'date' => $period->previous()->toMatomoDate()],
            $request + ['period' => 'day', 'date' => $period->toMatomoDate()],
        ]);

        // Matomo answers a day range with a map of date => rows
        if (!is_array($daily) || ($daily !== [] && array_is_list($daily))) {
            throw new MatomoApiException('Matomo answered with an unexpected daily result', 1793960007);
        }
        $dailyMetrics = [];
        foreach ($period->dates() as $date) {
            $dailyMetrics[$date] = PageMetrics::fromApiResult($daily[$date] ?? []);
        }

        return new PageStatistics(
            PageMetrics::fromApiResult($current),
            PageMetrics::fromApiResult($previous),
            $dailyMetrics,
        );
    }
}
