<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Caches built statistics. A build that throws is not cached, so a Matomo
 * error is retried on the next request.
 */
final readonly class StatisticsCache
{
    public function __construct(
        #[Autowire(service: 'cache.analytics_matomo')]
        private FrontendInterface $cache,
    ) {
    }

    /**
     * @template T
     * @param list<scalar> $key
     * @param callable(): T $build
     * @return T
     */
    public function remember(array $key, callable $build): mixed
    {
        $identifier = sha1((string)json_encode($key));
        $cached = $this->cache->get($identifier);
        if ($cached !== false) {
            return $cached;
        }
        $value = $build();
        $this->cache->set($identifier, $value);
        return $value;
    }
}
