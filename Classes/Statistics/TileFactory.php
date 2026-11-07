<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Dashboard\SiteMetrics;
use MaikSchneider\AnalyticsMatomo\Dashboard\SitePerformance;
use MaikSchneider\AnalyticsMatomo\Module\EvolutionChartFactory;
use MaikSchneider\AnalyticsMatomo\Module\MetricFormatter;

/**
 * Turns page and site statistics into metric tiles: formatted
 * value, change against the previous period and a sparkline of the single days.
 */
final readonly class TileFactory
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
     * @return list<Tile>
     */
    public function createForSite(SitePerformance $performance): array
    {
        $current = $performance->current;
        $previous = $performance->previous;
        $daily = array_values($performance->daily);

        return [
            $this->tile(
                'visits',
                'actions-user',
                $this->formatNumber($current->visits),
                $current->visits - $previous->visits,
                $this->formatSignedNumber($current->visits - $previous->visits),
                true,
                array_map(static fn (SiteMetrics $day): float => $day->visits, $daily),
            ),
            $this->tile(
                'pageViews',
                'actions-eye',
                $this->formatNumber($current->pageViews),
                $current->pageViews - $previous->pageViews,
                $this->formatSignedNumber($current->pageViews - $previous->pageViews),
                true,
                array_map(static fn (SiteMetrics $day): float => $day->pageViews, $daily),
            ),
            $this->tile(
                'bounceRate',
                'actions-sign-out',
                $this->formatPercent($current->bounceRate),
                $current->bounceRate - $previous->bounceRate,
                $this->formatSignedPercent($current->bounceRate - $previous->bounceRate),
                false,
                array_map(static fn (SiteMetrics $day): float => $day->bounceRate, $daily),
            ),
            $this->tile(
                'averageTimeOnSite',
                'actions-clock',
                $this->formatDuration($current->averageTimeOnSite),
                $current->averageTimeOnSite - $previous->averageTimeOnSite,
                $this->formatSignedDuration($current->averageTimeOnSite - $previous->averageTimeOnSite),
                true,
                array_map(static fn (SiteMetrics $day): float => $day->averageTimeOnSite, $daily),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed>|null $previous
     * @param array<string, array{string, string, string}> $metrics key => column, metric type, icon
     * @return list<Tile>
     */
    public function createForOverview(array $current, ?array $previous, array $metrics = EvolutionChartFactory::METRICS): array
    {
        $formatter = new MetricFormatter();
        $tiles = [];
        foreach ($metrics as $key => [$column, $type, $icon]) {
            $value = $current[$column] ?? null;
            $before = $previous[$column] ?? null;
            $change = is_numeric($value) && is_numeric($before) ? $value - $before : null;
            $hasChange = $change !== null && round($change, 3) !== 0.0;
            $tiles[] = new Tile(
                key: $key,
                icon: $icon,
                value: $formatter->format($value, $type),
                trend: $hasChange ? ($change > 0 ? '+' : '−') . $formatter->format(abs($change), $type) : null,
                trendIcon: $hasChange ? ($change > 0 ? 'actions-arrow-up' : 'actions-arrow-down') : null,
                trendRating: $hasChange ? (($change > 0) === !in_array($key, ['bounceRate', 'exitRate'], true) ? 'positive' : 'negative') : null,
                sparklineLine: '',
                sparklineArea: '',
            );
        }
        return $tiles;
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
