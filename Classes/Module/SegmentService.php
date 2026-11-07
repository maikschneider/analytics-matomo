<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Statistics\StatisticsCache;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Stored segments the module may filter by: only those Matomo pre-processes with its
 * archiving (auto_archive), as ad-hoc segments are slow or disabled on many instances.
 * Matomo lists the segments the token user may see; the choice is stored per site.
 */
final readonly class SegmentService
{
    public function __construct(
        private MatomoClient $client,
        private StatisticsCache $cache,
    ) {
    }

    /**
     * @return array<string, array{name: string, definition: string}> by segment ID
     * @throws MatomoApiException
     */
    public function getSegments(MatomoConfiguration $configuration): array
    {
        return $this->cache->remember(['segments', $configuration->getCacheIdentifier()], function () use ($configuration): array {
            [$result] = $this->client->bulkRequest($configuration, [['method' => 'SegmentEditor.getAll']]);
            if (!is_array($result) || !array_is_list($result)) {
                throw new MatomoApiException('Matomo answered with an unexpected segment list', 1794080101);
            }
            $segments = [];
            foreach ($result as $segment) {
                if (!is_array($segment) || (int)($segment['auto_archive'] ?? 0) !== 1 || (int)($segment['deleted'] ?? 0) !== 0
                    || !in_array((int)($segment['enable_only_idsite'] ?? 0), [0, $configuration->idSite], true)
                    || !is_string($segment['definition'] ?? null) || $segment['definition'] === '') {
                    continue;
                }
                $segments[(string)($segment['idsegment'] ?? '')] = ['name' => (string)($segment['name'] ?? ''), 'definition' => $segment['definition']];
            }
            return $segments;
        });
    }

    /**
     * Stores a requested segment ID ('' for all visits) and returns the user's segment of the site, if still offered
     *
     * @param array<string, array{name: string, definition: string}> $segments
     */
    public function select(BackendUserAuthentication $user, string $siteIdentifier, array $segments, ?string $requested): string
    {
        $stored = $user->uc['analytics_matomo']['segments'] ?? [];
        $stored = is_array($stored) ? $stored : [];
        if ($requested !== null && ($requested === '' || isset($segments[$requested]))) {
            $stored[$siteIdentifier] = $requested;
            $user->uc['analytics_matomo']['segments'] = $stored;
            $user->writeUC();
        }
        $selected = (string)($stored[$siteIdentifier] ?? '');
        return isset($segments[$selected]) ? $selected : '';
    }
}
