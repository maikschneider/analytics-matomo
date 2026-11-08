<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Monitoring;

/**
 * Compares the last closed day with the median of the four preceding same weekdays.
 */
final class AnomalyDetector
{
    public const MINIMUM_VISITS = 50;
    public const TRAFFIC_THRESHOLD = 40;
    public const BOUNCE_THRESHOLD = 20;

    /**
     * @param array<string, array<string, mixed>> $series closed days only, in the Matomo site's timezone
     * @return list<array{metric: string, date: string, current: float, baseline: float, change: float}>
     */
    public static function detect(array $series): array
    {
        if ($series === []) {
            return [];
        }
        ksort($series);
        $date = array_key_last($series);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            return [];
        }
        $current = $series[$date];
        $baseline = [];
        for ($week = 1; $week <= 4; $week++) {
            $row = $series[$day->modify('-' . ($week * 7) . ' days')->format('Y-m-d')] ?? null;
            if (!is_array($row) || !is_numeric($row['nb_visits'] ?? null)) {
                return [];
            }
            $baseline[] = $row;
        }
        $visits = self::median(array_column($baseline, 'nb_visits'));
        if ($visits < self::MINIMUM_VISITS || !is_numeric($current['nb_visits'] ?? null)) {
            return [];
        }
        $alerts = [];
        $change = ((float)$current['nb_visits'] / $visits - 1) * 100;
        if (abs($change) >= self::TRAFFIC_THRESHOLD) {
            $alerts[] = ['metric' => 'visits', 'date' => $date, 'current' => (float)$current['nb_visits'], 'baseline' => $visits, 'change' => $change];
        }
        $bounces = array_column($baseline, 'bounce_rate');
        if ((int)$current['nb_visits'] >= self::MINIMUM_VISITS && min(array_column($baseline, 'nb_visits')) >= self::MINIMUM_VISITS && count($bounces) === 4 && is_numeric($current['bounce_rate'] ?? null)) {
            $bounce = self::median($bounces);
            $difference = ((float)$current['bounce_rate'] - $bounce) * 100;
            if (abs($difference) >= self::BOUNCE_THRESHOLD) {
                $alerts[] = ['metric' => 'bounceRate', 'date' => $date, 'current' => (float)$current['bounce_rate'], 'baseline' => $bounce, 'change' => $difference];
            }
        }
        return $alerts;
    }

    /** @param list<int|float|string> $values */
    private static function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        return ((float)$values[1] + (float)$values[2]) / 2;
    }
}
