<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Annotation;

/**
 * Average daily page views before and after an annotated change, without the
 * day of the change and the unfinished current day. An indication only: season,
 * campaigns and other pages influence the numbers as well.
 */
final readonly class ChangeImpact
{
    /**
     * @param float|null $change relative change of the daily average, null without views before
     */
    public function __construct(
        public string $date,
        public float $before,
        public float $after,
        public int $daysBefore,
        public int $daysAfter,
        public ?float $change,
    ) {
    }

    /**
     * @param array<string, int> $views page views by day (Y-m-d)
     */
    public static function fromDailyViews(array $views, string $changeDate, string $today): ?self
    {
        $before = $after = [];
        foreach ($views as $date => $count) {
            if ($date < $changeDate) {
                $before[] = $count;
            } elseif ($date > $changeDate && $date < $today) {
                $after[] = $count;
            }
        }
        if ($before === [] || $after === []) {
            return null;
        }
        $averageBefore = array_sum($before) / count($before);
        $averageAfter = array_sum($after) / count($after);
        return new self(
            $changeDate,
            (float)$averageBefore,
            (float)$averageAfter,
            count($before),
            count($after),
            $averageBefore > 0 ? $averageAfter / $averageBefore - 1 : null,
        );
    }
}
