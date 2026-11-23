<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Statistics\Store;

use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlDayRows;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlPageMapper;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UrlPageMapperTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database.csv');
    }

    #[Test]
    public function mapUrlsStoresLanguageAndPageOfEachUrl(): void
    {
        $uids = $this->storeDay(1, 20261120, [
            'https://example.com/about' => [5],
            'https://example.com/de/ueber-uns' => [3],
            'https://example.com/videos.php' => [2],
            'https://www.virtual-drums.com/' => [1],
        ]);

        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));

        self::assertSame([
            $uids['https://example.com/about'] => [0, 2],
            $uids['https://example.com/de/ueber-uns'] => [1, 2],
            // On the site, not routable
            $uids['https://example.com/videos.php'] => [0, 0],
            // Another domain
            $uids['https://www.virtual-drums.com/'] => [-1, 0],
        ], $this->mappings('main'));
    }

    #[Test]
    public function aUrlFollowsThePageOnceItRoutesAtTheNextImport(): void
    {
        $uids = $this->storeDay(1, 20261120, ['https://example.com/new-page' => [5]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));
        self::assertSame([$uids['https://example.com/new-page'] => [0, 0]], $this->mappings('main'));

        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 8, 'pid' => 1, 'title' => 'New page', 'slug' => '/new-page', 'doktype' => 1,
            'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 1,
        ]);
        $uids = $this->storeDay(1, 20261121, ['https://example.com/new-page' => [2]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));

        self::assertSame([$uids['https://example.com/new-page'] => [0, 8]], $this->mappings('main'));
    }

    #[Test]
    public function anOldSlugKeepsItsPageAndAUrlRoutingToAnotherPageFollowsIt(): void
    {
        $uids = $this->storeDay(1, 20261120, ['https://example.com/about' => [5], 'https://example.com/summer-sale' => [1]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));
        self::assertSame([$uids['https://example.com/about'] => [0, 2], $uids['https://example.com/summer-sale'] => [0, 5]], $this->mappings('main'));

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->update('pages', ['slug' => '/about-us'], ['uid' => 2]);
        $pages->update('pages', ['slug' => '/sale'], ['uid' => 5]);
        $pages->insert('pages', [
            'uid' => 8, 'pid' => 1, 'title' => 'Summer sale 2027', 'slug' => '/summer-sale', 'doktype' => 1,
            'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 1,
        ]);
        // An old day imported again, and a re-map after a new language
        $this->storeDay(1, 20261119, ['https://example.com/about' => [1], 'https://example.com/summer-sale' => [1]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));
        $this->mapper()->remapSite($this->withFrench($this->site('main')), 1, 100);

        // /about does not route anymore: it keeps counting for page 2. /summer-sale routes to page 8 now.
        self::assertSame([$uids['https://example.com/about'] => [0, 2], $uids['https://example.com/summer-sale'] => [0, 8]], $this->mappings('main'));
    }

    #[Test]
    public function mapUnmappedUrlsLeavesMappedUrlsAlone(): void
    {
        $uids = $this->storeDay(1, 20261120, ['https://example.com/new-page' => [5]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 8, 'pid' => 1, 'title' => 'New page', 'slug' => '/new-page', 'doktype' => 1,
            'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 1,
        ]);
        $uids += $this->storeDay(1, 20261119, ['https://example.com/about' => [1]]);

        $this->mapper()->mapUnmappedUrls($this->site('main'), $this->urls($uids));

        self::assertSame([$uids['https://example.com/new-page'] => [0, 0], $uids['https://example.com/about'] => [0, 2]], $this->mappings('main'));
    }

    #[Test]
    public function remapSiteMapsAllStoredUrlsOfASiteNewToTheConnection(): void
    {
        $uids = $this->storeDay(1, 20261120, ['https://example.com/about' => [5], 'https://shop.example.org/products/' => [3]]);
        $uids += $this->storeDay(1, 20261119, ['https://shop.example.de/produkte/' => [1]]);
        $this->storeDay(2, 20261120, ['https://shop.example.org/imprint' => [1]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));

        self::assertSame(3, $this->mapper()->remapSite($this->site('shop'), 1, 100));

        self::assertSame([
            $uids['https://example.com/about'] => [-1, 0],
            $uids['https://shop.example.org/products/'] => [0, 11],
            $uids['https://shop.example.de/produkte/'] => [1, 11],
        ], $this->mappings('shop'));
        self::assertSame(['config_hash' => UrlPageMapper::configHash($this->site('shop'), 1), 'pending_hash' => '', 'remap_cursor' => 0], $this->state('shop'));
        // Mapped once: the next re-map with the same configuration does nothing
        self::assertSame(0, $this->mapper()->remapSite($this->site('shop'), 1, 100));
    }

    #[Test]
    public function remapSiteMapsAllStoredUrlsAgainWhenTheSiteConfigurationChanges(): void
    {
        $uids = $this->storeDay(1, 20261120, ['https://example.com/fr/about' => [5]]);
        $this->mapper()->remapSite($this->site('main'), 1, 100);
        self::assertSame([$uids['https://example.com/fr/about'] => [0, 0]], $this->mappings('main'));
        self::assertSame(0, $this->mapper()->remapSite($this->site('main'), 1, 100));

        self::assertSame(1, $this->mapper()->remapSite($this->withFrench($this->site('main')), 1, 100));
        self::assertSame([$uids['https://example.com/fr/about'] => [2, 2]], $this->mappings('main'));
    }

    #[Test]
    public function remapSiteMapsAllStoredUrlsWhenTheSiteMovesToAnotherConnection(): void
    {
        $this->mapper()->remapSite($this->site('main'), 1, 100);
        $uids = $this->storeDay(2, 20261120, ['https://example.com/about' => [5]]);

        self::assertSame(1, $this->mapper()->remapSite($this->site('main'), 2, 100));
        self::assertSame([$uids['https://example.com/about'] => [0, 2]], $this->mappings('main'));
    }

    #[Test]
    public function remapSiteMapsLargeConnectionsInChunks(): void
    {
        $this->storeDay(1, 20261120, $this->pages(2100));

        self::assertSame(2100, $this->mapper()->remapSite($this->site('main'), 1, 5000));

        self::assertCount(2100, $this->mappings('main'));
    }

    #[Test]
    public function anInterruptedRemapResumesFromItsCursorWithinTheLimit(): void
    {
        $uids = $this->storeDay(1, 20261120, $this->pages(2500));
        $hash = UrlPageMapper::configHash($this->site('main'), 1);

        self::assertSame(1200, $this->mapper()->remapSite($this->site('main'), 1, 1200));

        self::assertCount(1200, $this->mappings('main'));
        $cursor = $uids['https://example.com/page-1199'];
        self::assertSame(['config_hash' => '', 'pending_hash' => $hash, 'remap_cursor' => $cursor], $this->state('main'));

        // The mapping of a URL before the cursor is not repeated
        $this->getConnectionPool()->getConnectionForTable(UrlPageMapper::TABLE)->delete(UrlPageMapper::TABLE, ['site' => 'main', 'url' => $uids['https://example.com/page-0']]);
        self::assertSame(1000, $this->mapper()->remapSite($this->site('main'), 1, 1000));
        self::assertCount(2199, $this->mappings('main'));
        self::assertSame($uids['https://example.com/page-2199'], $this->state('main')['remap_cursor']);

        self::assertSame(300, $this->mapper()->remapSite($this->site('main'), 1, 1000));
        self::assertSame(['config_hash' => $hash, 'pending_hash' => '', 'remap_cursor' => 0], $this->state('main'));
        self::assertSame(0, $this->mapper()->remapSite($this->site('main'), 1, 1000));
    }

    #[Test]
    public function aConfigurationChangeDuringAPendingRemapRestartsIt(): void
    {
        $uids = $this->storeDay(1, 20261120, ['https://example.com/fr/about' => [1]] + $this->pages(10));
        self::assertSame(5, $this->mapper()->remapSite($this->site('main'), 1, 5));

        $changed = $this->withFrench($this->site('main'));
        self::assertSame(11, $this->mapper()->remapSite($changed, 1, 100));

        self::assertSame([2, 2], $this->mappings('main')[$uids['https://example.com/fr/about']]);
        self::assertSame(['config_hash' => UrlPageMapper::configHash($changed, 1), 'pending_hash' => '', 'remap_cursor' => 0], $this->state('main'));
    }

    #[Test]
    public function sumByPageSumsTheUrlsOfAPagePerLanguage(): void
    {
        $uids = $this->storeDay(1, 20261120, [
            'https://example.com/about' => [5, 4, 30, 3, 2, 1],
            'https://example.com/about/' => [2, 2, 10, 1, 1, 1],
            'https://example.com/de/ueber-uns' => [3, 1],
            'https://example.com/videos.php' => [7],
            'https://www.virtual-drums.com/' => [9],
        ]);
        $uids += $this->storeDay(1, 20261121, ['https://example.com/about' => [1], 'https://example.com/' => [4]]);
        $uids += $this->storeDay(1, 20261122, ['https://example.com/about' => [100]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));
        // Shop maps the URLs of the connection too, to none of its pages
        $this->mapper()->mapUrls($this->site('shop'), $this->urls($uids));
        // Another connection with the same URL
        $other = $this->storeDay(2, 20261120, ['https://example.com/about' => [50]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($other));

        $sums = $this->repository()->sumByPage('main', 1, 20261120, 20261121);

        $pages = array_keys($sums);
        sort($pages);
        // Not the URL that does not route (page 0), nor the one of another domain (-1)
        self::assertSame([1, 2], $pages);
        self::assertSame(
            ['nb_hits' => 8, 'nb_visits' => 6, 'sum_time_spent' => 40, 'entry_nb_visits' => 4, 'entry_bounce_count' => 3, 'exit_nb_visits' => 2],
            $sums[2][0],
        );
        self::assertSame(3, $sums[2][1]['nb_hits']);
        self::assertSame(1, $sums[2][1]['nb_visits']);
        self::assertSame([0], array_keys($sums[1]));
        self::assertSame(4, $sums[1][0]['nb_hits']);
        self::assertSame([], $this->repository()->sumByPage('shop', 1, 20261120, 20261121));
        self::assertSame(50, $this->repository()->sumByPage('main', 2, 20261120, 20261121)[2][0]['nb_hits'] ?? null);
        self::assertSame([], $this->repository()->sumByPage('main', 1, 20261101, 20261110));
    }

    /**
     * Adds the URLs and counts of the day, as the importer does.
     *
     * @param array<string, list<int>> $counts by URL, in the order of PageMetrics::COUNTS
     * @return array<string, int> uid by URL
     */
    private function storeDay(int $connection, int $day, array $counts): array
    {
        $rows = [];
        foreach ($counts as $url => $values) {
            $rows[] = ['label' => $url, 'url' => $url] + array_combine(
                ['nb_hits', 'nb_visits', 'sum_time_spent', 'entry_nb_visits', 'entry_bounce_count', 'exit_nb_visits'],
                array_pad($values, 6, 0),
            );
        }
        $urlDay = UrlDayRows::fromReport($rows);
        $uids = $this->repository()->addUrls($connection, $day, $urlDay);
        $this->getConnectionPool()->getConnectionForTable(UrlDayRepository::TABLE)->transactional(
            fn (Connection $database) => $this->repository()->replaceDayFacts($database, $connection, $day, $urlDay, $uids),
        );
        $byUrl = [];
        foreach (array_keys($counts) as $url) {
            $byUrl[$url] = $uids[(string)UrlDayRows::hash($url)];
        }
        return $byUrl;
    }

    /**
     * @param array<string, int> $uids uid by URL
     * @return array<int, string>
     */
    private function urls(array $uids): array
    {
        return array_flip($uids);
    }

    /**
     * @return array<int, array{int, int}> language and page by URL uid
     */
    private function mappings(string $site): array
    {
        $mappings = [];
        $rows = $this->getConnectionPool()->getConnectionForTable(UrlPageMapper::TABLE)
            ->select(['url', 'language_id', 'page_id'], UrlPageMapper::TABLE, ['site' => $site], [], ['url' => 'ASC'])
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $mappings[(int)$row['url']] = [(int)$row['language_id'], (int)$row['page_id']];
        }
        return $mappings;
    }

    /**
     * @return array<string, list<int>>
     */
    private function pages(int $count): array
    {
        $counts = [];
        for ($i = 0; $i < $count; $i++) {
            $counts['https://example.com/page-' . $i] = [1];
        }
        return $counts;
    }

    /**
     * @return array{config_hash: string, pending_hash: string, remap_cursor: int}
     */
    private function state(string $site): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(UrlPageMapper::SITE_STATE_TABLE)
            ->select(['config_hash', 'pending_hash', 'remap_cursor'], UrlPageMapper::SITE_STATE_TABLE, ['site' => $site])
            ->fetchAssociative();
        self::assertIsArray($row);
        return ['config_hash' => trim((string)$row['config_hash']), 'pending_hash' => trim((string)$row['pending_hash']), 'remap_cursor' => (int)$row['remap_cursor']];
    }

    private function withFrench(Site $site): Site
    {
        $configuration = $site->getConfiguration();
        $configuration['languages'][] = [
            'title' => 'Français', 'enabled' => true, 'languageId' => 2, 'base' => '/fr/', 'locale' => 'fr_FR.UTF-8',
            'fallbackType' => 'fallback', 'fallbacks' => '0',
        ];
        return new Site($site->getIdentifier(), $site->getRootPageId(), $configuration);
    }

    private function site(string $identifier): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier($identifier);
    }

    private function mapper(): UrlPageMapper
    {
        return new UrlPageMapper($this->get(PageUrlMapper::class), $this->getConnectionPool());
    }

    private function repository(): UrlDayRepository
    {
        return new UrlDayRepository($this->getConnectionPool());
    }
}
