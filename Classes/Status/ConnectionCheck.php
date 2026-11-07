<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Status;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Checks the Matomo connection of a site with view access only: reachability, version,
 * access of the token, URLs Matomo would drop, stalled tracking and setup hints.
 * Matomo's answers are cached for ten minutes, so the check never runs on every request.
 */
final readonly class ConnectionCheck
{
    private const LIFETIME = 600;
    private const YOUNG_DAYS = 90;
    private const CHECKS = [
        'version' => ['method' => 'API.getMatomoVersion'],
        'site' => ['method' => 'SitesManager.getSiteFromId'],
        'urls' => ['method' => 'SitesManager.getSiteUrlsFromId'],
        'lastVisit' => ['method' => 'Live.getLastVisitsDetails', 'filter_limit' => 1, 'showColumns' => 'lastActionTimestamp'],
        'adminSites' => ['method' => 'SitesManager.getSitesIdWithAdminAccess'],
        'viewSites' => ['method' => 'SitesManager.getSitesIdWithAtLeastViewAccess'],
        // Only a superuser may call it, for any user; "anonymous" exists in every Matomo
        'superuser' => ['method' => 'UsersManager.getSitesAccessFromUser', 'userLogin' => 'anonymous'],
    ];

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private Context $context,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<int> $expectedSiteIds Matomo sites of all TYPO3 sites using the same URL and token
     * @param int $maxHoursWithoutVisits 0 to skip the check
     * @return array{version: string, findings: list<array{severity: ContextualFeedbackSeverity, key: string, arguments: list<int|string>}>}
     */
    public function check(Site $site, MatomoConfiguration $configuration, array $expectedSiteIds, int $maxHoursWithoutVisits): array
    {
        $answers = $this->cache->remember(['status', $configuration->getCacheIdentifier()], fn (): array => $this->fetch($configuration), self::LIFETIME);
        if (isset($answers['error'])) {
            return ['version' => '', 'findings' => [$this->finding(ContextualFeedbackSeverity::ERROR, 'connection', (string)$answers['error'])]];
        }
        $version = (string)($answers['version']['value'] ?? '');
        foreach (array_diff_key($answers, ['superuser' => true, 'today' => true]) as $answer) {
            if ($this->isError($answer)) {
                return ['version' => $version, 'findings' => [$this->finding(ContextualFeedbackSeverity::ERROR, 'siteAccess', $configuration->idSite, (string)$answer['message'])]];
            }
        }

        $findings = [];
        $superuser = $answers['superuser'] ?? null;
        if (!$this->isError($superuser) || !str_contains((string)$superuser['message'], "'superuser'")) {
            $findings[] = $this->finding(ContextualFeedbackSeverity::ERROR, 'superuser');
        }
        $adminSites = array_map(intval(...), (array)$answers['adminSites']);
        if ($adminSites !== []) {
            $findings[] = $this->finding(ContextualFeedbackSeverity::WARNING, 'adminAccess', implode(', ', $adminSites));
        }
        $extraSites = array_values(array_diff(array_map(intval(...), (array)$answers['viewSites']), $expectedSiteIds));
        if ($extraSites !== []) {
            $findings[] = $this->finding(ContextualFeedbackSeverity::NOTICE, 'extraSites', implode(', ', $extraSites));
        }

        $matomoSite = (array)$answers['site'];
        if ((int)($matomoSite['exclude_unknown_urls'] ?? 0) === 1) {
            $siteUrls = array_values(array_map(strval(...), (array)$answers['urls']));
            $unknown = [];
            foreach ($site->getLanguages() as $language) {
                $base = (string)$language->getBase();
                if ($language->getBase()->getHost() !== '' && !MatomoUrlMatcher::covers($siteUrls, $base)) {
                    $unknown[] = $base;
                }
            }
            if ($unknown !== []) {
                $findings[] = $this->finding(ContextualFeedbackSeverity::WARNING, 'unknownUrls', implode(', ', array_unique($unknown)));
            }
        }

        $now = $this->now();
        $lastVisit = (int)(((array)$answers['lastVisit'])[0]['lastActionTimestamp'] ?? 0);
        if ($lastVisit === 0) {
            $findings[] = $this->finding(ContextualFeedbackSeverity::WARNING, 'noVisits');
        } elseif ($maxHoursWithoutVisits > 0 && $now->getTimestamp() - $lastVisit > $maxHoursWithoutVisits * 3600) {
            $findings[] = $this->finding(ContextualFeedbackSeverity::WARNING, 'stalled', intdiv($now->getTimestamp() - $lastVisit, 3600));
        }

        if ((int)($matomoSite['sitesearch'] ?? 1) === 0) {
            $findings[] = $this->finding(ContextualFeedbackSeverity::INFO, 'siteSearch');
        }
        $created = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)($matomoSite['ts_created'] ?? ''));
        if ($created !== false && $created > $now->modify('-' . self::YOUNG_DAYS . ' days')) {
            $findings[] = $this->finding(ContextualFeedbackSeverity::INFO, 'young', $created->format('d.m.Y'), self::YOUNG_DAYS);
        }
        $archived = (int)($answers['today']['archived'] ?? 0);
        $raw = (int)($answers['today']['raw'] ?? 0);
        if ($raw > $archived) {
            $findings[] = $this->finding(ContextualFeedbackSeverity::INFO, 'archiveLag', $archived, $raw);
        }

        return ['version' => $version, 'findings' => $findings];
    }

    /**
     * @return array<string, mixed> answers by check, or ['error' => message]
     */
    private function fetch(MatomoConfiguration $configuration): array
    {
        try {
            $answers = array_combine(array_keys(self::CHECKS), $this->client->bulkRequest($configuration, array_values(self::CHECKS), keepErrors: true));
        } catch (MatomoApiException $exception) {
            return ['error' => $exception->getMessage()];
        }
        $timezone = $this->timezone($answers['site']);
        if ($timezone !== null) {
            // Visits of today in reports against the raw log since midnight in the site's time zone
            $now = $this->now()->setTimezone($timezone);
            $minutes = intdiv($now->getTimestamp() - $now->setTime(0, 0)->getTimestamp(), 60);
            try {
                [$counters, $summary] = $this->client->bulkRequest($configuration, [
                    ['method' => 'Live.getCounters', 'lastMinutes' => max(1, $minutes)],
                    ['method' => 'VisitsSummary.get', 'period' => 'range', 'date' => $now->format('Y-m-d') . ',' . $now->format('Y-m-d'), 'format_metrics' => 0],
                ]);
                $answers['today'] = ['raw' => (int)(((array)$counters)[0]['visits'] ?? 0), 'archived' => (int)(((array)$summary)['nb_visits'] ?? 0)];
            } catch (MatomoApiException $exception) {
                $this->logger->warning('Comparing archived and raw Matomo visits failed: {message}', ['message' => $exception->getMessage()]);
            }
        }
        return $answers;
    }

    private function timezone(mixed $site): ?\DateTimeZone
    {
        if (!is_array($site) || $this->isError($site) || !is_string($site['timezone'] ?? null)) {
            return null;
        }
        try {
            return new \DateTimeZone($site['timezone']);
        } catch (\Exception) {
            // Matomo also allows offsets like "UTC+2", which PHP doesn't know by name
            return null;
        }
    }

    /**
     * @phpstan-assert-if-true array{result: 'error', message: mixed} $answer
     */
    private function isError(mixed $answer): bool
    {
        return is_array($answer) && ($answer['result'] ?? null) === 'error';
    }

    /**
     * @return array{severity: ContextualFeedbackSeverity, key: string, arguments: list<int|string>}
     */
    private function finding(ContextualFeedbackSeverity $severity, string $key, int|string ...$arguments): array
    {
        return ['severity' => $severity, 'key' => $key, 'arguments' => array_values($arguments)];
    }

    private function now(): \DateTimeImmutable
    {
        return $this->context->getPropertyFromAspect('date', 'full', new \DateTimeImmutable());
    }
}
