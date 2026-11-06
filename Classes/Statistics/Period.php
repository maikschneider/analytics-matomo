<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

/**
 * The last N days including today, as Matomo expects them in `date` for
 * `period=range` and `period=day`.
 */
final readonly class Period
{
    public const DAYS = [7, 14, 30];

    private function __construct(
        public int $days,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }

    public static function normalizeDays(int $days): int
    {
        return in_array($days, self::DAYS, true) ? $days : self::DAYS[0];
    }

    public static function lastDays(int $days, \DateTimeImmutable $today): self
    {
        $days = self::normalizeDays($days);
        $to = $today->setTime(0, 0);
        return new self($days, $to->modify('-' . ($days - 1) . ' days'), $to);
    }

    /**
     * The same number of days right before this period.
     */
    public function previous(): self
    {
        $to = $this->from->modify('-1 day');
        return new self($this->days, $to->modify('-' . ($this->days - 1) . ' days'), $to);
    }

    public function toMatomoDate(): string
    {
        return $this->from->format('Y-m-d') . ',' . $this->to->format('Y-m-d');
    }

    /**
     * @return list<string> every day (Y-m-d), oldest first
     */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->from; $day <= $this->to; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }
}
