<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Matomo;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Dashboard\Breakdown;

/**
 * Generated Reporting API answers for trying out the extension without a Matomo
 * instance. Numbers depend only on method, URL and date, so they stay the same
 * on every reload and a range is the sum of its days.
 */
final readonly class DemoData
{
    private const PATHS = ['', 'about', 'products', 'news', 'contact', 'jobs', 'blog', 'imprint'];
    private const LABELS = [
        Breakdown::CHANNEL => ['Search Engines' => 52, 'Direct Entry' => 24, 'Websites' => 13, 'Social Networks' => 8, 'Campaigns' => 3],
        Breakdown::DEVICES => ['Desktop' => 58, 'Smartphone' => 36, 'Tablet' => 5, 'Phablet' => 1],
        Breakdown::BROWSERS => ['Chrome' => 46, 'Safari' => 24, 'Firefox' => 12, 'Microsoft Edge' => 10, 'Samsung Internet' => 5, 'Opera' => 3],
        Breakdown::COUNTRIES => ['Germany' => 61, 'Austria' => 12, 'Switzerland' => 10, 'United States' => 7, 'Netherlands' => 6, 'France' => 4],
    ];

    /**
     * @param list<array<string, int|string>> $requests
     * @return list<mixed>
     */
    public function results(array $requests, MatomoConfiguration $configuration): array
    {
        return array_map(fn (array $request): mixed => $this->result($request, $configuration->url), $requests);
    }

    /**
     * @param array<string, int|string> $request
     */
    private function result(array $request, string $baseUrl): mixed
    {
        $dates = $this->dates((string)($request['date'] ?? ''));
        if (($request['period'] ?? '') === 'day') {
            $result = [];
            foreach ($dates as $date) {
                $result[$date] = $this->report($request, [$date], $baseUrl);
            }
            return $result;
        }
        return $this->report($request, $dates, $baseUrl);
    }

    /**
     * @param array<string, int|string> $request
     * @param list<string> $dates
     * @return array<mixed>
     */
    private function report(array $request, array $dates, string $baseUrl): array
    {
        $method = (string)($request['method'] ?? '');
        $visits = $this->sum($dates, static fn (string $date, self $demo): int => $demo->visits($date));

        return match ($method) {
            'VisitsSummary.get' => $this->visitsSummary($dates, $visits),
            'VisitFrequency.get' => ['nb_visits_returning' => intdiv($visits * 3, 10)],
            'Actions.get' => ['nb_pageviews' => $this->pageViews($dates)],
            'Actions.getPageUrl' => [$this->pageRow((string)($request['pageUrl'] ?? ''), $dates)],
            'Actions.getPageUrls' => $this->pageUrls($baseUrl, $dates, (int)($request['filter_limit'] ?? -1)),
            Breakdown::CHANNEL, Breakdown::DEVICES, Breakdown::BROWSERS, Breakdown::COUNTRIES => $this->breakdown($method, $dates),
            default => throw new MatomoApiException('The demo data have no answer for ' . $method, 1793960301),
        };
    }

    /**
     * @param list<string> $dates
     * @return array<string, int|float>
     */
    private function visitsSummary(array $dates, int $visits): array
    {
        $bounces = $this->sum($dates, static fn (string $date, self $demo): int => intdiv($demo->visits($date) * $demo->between('bounce' . $date, 35, 55), 100));
        $length = $this->sum($dates, static fn (string $date, self $demo): int => $demo->visits($date) * $demo->between('length' . $date, 90, 240));

        return [
            'nb_uniq_visitors' => intdiv($visits * 4, 5),
            'nb_visits' => $visits,
            'nb_actions' => $this->pageViews($dates),
            'bounce_count' => $bounces,
            'sum_visit_length' => $length,
            'bounce_rate' => $visits > 0 ? round($bounces / $visits, 2) : 0,
            'avg_time_on_site' => $visits > 0 ? intdiv($length, $visits) : 0,
        ];
    }

    /**
     * @param list<string> $dates
     * @return array<string, int|float|string>
     */
    private function pageRow(string $url, array $dates): array
    {
        $hits = $this->sum($dates, static fn (string $date, self $demo): int => $demo->between($url . $date, 2, 40));
        return [
            'label' => (string)parse_url($url, PHP_URL_PATH),
            'url' => $url,
            'nb_hits' => $hits,
            'bounce_rate' => $this->between('bounce' . $url, 20, 70) / 100,
            'avg_time_on_page' => $this->between('time' . $url, 20, 180),
            'exit_rate' => $this->between('exit' . $url, 15, 60) / 100,
        ];
    }

    /**
     * @param list<string> $dates
     * @return list<array<string, int|float|string>>
     */
    private function pageUrls(string $baseUrl, array $dates, int $limit): array
    {
        $rows = [];
        foreach (self::PATHS as $path) {
            $rows[] = $this->pageRow(rtrim($baseUrl, '/') . '/' . $path, $dates);
        }
        usort($rows, static fn (array $a, array $b): int => $b['nb_hits'] <=> $a['nb_hits']);
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * @param list<string> $dates
     * @return list<array{label: string, nb_visits: int}>
     */
    private function breakdown(string $method, array $dates): array
    {
        $rows = [];
        foreach (self::LABELS[$method] as $label => $percent) {
            $visits = $this->sum($dates, static fn (string $date, self $demo): int => intdiv($demo->visits($date) * $percent * $demo->between($label . $date, 80, 120), 10000));
            $rows[] = ['label' => $label, 'nb_visits' => $visits];
        }
        usort($rows, static fn (array $a, array $b): int => $b['nb_visits'] <=> $a['nb_visits']);
        return $rows;
    }

    /**
     * @param list<string> $dates
     */
    private function pageViews(array $dates): int
    {
        return $this->sum($dates, static fn (string $date, self $demo): int => intdiv($demo->visits($date) * $demo->between('actions' . $date, 150, 260), 100));
    }

    private function visits(string $date): int
    {
        $weekend = (int)(new \DateTimeImmutable($date))->format('N') >= 6;
        return $this->between('visits' . $date, 90, 160) - ($weekend ? 40 : 0);
    }

    /**
     * @param list<string> $dates
     * @param \Closure(string, self): int $perDay
     */
    private function sum(array $dates, \Closure $perDay): int
    {
        return array_sum(array_map(fn (string $date): int => $perDay($date, $this), $dates));
    }

    private function between(string $seed, int $min, int $max): int
    {
        return $min + crc32($seed) % ($max - $min + 1);
    }

    /**
     * @return list<string> the days of a Matomo date "Y-m-d" or "Y-m-d,Y-m-d"
     */
    private function dates(string $date): array
    {
        $parts = explode(',', $date);
        $day = new \DateTimeImmutable($parts[0]);
        $last = new \DateTimeImmutable($parts[1] ?? $parts[0]);
        $dates = [];
        for (; $day <= $last; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }
}
