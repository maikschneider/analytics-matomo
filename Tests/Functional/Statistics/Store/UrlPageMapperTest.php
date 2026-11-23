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
    public function refreshSiteMapsAllStoredUrlsOfASiteNewToTheConnection(): void
    {
        $uids = $this->storeDay(1, 20261120, ['https://example.com/about' => [5], 'https://shop.example.org/products/' => [3]]);
        $uids += $this->storeDay(1, 20261119, ['https://shop.example.de/produkte/' => [1]]);
        $this->storeDay(2, 20261120, ['https://shop.example.org/imprint' => [1]]);
        $this->mapper()->mapUrls($this->site('main'), $this->urls($uids));

        self::assertTrue($this->mapper()->refreshSite($this->site('shop'), 1));

        self::assertSame([
            $uids['https://example.com/about'] => [-1, 0],
            $uids['https://shop.example.org/products/'] => [0, 11],
            $uids['https://shop.example.de/produkte/'] => [1, 11],
        ], $this->mappings('shop'));
        // Mapped once: the next refresh with the same configuration does nothing
        self::assertFalse($this->mapper()->refreshSite($this->site('shop'), 1));
    }

    #[Test]
    public function refreshSiteMapsAllStoredUrlsAgainWhenTheSiteConfigurationChanges(): void
    {
        $uids = $this->storeDay(1, 20261120, ['https://example.com/fr/about' => [5]]);
        $this->mapper()->refreshSite($this->site('main'), 1);
        self::assertSame([$uids['https://example.com/fr/about'] => [0, 0]], $this->mappings('main'));
        self::assertFalse($this->mapper()->refreshSite($this->site('main'), 1));

        $configuration = $this->site('main')->getConfiguration();
        $configuration['languages'][] = [
            'title' => 'Français', 'enabled' => true, 'languageId' => 2, 'base' => '/fr/', 'locale' => 'fr_FR.UTF-8',
            'fallbackType' => 'fallback', 'fallbacks' => '0',
        ];
        $changed = new Site('main', 1, $configuration);

        self::assertTrue($this->mapper()->refreshSite($changed, 1));
        self::assertSame([$uids['https://example.com/fr/about'] => [2, 2]], $this->mappings('main'));
    }

    #[Test]
    public function refreshSiteMapsAllStoredUrlsWhenTheSiteMovesToAnotherConnection(): void
    {
        $this->mapper()->refreshSite($this->site('main'), 1);
        $uids = $this->storeDay(2, 20261120, ['https://example.com/about' => [5]]);

        self::assertTrue($this->mapper()->refreshSite($this->site('main'), 2));
        self::assertSame([$uids['https://example.com/about'] => [0, 2]], $this->mappings('main'));
    }

    #[Test]
    public function refreshSiteMapsLargeConnectionsInChunks(): void
    {
        $counts = [];
        for ($i = 0; $i < 2100; $i++) {
            $counts['https://example.com/page-' . $i] = [1];
        }
        $this->storeDay(1, 20261120, $counts);

        $this->mapper()->refreshSite($this->site('main'), 1);

        self::assertCount(2100, $this->mappings('main'));
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
