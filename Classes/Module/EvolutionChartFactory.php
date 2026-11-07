<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Statistics\Period;

final readonly class EvolutionChartFactory
{
    public const METRICS = [
        'visits' => ['nb_visits', 'number', 'actions-user'],
        'visitors' => ['nb_uniq_visitors', 'number', 'actions-user'],
        'pageViews' => ['nb_pageviews', 'number', 'actions-eye'],
        'bounceRate' => ['bounce_rate', 'percent', 'actions-sign-out'],
        'averageTimeOnSite' => ['avg_time_on_site', 'duration_s', 'actions-clock'],
        'actionsPerVisit' => ['nb_actions_per_visit', 'number', 'actions-list'],
    ];
    public const PAGE_METRICS = [
        'views' => ['nb_hits', 'number', 'actions-eye'],
        'bounceRate' => ['bounce_rate', 'percent', 'actions-sign-out'],
        'averageTimeOnPage' => ['avg_time_on_page', 'duration_s', 'actions-clock'],
        'exitRate' => ['exit_rate', 'percent', 'actions-logout'],
    ];

    /**
     * @param array<string, array<string, mixed>> $current
     * @param array<string, array<string, mixed>>|null $previous
     * @param array<string, array{string, string, string}> $metrics key => column, metric type, icon
     * @return array{granularity: string, labels: list<string>, previousLabels: list<string>, metrics: array<string, array{type: string, current: list<int|float|null>, previous?: list<int|float|null>}>}
     */
    public function create(Period $period, array $current, ?array $previous = null, array $metrics = self::METRICS): array
    {
        $labels = $this->labels($period);
        $previousLabels = $previous === null ? [] : $this->labels($period->previous());
        $series = [];
        foreach ($metrics as $key => [$column, $type]) {
            $currentValues = $previousValues = [];
            foreach ($labels as $index => $date) {
                $value = $current[$date][$column] ?? null;
                $currentValues[] = is_numeric($value) ? $value + 0 : null;
                if ($previous !== null) {
                    // Calendar weeks/months can differ in count; compare by ordinal bucket, not by date.
                    $value = $previous[$previousLabels[$index] ?? ''][$column] ?? null;
                    $previousValues[] = is_numeric($value) ? $value + 0 : null;
                }
            }
            $series[$key] = ['type' => $type, 'current' => $currentValues];
            if ($previous !== null) {
                $series[$key]['previous'] = $previousValues;
            }
        }
        return ['granularity' => $period->granularity(), 'labels' => $labels, 'previousLabels' => $previousLabels, 'metrics' => $series];
    }

    /** @return list<string> */
    private function labels(Period $period): array
    {
        $granularity = $period->granularity();
        $date = match ($granularity) {
            'week' => $period->from->modify('monday this week'),
            'month' => $period->from->modify('first day of this month'),
            default => $period->from,
        };
        $labels = [];
        for (; $date <= $period->to; $date = $date->modify('+1 ' . $granularity)) {
            $labels[] = $date->format('Y-m-d');
        }
        return $labels;
    }
}
