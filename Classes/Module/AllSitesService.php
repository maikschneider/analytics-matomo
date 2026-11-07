<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Visit totals of several sites: one bulk request per Matomo connection (URL and
 * token), each site asked with its own site ID. Every site keeps its own token
 * instead of one token with access to all sites. A failing site or connection
 * leaves the other sites.
 */
final readonly class AllSitesService
{
    public const COLUMNS = ['nb_visits', 'nb_pageviews', 'bounce_rate', 'avg_time_on_site'];

    public function __construct(
        private MatomoConfigurationResolver $configurationResolver,
        private MatomoClient $client,
        private StatisticsCache $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function title(Site $site): string
    {
        return (string)(BackendUtility::getRecord('pages', $site->getRootPageId(), 'title')['title'] ?? $site->getIdentifier());
    }

    /**
     * @param array<string, Site> $sites by identifier
     * @return array<string, array{current: array<string, mixed>|null, previous: array<string, mixed>|null}> by site identifier, null without an answer
     */
    public function getTotals(array $sites, Period $period, bool $compare): array
    {
        $ranges = $compare ? [$period, $period->previous()] : [$period];
        $totals = array_fill_keys(array_keys($sites), ['current' => null, 'previous' => null]);
        $connections = [];
        foreach ($sites as $identifier => $site) {
            $configuration = $this->configurationResolver->resolve($site);
            if ($configuration === null) {
                continue;
            }
            $key = ['allSites', $configuration->getCacheIdentifier(), $period->toMatomoDate(), $compare];
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                $totals[$identifier] = $this->totals($cached, $compare);
                continue;
            }
            // Demo data are generated per site, so demo sites never share a request
            $connection = implode("\n", [rtrim($configuration->url, '/'), $configuration->tokenAuth, $configuration->demo ? 'demo' . $identifier : '']);
            $connections[$connection]['configuration'] ??= $configuration;
            $connections[$connection]['sites'][$identifier] = [$configuration->idSite, $key];
        }
        foreach ($connections as ['configuration' => $configuration, 'sites' => $connectionSites]) {
            foreach ($this->fetch($configuration, $connectionSites, $ranges) as $identifier => $siteResults) {
                if ($siteResults !== null) {
                    $this->cache->set($connectionSites[$identifier][1], $siteResults);
                    $totals[$identifier] = $this->totals($siteResults, $compare);
                }
            }
        }
        return $totals;
    }

    /**
     * @param array<string, array{int, list<mixed>}> $sites site ID and cache key by site identifier
     * @param non-empty-list<Period> $ranges
     * @return array<string, list<mixed>|null> results by site identifier, null on an error
     */
    private function fetch(MatomoConfiguration $configuration, array $sites, array $ranges): array
    {
        $requests = [];
        foreach ($sites as [$idSite]) {
            foreach ($ranges as $range) {
                $requests[] = ['method' => 'API.get', 'idSite' => $idSite, 'period' => 'range', 'date' => $range->toMatomoDate(), 'format_metrics' => 0, 'showColumns' => implode(',', self::COLUMNS)];
            }
        }
        try {
            $results = array_chunk($this->client->bulkRequest($configuration, $requests, keepErrors: true), count($ranges));
        } catch (MatomoApiException $exception) {
            $this->logger->warning('Loading the Matomo totals of sites failed: {message}', ['message' => $exception->getMessage(), 'sites' => array_keys($sites)]);
            return array_fill_keys(array_keys($sites), null);
        }
        $siteResults = [];
        foreach (array_keys($sites) as $index => $identifier) {
            $siteResults[$identifier] = $results[$index];
            foreach ($results[$index] as $result) {
                // E.g. a site the token may not view; the other sites of the request keep their answers
                if (!is_array($result) || ($result['result'] ?? null) === 'error') {
                    $this->logger->warning('Loading the Matomo totals of a site failed: {message}', ['message' => (string)(is_array($result) ? $result['message'] ?? '' : 'invalid answer'), 'site' => $identifier]);
                    $siteResults[$identifier] = null;
                    break;
                }
            }
        }
        return $siteResults;
    }

    /**
     * @param array<mixed> $results
     * @return array{current: array<string, mixed>|null, previous: array<string, mixed>|null}
     */
    private function totals(array $results, bool $compare): array
    {
        return ['current' => is_array($results[0] ?? null) ? $results[0] : null, 'previous' => $compare && is_array($results[1] ?? null) ? $results[1] : null];
    }
}
