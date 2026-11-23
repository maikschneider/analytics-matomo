<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use TYPO3\CMS\Core\Context\Context;

/**
 * Fetches the numbers of one page for the last N days, the N days before and
 * each single day from one day-by-day request. Results are cached per page, period and day.
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
        [$days] = $this->client->bulkRequest($configuration, [[
            'method' => 'Actions.getPageUrl',
            'pageUrl' => $pageUrl,
            'format_metrics' => 0,
            'period' => 'day',
            'date' => $period->withPrevious()->toMatomoDate(),
        ]]);

        $daily = [];
        foreach ($period->dates() as $date) {
            $daily[$date] = PageMetrics::fromDays($days, [$date]);
        }

        return new PageStatistics(
            PageMetrics::fromDays($days, $period->dates()),
            PageMetrics::fromDays($days, $period->previous()->dates()),
            $daily,
        );
    }
}
