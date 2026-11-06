<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;

/**
 * Fetches the numbers of one page for the last N days, the N days before and
 * each single day, in one bulk request. Results are cached per page, period and day.
 */
final readonly class PageStatisticsService
{
    public const PERIODS = [7, 14, 30];

    public function __construct(
        private MatomoClient $client,
        private Context $context,
        #[Autowire(service: 'cache.analytics_matomo')]
        private FrontendInterface $cache,
    ) {
    }

    public static function normalizeDays(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : self::PERIODS[0];
    }

    /**
     * @throws MatomoApiException
     */
    public function getStatistics(MatomoConfiguration $configuration, string $pageUrl, int $days): PageStatistics
    {
        $days = self::normalizeDays($days);
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $today = $now->setTime(0, 0);
        $from = $today->modify('-' . ($days - 1) . ' days');
        $previousTo = $from->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days');

        $cacheIdentifier = sha1(implode('|', [$configuration->url, $configuration->idSite, $pageUrl, $days, $today->format('Y-m-d')]));
        $cached = $this->cache->get($cacheIdentifier);
        if ($cached instanceof PageStatistics) {
            return $cached;
        }

        $request = [
            'method' => 'Actions.getPageUrl',
            'pageUrl' => $pageUrl,
            'format_metrics' => 0,
        ];
        [$current, $previous, $daily] = $this->client->bulkRequest($configuration, [
            $request + ['period' => 'range', 'date' => $this->range($from, $today)],
            $request + ['period' => 'range', 'date' => $this->range($previousFrom, $previousTo)],
            $request + ['period' => 'day', 'date' => $this->range($from, $today)],
        ]);

        // Matomo answers a day range with a map of date => rows
        if (!is_array($daily) || ($daily !== [] && array_is_list($daily))) {
            throw new MatomoApiException('Matomo answered with an unexpected daily result', 1793960007);
        }
        $dailyMetrics = [];
        for ($day = $from; $day <= $today; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $dailyMetrics[$date] = PageMetrics::fromApiResult($daily[$date] ?? []);
        }

        $statistics = new PageStatistics(
            PageMetrics::fromApiResult($current),
            PageMetrics::fromApiResult($previous),
            $dailyMetrics,
        );
        $this->cache->set($cacheIdentifier, $statistics);

        return $statistics;
    }

    private function range(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $from->format('Y-m-d') . ',' . $to->format('Y-m-d');
    }
}
