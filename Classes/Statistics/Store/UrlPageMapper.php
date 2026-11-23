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
 * the imported day again, so current URLs follow the current page tree, and
 * URLs not seen anymore keep their last mapping (an old slug keeps counting
 * for its page). All stored URLs are mapped again when the site is new to the
 * connection or its base, languages or base variants changed.
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
     * Maps all stored URLs of the connection for the site when the site was
     * not mapped for this connection yet or its configuration changed.
     *
     * @return bool whether the URLs were mapped
     */
    public function refreshSite(Site $site, int $connection): bool
    {
        $database = $this->connectionPool->getConnectionForTable(self::SITE_STATE_TABLE);
        $stored = $database->select(['config_hash'], self::SITE_STATE_TABLE, ['site' => $site->getIdentifier()])->fetchOne();
        $hash = self::configHash($site, $connection);
        if ($stored === $hash) {
            return false;
        }

        $urlDatabase = $this->connectionPool->getConnectionForTable(UrlDayRepository::URL_TABLE);
        $last = 0;
        do {
            $queryBuilder = $urlDatabase->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder->select('uid', 'url')
                ->from(UrlDayRepository::URL_TABLE)
                ->where(
                    $queryBuilder->expr()->eq('connection', $queryBuilder->createNamedParameter($connection, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($last, Connection::PARAM_INT)),
                )
                ->orderBy('uid')
                ->setMaxResults(self::CHUNK_SIZE)
                ->executeQuery()
                ->fetchAllAssociative();
            $urls = [];
            foreach ($rows as $row) {
                $urls[(int)$row['uid']] = (string)$row['url'];
            }
            $this->mapUrls($site, $urls);
            $last = $urls === [] ? $last : max(array_keys($urls));
        } while (count($rows) === self::CHUNK_SIZE);

        if ($stored === false) {
            try {
                $database->insert(self::SITE_STATE_TABLE, ['site' => $site->getIdentifier(), 'config_hash' => $hash]);
                return true;
            } catch (UniqueConstraintViolationException) {
            }
        }
        $database->update(self::SITE_STATE_TABLE, ['config_hash' => $hash], ['site' => $site->getIdentifier()]);
        return true;
    }

    /**
     * Maps the URLs to the site, replacing their previous mapping.
     *
     * @param array<int, string> $urls display URL by URL uid
     */
    public function mapUrls(Site $site, array $urls): void
    {
        $database = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach (array_chunk($urls, self::CHUNK_SIZE, true) as $chunk) {
            $data = [];
            foreach ($chunk as $uid => $url) {
                $match = $this->pageUrlMapper->match($site, $url);
                $data[] = [
                    $site->getIdentifier(),
                    $uid,
                    $match?->language->getLanguageId() ?? self::NOT_ON_SITE,
                    $match->pageId ?? self::NO_PAGE,
                ];
            }
            $database->transactional(static function (Connection $database) use ($site, $chunk, $data): void {
                $queryBuilder = $database->createQueryBuilder();
                $queryBuilder->delete(self::TABLE)
                    ->where(
                        $queryBuilder->expr()->eq('site', $queryBuilder->createNamedParameter($site->getIdentifier())),
                        $queryBuilder->expr()->in('url', $queryBuilder->createNamedParameter(array_keys($chunk), Connection::PARAM_INT_ARRAY)),
                    )
                    ->executeStatement();
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
