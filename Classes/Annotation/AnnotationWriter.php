<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Annotation;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\PageRecordResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\PageTargetResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Adds one Matomo annotation per page URL and day. Matomo is asked for the
 * notes of the day first, so a flushed cache or a second TYPO3 instance never
 * duplicates one. Nothing here may break the save: failures are logged, and
 * further changes skip Matomo for a few minutes instead of waiting for it again.
 */
final readonly class AnnotationWriter
{
    private const TIMEOUT_SECONDS = 3;
    private const PAUSE_SECONDS = 300;

    public function __construct(
        private SiteFinder $siteFinder,
        private MatomoConfigurationResolver $configurationResolver,
        private PageRecordResolver $pageRecordResolver,
        private MatomoClient $client,
        private StatisticsCache $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ContentChangedMessage $change): void
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($change->siteIdentifier);
            $language = $site->getLanguageById($change->languageId);
        } catch (SiteNotFoundException|\InvalidArgumentException) {
            return;
        }
        $configuration = $this->configurationResolver->resolve($site);
        if (!AnnotationService::isEnabled($site) || $configuration === null || $configuration->demo) {
            return;
        }
        $pause = ['annotationPause', $configuration->getCacheIdentifier()];
        if ($this->cache->get($pause) !== null) {
            return;
        }
        try {
            $this->annotate($change, $site, $language, $configuration);
        } catch (\Throwable $e) {
            $this->cache->set($pause, [true], self::PAUSE_SECONDS);
            $this->logger->warning('Adding a Matomo annotation failed: {message}', ['message' => $e->getMessage(), 'site' => $site->getIdentifier(), 'pageId' => $change->pageId]);
        }
    }

    /**
     * @throws MatomoApiException
     */
    private function annotate(ContentChangedMessage $change, Site $site, SiteLanguage $language, MatomoConfiguration $configuration): void
    {
        $page = $this->pageRecordResolver->resolve($change->pageId, $language);
        if (!is_array($page) || in_array((int)($page['doktype'] ?? 0), PageTargetResolver::DOKTYPES_WITHOUT_URL, true)
            || in_array($change->pageId, PageTargetResolver::errorPageIds($site), true)
        ) {
            return;
        }
        $pageUrl = (string)$site->getRouter()->generateUri($page, ['_language' => $language], '', RouterInterface::ABSOLUTE_URL);
        $note = Annotation::note($pageUrl, $change->username);
        $key = ['annotation', $configuration->getCacheIdentifier(), $pageUrl, $change->date];
        if ($note === null || $this->cache->get($key) !== null) {
            return;
        }
        [$existing] = $this->client->bulkRequest($configuration, [['method' => 'Annotations.getAll', 'period' => 'day', 'date' => $change->date]], timeout: self::TIMEOUT_SECONDS);
        if (Annotation::forPage(Annotation::listFromApiResult($existing), $pageUrl) === []) {
            $this->client->bulkRequest($configuration, [['method' => 'Annotations.add', 'date' => $change->date, 'note' => $note]], timeout: self::TIMEOUT_SECONDS);
        }
        $this->cache->set($key, [$note], 2 * 86400);
    }
}
