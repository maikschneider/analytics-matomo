<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

/**
 * Turns page statistics into the four tiles of the statistics bar: formatted
 * value, change against the previous period and a sparkline of the single days.
 */
final class TileFactory
{
    private const SPARKLINE_WIDTH = 100;
    private const SPARKLINE_HEIGHT = 30;

    /**
     * @return list<Tile>
     */
    public function create(PageStatistics $statistics): array
    {
        $current = $statistics->current;
        $previous = $statistics->previous;
        $daily = array_values($statistics->daily);

        return [
            $this->tile(
                'views',
                'actions-eye',
                $this->formatNumber($current->views),
                $current->views - $previous->views,
                $this->formatSignedNumber($current->views - $previous->views),
                true,
                array_map(static fn (PageMetrics $day): float => $day->views, $daily),
            ),
            $this->tile(
                'bounceRate',
                'actions-sign-out',
                $this->formatPercent($current->bounceRate),
                $current->bounceRate - $previous->bounceRate,
                $this->formatSignedPercent($current->bounceRate - $previous->bounceRate),
                false,
                array_map(static fn (PageMetrics $day): float => $day->bounceRate, $daily),
            ),
            $this->tile(
                'averageTimeOnPage',
                'actions-clock',
                $this->formatDuration($current->averageTimeOnPage),
                $current->averageTimeOnPage - $previous->averageTimeOnPage,
                $this->formatSignedDuration($current->averageTimeOnPage - $previous->averageTimeOnPage),
                true,
                array_map(static fn (PageMetrics $day): float => $day->averageTimeOnPage, $daily),
            ),
            $this->tile(
                'exitRate',
                'actions-logout',
                $this->formatPercent($current->exitRate),
                $current->exitRate - $previous->exitRate,
                $this->formatSignedPercent($current->exitRate - $previous->exitRate),
                false,
                array_map(static fn (PageMetrics $day): float => $day->exitRate, $daily),
            ),
        ];
    }

    /**
     * @param list<float> $series
     */
    private function tile(string $key, string $icon, string $value, int|float $change, string $formattedChange, bool $higherIsBetter, array $series): Tile
    {
        $hasChange = round($change, 3) !== 0.0;
        $isUp = $change > 0;
        [$line, $area] = $this->sparkline($series);

        return new Tile(
            key: $key,
            icon: $icon,
            value: $value,
            trend: $hasChange ? $formattedChange : null,
            trendIcon: $hasChange ? ($isUp ? 'actions-arrow-up' : 'actions-arrow-down') : null,
            trendRating: $hasChange ? ($isUp === $higherIsBetter ? 'positive' : 'negative') : null,
            sparklineLine: $line,
            sparklineArea: $area,
        );
    }

    /**
     * SVG polyline points for the line and the filled area below it, in a
     * viewBox of SPARKLINE_WIDTH x SPARKLINE_HEIGHT.
     *
     * @param list<float> $series
     * @return array{string, string}
     */
    private function sparkline(array $series): array
    {
        $count = count($series);
        if ($count < 2) {
            return ['', ''];
        }
        $max = max($series);
        $min = min($series);
        $range = $max - $min ?: 1.0;

        $points = [];
        foreach ($series as $index => $value) {
            $x = $index / ($count - 1) * self::SPARKLINE_WIDTH;
            // Keep a 1px margin so the stroke is not clipped at the top and bottom edge
            $y = self::SPARKLINE_HEIGHT - 1 - ($value - $min) / $range * (self::SPARKLINE_HEIGHT - 2);
            $points[] = round($x, 2) . ',' . round($y, 2);
        }
        $line = implode(' ', $points);

        return [$line, '0,' . self::SPARKLINE_HEIGHT . ' ' . $line . ' ' . self::SPARKLINE_WIDTH . ',' . self::SPARKLINE_HEIGHT];
    }

    private function formatNumber(int $number): string
    {
        return number_format($number, 0, '.', "\u{202F}");
    }

    private function formatSignedNumber(int $number): string
    {
        return ($number > 0 ? '+' : '−') . $this->formatNumber(abs($number));
    }

    private function formatPercent(float $ratio): string
    {
        return number_format($ratio * 100, 1) . ' %';
    }

    private function formatSignedPercent(float $ratio): string
    {
        return ($ratio > 0 ? '+' : '−') . $this->formatPercent(abs($ratio));
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    private function formatSignedDuration(int $seconds): string
    {
        return ($seconds > 0 ? '+' : '−') . $this->formatDuration(abs($seconds));
    }
}
