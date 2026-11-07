<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

final readonly class MetricFormatter
{
    public function format(mixed $value, string $type): string
    {
        if (!is_numeric($value)) {
            return '–';
        }
        $number = (float)$value;
        return match ($type) {
            'percent' => number_format($number * 100, 1) . ' %',
            'difference', 'growth' => ($number > 0 ? '+' : ($number < 0 ? '−' : '')) . $this->format(abs($number), $type === 'growth' ? 'percent' : 'number'),
            'duration_s' => $number < 60 && $number !== floor($number)
                ? number_format($number, 2) . ' s'
                : sprintf('%d:%02d:%02d', intdiv((int)$number, 3600), intdiv((int)$number, 60) % 60, (int)$number % 60),
            'money' => number_format($number, 2),
            default => number_format($number, $number === floor($number) ? 0 : 2),
        };
    }
}
