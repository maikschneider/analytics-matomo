<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

/**
 * Days of the store as integers (Ymd, e.g. 20261123), in the timezone of the clock that asks.
 */
final class Day
{
    public static function fromDate(\DateTimeInterface $date): int
    {
        return (int)$date->format('Ymd');
    }

    /**
     * Midnight of the day in the given timezone.
     */
    public static function toDate(int $day, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Ymd', (string)$day, $timezone);
        if ($date === false || self::fromDate($date) !== $day) {
            throw new \InvalidArgumentException('Not a day (Ymd): ' . $day, 1794500002);
        }
        return $date;
    }

    /**
     * Every day from $from to $to, newest first.
     *
     * @return list<int>
     */
    public static function range(int $from, int $to): array
    {
        $timezone = new \DateTimeZone('UTC');
        $days = [];
        for ($date = self::toDate($to, $timezone), $first = self::toDate($from, $timezone); $date >= $first; $date = $date->modify('-1 day')) {
            $days[] = self::fromDate($date);
        }
        return $days;
    }
}
