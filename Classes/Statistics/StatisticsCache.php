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
     * @param list<mixed> $key
     * @param callable(): T $build
     * @return T
     */
    public function remember(array $key, callable $build, ?int $lifetime = null): mixed
    {
        $cached = $this->cache->get($this->identifier($key));
        if ($cached !== false) {
            return $cached;
        }
        $value = $build();
        $this->set($key, $value, $lifetime);
        return $value;
    }

    /**
     * @param list<mixed> $key
     * @return array<mixed>|null null when nothing is cached
     */
    public function get(array $key): ?array
    {
        $cached = $this->cache->get($this->identifier($key));
        return is_array($cached) ? $cached : null;
    }

    /**
     * @param list<mixed> $key
     */
    public function set(array $key, mixed $value, ?int $lifetime = null): void
    {
        $this->cache->set($this->identifier($key), $value, [], $lifetime);
    }

    /**
     * @param list<mixed> $key
     */
    private function identifier(array $key): string
    {
        return sha1((string)json_encode($key));
    }
}
