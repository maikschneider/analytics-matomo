<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;

/**
 * Turns the flat page report of one day (Actions.getPageUrls with period=day,
 * flat=1, format_metrics=0) into the rows of the store. URLs are merged by
 * PageUrlMapper::normalize() without the scheme, so http/https and query
 * variants other than the page ID count for one URL, as on the live path.
 */
final class UrlDayRows
{
    /**
     * @throws MatomoApiException
     */
    public static function fromReport(mixed $result): UrlDay
    {
        if (!is_array($result) || !array_is_list($result)) {
            throw self::unexpected($result);
        }
        $rows = [];
        $displayHits = [];
        $totalHits = 0;
        $summaryHits = 0;
        foreach ($result as $row) {
            if (!is_array($row)) {
                throw self::unexpected($result);
            }
            $counts = [];
            foreach (PageMetrics::COUNTS as $column) {
                $value = $row[$column] ?? 0;
                if (!is_numeric($value)) {
                    throw self::unexpected($result);
                }
                $counts[$column] = (int)$value;
            }
            $url = $row['url'] ?? '';
            if (!is_string($url)) {
                throw self::unexpected($result);
            }

            $totalHits += $counts['nb_hits'];
            if (PageUrlMapper::isSummaryRow($row)) {
                $summaryHits += $counts['nb_hits'];
                continue;
            }
            $key = self::key($url);
            if ($key === null) {
                continue;
            }
            $hash = sha1($key);
            if (!isset($rows[$hash])) {
                $rows[$hash] = ['url' => $url, 'counts' => $counts];
                $displayHits[$hash] = $counts['nb_hits'];
                continue;
            }
            foreach ($counts as $column => $value) {
                $rows[$hash]['counts'][$column] = ($rows[$hash]['counts'][$column] ?? 0) + $value;
            }
            // The busiest variant is shown; the first one seen wins a tie
            if ($counts['nb_hits'] > $displayHits[$hash]) {
                $rows[$hash]['url'] = $url;
                $displayHits[$hash] = $counts['nb_hits'];
            }
        }

        return new UrlDay(
            array_map(static fn (array $row): UrlDayRow => new UrlDayRow($row['url'], $row['counts']), $rows),
            $totalHits,
            $summaryHits,
        );
    }

    /**
     * The URL as the store compares it: normalized, without scheme
     * (e.g. "www.example.org/path?id=5"); null when it is not a URL.
     */
    public static function key(string $url): ?string
    {
        $normalized = PageUrlMapper::normalize($url);
        if ($normalized === null) {
            return null;
        }
        return preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $normalized) ?? $normalized;
    }

    /**
     * The hash of the key, as stored in tx_analyticsmatomo_url.url_hash; null when it is not a URL.
     */
    public static function hash(string $url): ?string
    {
        $key = self::key($url);
        return $key === null ? null : sha1($key);
    }

    private static function unexpected(mixed $result): MatomoApiException
    {
        return new MatomoApiException('Matomo answered with an unexpected page report of a day: ' . mb_substr((string)json_encode($result), 0, 200), 1794500001);
    }
}
