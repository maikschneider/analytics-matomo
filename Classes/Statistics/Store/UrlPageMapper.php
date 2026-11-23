<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics\Store;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Maps the stored URLs of a connection to the language and page of a TYPO3
 * site (PageUrlMapper::match()), by last sight: every import maps the URLs of
 * the newest imported day again, so current URLs follow the current page
 * tree. A mapping to a page is never replaced by "no page" or "not on the
 * site", only by another page: an old slug keeps counting for its page.
 * Older days of a backfill map only URLs without mapping.
 *
 * All stored URLs are mapped again when the site is new to the connection or
 * its base, languages or base variants changed (remapSite()), in chunks that
 * may spread over several runs; the old mappings stay valid meanwhile.
 *
 * Callers must hold the import lock of the connection.
 */
final readonly class UrlPageMapper
{
    public const TABLE = 'tx_analyticsmatomo_url_page';
    public const SITE_STATE_TABLE = 'tx_analyticsmatomo_site_state';

    /**
     * language_id of URLs that are not on the site
     */
    public const NOT_ON_SITE = -1;

    /**
     * page_id of URLs on the site that do not route to a page
     */
    public const NO_PAGE = 0;

    private const CHUNK_SIZE = 1000;

    public function __construct(
        private PageUrlMapper $pageUrlMapper,
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Maps up to $limit stored URLs of the connection for the site, when the
     * site was not mapped for this connection yet or its configuration
     * changed. Resumes where the previous call stopped; a configuration
     * change meanwhile starts over.
     *
     * @return int URLs mapped
     */
    public function remapSite(Site $site, int $connection, int $limit): int
    {
        $database = $this->connectionPool->getConnectionForTable(self::SITE_STATE_TABLE);
        $identifier = ['site' => $site->getIdentifier()];
        $hash = self::configHash($site, $connection);
        $state = $database->select(['config_hash', 'pending_hash', 'remap_cursor'], self::SITE_STATE_TABLE, $identifier)->fetchAssociative();
        if ($state === false) {
            $state = ['config_hash' => '', 'pending_hash' => $hash, 'remap_cursor' => 0];
            try {
                $database->insert(self::SITE_STATE_TABLE, $identifier + $state);
            } catch (UniqueConstraintViolationException) {
                $database->update(self::SITE_STATE_TABLE, ['pending_hash' => $hash, 'remap_cursor' => 0], $identifier);
            }
        }
        $configHash = trim((string)$state['config_hash']);
        $pendingHash = trim((string)$state['pending_hash']);
        $cursor = (int)$state['remap_cursor'];
        if ($pendingHash === '' && $configHash === $hash) {
            return 0;
        }
        if ($pendingHash !== $hash) {
            // Changed since the last re-map, or during the pending one
            $cursor = 0;
            $database->update(self::SITE_STATE_TABLE, ['pending_hash' => $hash, 'remap_cursor' => $cursor], $identifier);
        }

        $urlDatabase = $this->connectionPool->getConnectionForTable(UrlDayRepository::URL_TABLE);
        $mapped = 0;
        while ($mapped < $limit) {
            $size = min(self::CHUNK_SIZE, $limit - $mapped);
            $queryBuilder = $urlDatabase->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder->select('uid', 'url')
                ->from(UrlDayRepository::URL_TABLE)
                ->where(
                    $queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($cursor, Connection::PARAM_INT)),
                )
                ->orderBy('uid')
                ->setMaxResults($size)
                ->executeQuery()
                ->fetchAllAssociative();
            if ($rows !== []) {
                $urls = [];
                foreach ($rows as $row) {
                    $urls[(int)$row['uid']] = (string)$row['url'];
                }
                $this->mapUrls($site, $urls);
                $mapped += count($urls);
                $cursor = max(array_keys($urls));
                $database->update(self::SITE_STATE_TABLE, ['remap_cursor' => $cursor], $identifier);
            }
            if (count($rows) < $size) {
                $database->update(self::SITE_STATE_TABLE, ['config_hash' => $hash, 'pending_hash' => '', 'remap_cursor' => 0], $identifier);
                break;
            }
        }
        return $mapped;
    }

    /**
     * The stored URLs of the connection are not (all) mapped with the current
     * configuration of the site yet: remapSite() has work left.
     */
    public function isRemapPending(Site $site, int $connection): bool
    {
        $state = $this->connectionPool->getConnectionForTable(self::SITE_STATE_TABLE)
            ->select(['config_hash', 'pending_hash'], self::SITE_STATE_TABLE, ['site' => $site->getIdentifier()])
            ->fetchAssociative();
        return $state === false
            || trim((string)$state['pending_hash']) !== ''
            || trim((string)$state['config_hash']) !== self::configHash($site, $connection);
    }

    /**
     * Maps the URLs to the site. A mapping to a page is replaced by another
     * page only, not by "no page" or "not on the site".
     *
     * @param array<int, string> $urls display URL by URL uid
     */
    public function mapUrls(Site $site, array $urls): void
    {
        $this->map($site, $urls, false);
    }

    /**
     * Maps the URLs that have no mapping for the site yet.
     *
     * @param array<int, string> $urls display URL by URL uid
     */
    public function mapUnmappedUrls(Site $site, array $urls): void
    {
        $this->map($site, $urls, true);
    }

    /**
     * @param array<int, string> $urls display URL by URL uid
     */
    private function map(Site $site, array $urls, bool $unmappedOnly): void
    {
        $database = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach (array_chunk($urls, self::CHUNK_SIZE, true) as $chunk) {
            $existing = $this->mappings($database, $site, array_keys($chunk));
            $data = [];
            $replaced = [];
            foreach ($chunk as $uid => $url) {
                $old = $existing[$uid] ?? null;
                if ($old !== null && $unmappedOnly) {
                    continue;
                }
                $match = $this->pageUrlMapper->match($site, $url);
                $new = [$match?->language->getLanguageId() ?? self::NOT_ON_SITE, $match->pageId ?? self::NO_PAGE];
                if ($old !== null) {
                    // The last valid mapping stays: "not on the site" has no page either
                    if ($old === $new || ($old[1] !== self::NO_PAGE && $new[1] === self::NO_PAGE)) {
                        continue;
                    }
                    $replaced[] = $uid;
                }
                $data[] = [$site->getIdentifier(), $uid, ...$new];
            }
            if ($data === []) {
                continue;
            }
            $database->transactional(static function (Connection $database) use ($site, $replaced, $data): void {
                if ($replaced !== []) {
                    $queryBuilder = $database->createQueryBuilder();
                    $queryBuilder->delete(self::TABLE)
                        ->where(
                            $queryBuilder->expr()->eq('site', $queryBuilder->createNamedParameter($site->getIdentifier())),
                            $queryBuilder->expr()->in('url', $queryBuilder->createNamedParameter($replaced, Connection::PARAM_INT_ARRAY)),
                        )
                        ->executeStatement();
                }
                $database->bulkInsert(
                    self::TABLE,
                    $data,
                    ['site', 'url', 'language_id', 'page_id'],
                    [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT],
                );
            });
        }
    }

    /**
     * @param list<int> $urlUids
     * @return array<int, array{int, int}> language and page by URL uid
     */
    private function mappings(Connection $database, Site $site, array $urlUids): array
    {
        $queryBuilder = $database->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('url', 'language_id', 'page_id')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('site', $queryBuilder->createNamedParameter($site->getIdentifier())),
                $queryBuilder->expr()->in('url', $queryBuilder->createNamedParameter($urlUids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery();
        $mappings = [];
        while ($row = $result->fetchAssociative()) {
            $mappings[(int)$row['url']] = [(int)$row['language_id'], (int)$row['page_id']];
        }
        return $mappings;
    }

    /**
     * Hash of what decides which page a URL maps to, apart from the page
     * tree: the connection the site reads from, root page, base, base
     * variants, and base and fallbacks of each language.
     */
    public static function configHash(Site $site, int $connection): string
    {
        $configuration = $site->getConfiguration();
        $languages = [];
        foreach ($site->getAllLanguages() as $language) {
            $languages[] = [
                $language->getLanguageId(),
                (string)$language->getBase(),
                $language->isEnabled(),
                $language->getFallbackType(),
                $language->getFallbackLanguageIds(),
            ];
        }
        $languageConfigurations = is_array($configuration['languages'] ?? null) ? $configuration['languages'] : [];
        return sha1((string)json_encode([
            $connection,
            $site->getRootPageId(),
            (string)$site->getBase(),
            $configuration['base'] ?? '',
            $configuration['baseVariants'] ?? [],
            $languages,
            array_map(
                static fn (mixed $language): array => is_array($language) ? [$language['base'] ?? '', $language['baseVariants'] ?? []] : [],
                $languageConfigurations,
            ),
        ]));
    }
}
