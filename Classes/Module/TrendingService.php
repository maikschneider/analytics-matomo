<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewSource;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewStore;

/**
 * Pages that gained or lost views against the previous period. Matomo's Insights
 * plugin ranks only the top level of the page report (folders, not pages), so the
 * flat page reports of both periods are compared here, with Insights' default
 * thresholds plus a minimum difference against noise on small sites. Both periods
 * come from the page view store when it holds them.
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
        private PageViewStore $store,
    ) {
    }

    /**
     * @return array{rising: list<array<string, mixed>>, falling: list<array<string, mixed>>, source: ?PageViewSource} rows with url, value_old, value_new, difference and growth
     * @throws MatomoApiException
     */
    public function getTrending(MatomoConfiguration $configuration, Period $period): array
    {
        return $this->cache->remember(['trending', $configuration->getCacheIdentifier(), $period->toMatomoDate()], function () use ($configuration, $period): array {
            $urls = [];
            $source = $this->store->source($configuration, null, $period, $period->previous());
            if ($source !== null && $source->isComplete) {
                $views = $this->store->hitsByUrl($source, $period);
                $before = $this->store->hitsByUrl($source, $period->previous());
                $total = $source->of($period)['coverage']->totalHits;
                // Only URLs with a known total in both periods count; a total is unknown when the URL is missing on a truncated day
                $known = [$this->store->completeUrls($source, $period), $this->store->completeUrls($source, $period->previous())];
                $keys = array_keys(array_filter(
                    $views + $before,
                    static fn (int $key): bool => ($known[0] === null || isset($known[0][$key])) && ($known[1] === null || isset($known[1][$key])),
                    ARRAY_FILTER_USE_KEY,
                ));
            } else {
                $request = ['method' => 'Actions.getPageUrls', 'period' => 'range', 'flat' => 1, 'format_metrics' => 0, 'filter_limit' => -1, 'showColumns' => 'nb_hits'];
                [$current, $previous] = $this->client->bulkRequest($configuration, [
                    $request + ['date' => $period->toMatomoDate()],
                    $request + ['date' => $period->previous()->toMatomoDate()],
                ]);
                [$views, $urls] = $this->views($current);
                [$before, $previousUrls] = $this->views($previous);
                $urls += $previousUrls;
                $total = array_sum(array_map(static fn (mixed $row): int => is_array($row) ? (int)($row['nb_hits'] ?? 0) : 0, is_array($current) ? $current : []));
                // A page missing from a truncated report may hide in its summary row, so only pages of both periods count
                $keys = $this->isTruncated($current) || $this->isTruncated($previous) ? array_keys(array_intersect_key($views, $before)) : array_keys($views + $before);
            }
            $trends = [];
            foreach ($keys as $key) {
                $old = $before[$key] ?? 0;
                $new = $views[$key] ?? 0;
                $trend = self::trend($old, $new, $total);
                if ($trend !== null) {
                    $trends[$key] = [$trend, $old, $new];
                }
            }
            if ($source !== null && $source->isComplete) {
                $urls = $this->store->displayUrls(array_map(intval(...), array_keys($trends)));
            }
            $rising = $falling = [];
            foreach ($trends as $key => [$trend, $old, $new]) {
                $url = $urls[$key] ?? '';
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
            return ['rising' => $rising, 'falling' => $falling, 'source' => $source];
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
     * @return array{array<string, int>, array<string, string>} page views and the first URL seen, by URL without scheme; Matomo splits a URL tracked under http and https
     */
    private function views(mixed $rows): array
    {
        $views = $urls = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['url']) && !PageUrlMapper::isSummaryRow($row)) {
                $key = (string)preg_replace('#^https?://#', '', (string)$row['url']);
                $urls[$key] ??= (string)$row['url'];
                $views[$key] = ($views[$key] ?? 0) + (int)($row['nb_hits'] ?? 0);
            }
        }
        return [$views, $urls];
    }
}
