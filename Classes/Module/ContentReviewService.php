<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageRecordResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use MaikSchneider\AnalyticsMatomo\Statistics\VisiblePages;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Visible pages of a site (or of a subtree) without views in the last 90 days, and pages
 * unchanged for a year that still get views, ranked by views × days since the last change.
 * A page created during the period is no candidate yet, nor is any page while Matomo has
 * tracked the site for less than the period.
 */
final readonly class ContentReviewService
{
    public const DAYS = 90;
    public const OLD_DAYS = 365;
    public const COLUMNS_WITHOUT_VIEWS = [
        'label' => ['column.pageTitle', 'string'],
        'nb_hits' => ['column.nb_hits', 'number'],
        'last_changed' => ['column.lastChanged', 'date'],
    ];
    public const COLUMNS_OLD = self::COLUMNS_WITHOUT_VIEWS + [
        'age' => ['column.age', 'number'],
        'priority' => ['column.priority', 'number', 'column.priority.description'],
    ];

    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private PageUrlMapper $mapper,
        private PageRecordResolver $pageRecordResolver,
        private Context $context,
        private VisiblePages $visiblePages,
    ) {
    }

    /**
     * @param int $pageId first page of the subtree, the root page for the whole site
     * @return array{period: Period, trackedSince: \DateTimeImmutable|null, truncated: bool, withoutViews: list<array<string, mixed>>, old: list<array<string, mixed>>}
     * @throws MatomoApiException
     */
    public function getReview(Site $site, MatomoConfiguration $configuration, int $pageId): array
    {
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $period = Period::fromPreset((string)self::DAYS, $now);
        $matomo = $this->cache->remember(['contentReview', $configuration->getCacheIdentifier(), $period->toMatomoDate()], function () use ($configuration, $period): array {
            [$rows, $site] = $this->client->bulkRequest($configuration, [
                ['method' => 'Actions.getPageUrls', 'period' => 'range', 'date' => $period->toMatomoDate(), 'flat' => 1, 'format_metrics' => 0, 'filter_limit' => -1, 'showColumns' => 'nb_hits'],
                ['method' => 'SitesManager.getSiteFromId'],
            ]);
            if (!is_array($rows) || !array_is_list($rows) || !is_array($site)) {
                throw new MatomoApiException('Matomo answered with an unexpected page report', 1794090001);
            }
            return ['rows' => array_values(array_filter($rows, is_array(...))), 'trackedSince' => (string)($site['ts_created'] ?? '')];
        });
        $trackedSince = $matomo['trackedSince'] === '' ? null : new \DateTimeImmutable(substr($matomo['trackedSince'], 0, 10));
        $report = $this->mapper->mapReport($site, $matomo['rows']);
        $provable = $trackedSince !== null && $trackedSince <= $period->from;

        $withoutViews = $old = [];
        foreach ($this->visiblePages->get($site, $pageId, $GLOBALS['BE_USER']) as $page) {
            $languageId = (int)$page['sys_language_uid'];
            $defaultPageId = $languageId > 0 ? (int)$page['l10n_parent'] : (int)$page['uid'];
            $rows = $report->rowsOfPage($defaultPageId, $languageId);
            $views = $rows === null ? null : (int)array_sum(array_column($rows, 'nb_hits'));
            $lastChanged = max((int)$page['tstamp'], (int)$page['SYS_LASTCHANGED']);
            $age = $lastChanged > 0 ? (int)(new \DateTimeImmutable('@' . $lastChanged))->setTimezone($now->getTimezone())->setTime(0, 0)->diff($now->setTime(0, 0))->days : null;
            $language = $site->getLanguageById($languageId);
            $row = [
                'label' => (string)$page['title'],
                'url' => (string)$site->getRouter()->generateUri($this->pageRecordResolver->resolve($defaultPageId, $language), ['_language' => $language], '', RouterInterface::ABSOLUTE_URL),
                'nb_hits' => $views,
                'last_changed' => $lastChanged > 0 ? $lastChanged : null,
            ];
            if ($provable && !$views && (int)$page['crdate'] < $period->from->getTimestamp()) {
                $withoutViews[] = $row;
            }
            if ($views > 0 && $age !== null && $age >= self::OLD_DAYS) {
                $old[] = $row + ['age' => $age, 'priority' => $views * $age];
            }
        }
        // Longest unchanged first
        usort($withoutViews, static fn (array $a, array $b): int => ($a['last_changed'] ?? 0) <=> ($b['last_changed'] ?? 0));

        return ['period' => $period, 'trackedSince' => $trackedSince, 'truncated' => $report->isTruncated, 'withoutViews' => $withoutViews, 'old' => $old];
    }
}
