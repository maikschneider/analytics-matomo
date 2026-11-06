<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Configuration;

final readonly class MatomoConfiguration
{
    public function __construct(
        public string $url,
        public int $idSite,
        public string $tokenAuth,
        public bool $demo = false,
    ) {
    }

    /**
     * Generated statistics instead of a Matomo connection; the URL is the base of the site.
     */
    public static function demo(string $siteUrl): self
    {
        return new self($siteUrl, 0, '', true);
    }

    public function getApiUrl(): string
    {
        return rtrim($this->url, '/') . '/index.php';
    }

    /**
     * Includes a hash of the token: data fetched with one token must never answer a request with another.
     */
    public function getCacheIdentifier(): string
    {
        return hash('sha256', implode("\n", [$this->url, $this->idSite, $this->tokenAuth, $this->demo ? 'demo' : '']));
    }

    public function getReportUrl(int $days): string
    {
        if ($this->demo) {
            return '';
        }
        return $this->getApiUrl() . '?' . http_build_query([
            'module' => 'CoreHome',
            'action' => 'index',
            'idSite' => $this->idSite,
            'period' => 'range',
            'date' => 'last' . $days,
        ]);
    }
}
