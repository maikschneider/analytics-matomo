<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Page views of every page of a site in the last 30 days and the 30 days before, by page and
 * language, from one cached bulk request per site. The page tree loads level by level, so the
 * whole report is fetched once instead of per loaded page. An unreachable Matomo is not asked
 * again for five minutes, so a broken connection does not slow down every tree request.
 *
 * @phpstan-type Views array{current: array<int, array<int, int>>, previous: array<int, array<int, int>>, currentTruncated: bool, previousTruncated: bool, total: int}
 */
final readonly class PageViewsService
{
    public const DAYS = 30;
    private const FAILURE_LIFETIME = 300;

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private PageUrlMapper $mapper,
        private MatomoConfigurationResolver $configurationResolver,
        private Context $context,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return Views|null null without a Matomo connection or while Matomo fails
     */
    public function getViews(Site $site): ?array
    {
        $configuration = $this->configurationResolver->resolve($site);
        if ($configuration === null) {
            return null;
        }
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $period = Period::fromPreset((string)self::DAYS, $now);
        // The site is part of the key: TYPO3 sites sharing a Matomo site map the same report differently
        $key = ['pageViews', $configuration->getCacheIdentifier(), $site->getIdentifier(), $period->toMatomoDate()];
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached['views'];
        }
        try {
            $request = ['method' => 'Actions.getPageUrls', 'period' => 'range', 'flat' => 1, 'format_metrics' => 0, 'filter_limit' => -1, 'showColumns' => 'nb_hits'];
            [$current, $previous] = $this->client->bulkRequest($configuration, [
                $request + ['date' => $period->toMatomoDate()],
                $request + ['date' => $period->previous()->toMatomoDate()],
            ]);
            $currentReport = $this->mapper->mapReport($site, $this->rows($current));
            $previousReport = $this->mapper->mapReport($site, $this->rows($previous));
            $views = [
                'current' => $this->byPage($currentReport),
                'previous' => $this->byPage($previousReport),
                'currentTruncated' => $currentReport->isTruncated,
                'previousTruncated' => $previousReport->isTruncated,
                'total' => (int)array_sum(array_map(static fn (PageUrlRow $row): int => (int)($row->row['nb_hits'] ?? 0), $currentReport->rows)),
            ];
        } catch (MatomoApiException $exception) {
            $this->logger->warning('Loading Matomo page views failed: {message}', ['message' => $exception->getMessage(), 'site' => $site->getIdentifier()]);
            $this->cache->set($key, ['views' => null], self::FAILURE_LIFETIME);
            return null;
        }
        $this->cache->set($key, ['views' => $views]);
        return $views;
    }

    /**
     * @param array<int, PageTarget> $targets
     * @return array{current: int, previous: int}|null
     */
    public function getFallback(array $targets): ?array
    {
        if ($targets === []) {
            return null;
        }
        $configuration = reset($targets)->configuration;
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $period = Period::fromPreset((string)self::DAYS, $now);
        $urls = array_map(static fn (PageTarget $target): string => $target->pageUrl, $targets);
        $key = ['pageViewsFallback', $configuration->getCacheIdentifier(), $period->toMatomoDate(), $urls];
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached['views'];
        }
        try {
            $requests = [];
            foreach ([$period, $period->previous()] as $range) {
                foreach ($urls as $url) {
                    $requests[] = ['method' => 'Actions.getPageUrl', 'pageUrl' => $url, 'period' => 'range', 'date' => $range->toMatomoDate(), 'format_metrics' => 0];
                }
            }
            $results = $this->client->bulkRequest($configuration, $requests);
            $hits = array_map(fn (mixed $result): int => (int)($this->rows($result)[0]['nb_hits'] ?? 0), $results);
            $views = ['current' => (int)array_sum(array_slice($hits, 0, count($urls))), 'previous' => (int)array_sum(array_slice($hits, count($urls)))];
        } catch (MatomoApiException $exception) {
            $this->logger->warning('Loading Matomo page views fallback failed: {message}', ['message' => $exception->getMessage()]);
            $this->cache->set($key, ['views' => null], self::FAILURE_LIFETIME);
            return null;
        }
        $this->cache->set($key, ['views' => $views]);
        return $views;
    }

    /**
     * Views of a page in the given languages; null when unknown, as Matomo truncated the report
     *
     * @param Views $views
     * @param list<int> $languageIds
     * @return array{current: int|null, previous: int|null}
     */
    public static function ofPage(array $views, int $pageId, array $languageIds): array
    {
        $result = [];
        foreach (['current', 'previous'] as $period) {
            $byLanguage = array_intersect_key($views[$period][$pageId] ?? [], array_flip($languageIds));
            $result[$period] = count($byLanguage) < count($languageIds) && $views[$period . 'Truncated'] ? null : (int)array_sum($byLanguage);
        }
        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $result): array
    {
        if (!is_array($result) || !array_is_list($result)) {
            throw new MatomoApiException('Matomo answered with an unexpected page report', 1794100002);
        }
        return array_values(array_filter($result, is_array(...)));
    }

    /**
     * @return array<int, array<int, int>> views by page and language
     */
    private function byPage(PageUrlReport $report): array
    {
        $views = [];
        foreach ($report->rows as $row) {
            if ($row->match->pageId !== null) {
                $languageId = $row->match->language->getLanguageId();
                $views[$row->match->pageId][$languageId] = ($views[$row->match->pageId][$languageId] ?? 0) + (int)($row->row['nb_hits'] ?? 0);
            }
        }
        return $views;
    }
}
