<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;

/**
 * Pages that gained or lost views against the previous period. Matomo's Insights
 * plugin ranks only the top level of the page report (folders, not pages), so the
 * flat page reports of both periods are compared here, with Insights' default
 * thresholds plus a minimum difference against noise on small sites.
 */
final readonly class TrendingService
{
    public const MIN_GROWTH_PERCENT = 20;
    public const MIN_IMPACT_PERCENT = 2;
    public const MIN_DIFFERENCE = 3;
    public const COLUMNS = [
        'label' => ['column.page', 'string'],
        'value_old' => ['column.value_old', 'number'],
        'value_new' => ['column.value_new', 'number'],
        'difference' => ['column.difference', 'difference'],
        'growth' => ['column.growth', 'growth'],
    ];

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
    ) {
    }

    /**
     * @return array{rising: list<array<string, mixed>>, falling: list<array<string, mixed>>} rows with url, value_old, value_new, difference and growth
     * @throws MatomoApiException
     */
    public function getTrending(MatomoConfiguration $configuration, Period $period): array
    {
        return $this->cache->remember(['trending', $configuration->getCacheIdentifier(), $period->toMatomoDate()], function () use ($configuration, $period): array {
            $request = ['method' => 'Actions.getPageUrls', 'period' => 'range', 'flat' => 1, 'format_metrics' => 0, 'filter_limit' => -1, 'showColumns' => 'nb_hits'];
            [$current, $previous] = $this->client->bulkRequest($configuration, [
                $request + ['date' => $period->toMatomoDate()],
                $request + ['date' => $period->previous()->toMatomoDate()],
            ]);
            $views = $this->views($current);
            $before = $this->views($previous);
            $total = array_sum(array_column($views, 1));
            // A page missing from a truncated report may hide in its summary row, so only pages of both periods count
            $keys = $this->isTruncated($current) || $this->isTruncated($previous) ? array_keys(array_intersect_key($views, $before)) : array_keys($views + $before);
            $rising = $falling = [];
            foreach ($keys as $key) {
                $url = $views[$key][0] ?? $before[$key][0];
                $old = $before[$key][1] ?? 0;
                $new = $views[$key][1] ?? 0;
                $trend = self::trend($old, $new, $total);
                if ($trend === null) {
                    continue;
                }
                $row = [
                    'label' => (string)(parse_url($url, PHP_URL_PATH) ?: '/'), 'url' => $url,
                    'value_old' => $old, 'value_new' => $new, 'difference' => $new - $old, 'growth' => $old > 0 ? ($new - $old) / $old : null,
                ];
                if ($trend === 'up') {
                    $rising[] = $row;
                } else {
                    $falling[] = $row;
                }
            }
            usort($rising, static fn (array $a, array $b): int => $b['difference'] <=> $a['difference']);
            usort($falling, static fn (array $a, array $b): int => $a['difference'] <=> $b['difference']);
            return ['rising' => $rising, 'falling' => $falling];
        });
    }

    /**
     * @param int $total page views of the site in the current period
     * @return 'up'|'down'|null
     */
    public static function trend(int $old, int $new, int $total): ?string
    {
        $difference = $new - $old;
        if (abs($difference) < max(self::MIN_DIFFERENCE, $total * self::MIN_IMPACT_PERCENT / 100)) {
            return null;
        }
        if ($old > 0 && abs($difference) * 100 < $old * self::MIN_GROWTH_PERCENT) {
            return null;
        }
        return $difference > 0 ? 'up' : 'down';
    }

    private function isTruncated(mixed $rows): bool
    {
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && PageUrlMapper::isSummaryRow($row)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, array{string, int}> URL and page views by URL without scheme; Matomo splits a URL tracked under http and https
     */
    private function views(mixed $rows): array
    {
        $views = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['url']) && !PageUrlMapper::isSummaryRow($row)) {
                $key = (string)preg_replace('#^https?://#', '', (string)$row['url']);
                $views[$key] = [$views[$key][0] ?? (string)$row['url'], ($views[$key][1] ?? 0) + (int)($row['nb_hits'] ?? 0)];
            }
        }
        return $views;
    }
}
