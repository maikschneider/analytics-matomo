<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

/**
 * A range of whole days up to today at most, as Matomo expects them in `date`
 * for `period=range` and `period=day`.
 */
final readonly class Period
{
    /** Periods of the statistics bar */
    public const DAYS = [7, 14, 30];
    /** Periods of the module and the dashboard widgets; the first is the default */
    public const PRESETS = ['7', '14', '30', '90', 'thisMonth', 'lastMonth', 'thisYear'];
    public const CUSTOM = 'custom';
    private const MAX_DAYS_PER_DAY = 62;
    private const MAX_DAYS_PER_WEEK = 366;

    public int $days;

    /**
     * @param string $preset one of PRESETS or CUSTOM
     */
    private function __construct(
        public string $preset,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
        $this->days = (int)$from->diff($to)->days + 1;
    }

    public static function normalizeDays(int $days): int
    {
        return in_array($days, self::DAYS, true) ? $days : self::DAYS[0];
    }

    public static function lastDays(int $days, \DateTimeImmutable $today): self
    {
        $days = self::normalizeDays($days);
        $to = $today->setTime(0, 0);
        return new self((string)$days, $to->modify('-' . ($days - 1) . ' days'), $to);
    }

    /**
     * @param string $from first day of a custom range (Y-m-d)
     * @param string $to last day of a custom range (Y-m-d), today at most
     */
    public static function fromPreset(string $preset, \DateTimeImmutable $today, string $from = '', string $to = ''): self
    {
        $today = $today->setTime(0, 0);
        if ($preset === self::CUSTOM) {
            $first = self::parseDate($from);
            $last = self::parseDate($to);
            if ($first === null || $last === null) {
                return self::fromPreset(self::PRESETS[0], $today);
            }
            [$first, $last] = $first <= $last ? [$first, $last] : [$last, $first];
            return new self(self::CUSTOM, min($first, $today), min($last, $today));
        }
        return match ($preset) {
            'thisMonth' => new self($preset, $today->modify('first day of this month'), $today),
            'lastMonth' => new self($preset, $today->modify('first day of last month'), $today->modify('last day of last month')),
            'thisYear' => new self($preset, $today->setDate((int)$today->format('Y'), 1, 1), $today),
            '7', '14', '30', '90' => new self($preset, $today->modify('-' . ((int)$preset - 1) . ' days'), $today),
            default => self::fromPreset(self::PRESETS[0], $today),
        };
    }

    /**
     * The same number of days right before this period.
     */
    public function previous(): self
    {
        $to = $this->from->modify('-1 day');
        return new self(self::CUSTOM, $to->modify('-' . ($this->days - 1) . ' days'), $to);
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

    /**
     * Matomo period of the points in an evolution chart: days up to two months, weeks up to a year, months above.
     *
     * @return 'day'|'week'|'month'
     */
    public function granularity(): string
    {
        return match (true) {
            $this->days <= self::MAX_DAYS_PER_DAY => 'day',
            $this->days <= self::MAX_DAYS_PER_WEEK => 'week',
            default => 'month',
        };
    }

    private static function parseDate(string $date): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
