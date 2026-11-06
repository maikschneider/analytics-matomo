<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Configuration;

final readonly class MatomoConfiguration
{
    public function __construct(
        public string $url,
        public int $idSite,
        public string $tokenAuth,
    ) {
    }

    public function getApiUrl(): string
    {
        return rtrim($this->url, '/') . '/index.php';
    }

    public function getReportUrl(int $days): string
    {
        return $this->getApiUrl() . '?' . http_build_query([
            'module' => 'CoreHome',
            'action' => 'index',
            'idSite' => $this->idSite,
            'period' => 'range',
            'date' => 'last' . $days,
        ]);
    }
}
