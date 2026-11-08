<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Annotation;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The Matomo annotations of a period for sites that annotate content changes.
 * Annotations are extra information: without them, views show their numbers anyway.
 */
final readonly class AnnotationService
{
    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
        private LoggerInterface $logger,
    ) {
    }

    public static function isEnabled(Site $site): bool
    {
        return $site->getSettings()->get('analyticsMatomo.annotations') === true;
    }

    /**
     * @return list<Annotation> oldest first
     */
    public function get(Site $site, MatomoConfiguration $configuration, Period $period): array
    {
        if (!self::isEnabled($site)) {
            return [];
        }
        try {
            return $this->cache->remember(['annotations', $configuration->getCacheIdentifier(), $period->toMatomoDate()], function () use ($configuration, $period): array {
                [$result] = $this->client->bulkRequest($configuration, [['method' => 'Annotations.getAll', 'period' => 'range', 'date' => $period->toMatomoDate()]]);
                return Annotation::listFromApiResult($result);
            });
        } catch (MatomoApiException $e) {
            $this->logger->warning('Loading Matomo annotations failed: {message}', ['message' => $e->getMessage(), 'site' => $site->getIdentifier()]);
            return [];
        }
    }
}
