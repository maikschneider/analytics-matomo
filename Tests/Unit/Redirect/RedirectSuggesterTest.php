<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Redirect;

use MaikSchneider\AnalyticsMatomo\Redirect\RedirectCandidate;
use MaikSchneider\AnalyticsMatomo\Redirect\RedirectSuggester;
use MaikSchneider\AnalyticsMatomo\Redirect\RedirectSuggestion;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RedirectSuggesterTest extends UnitTestCase
{
    #[Test]
    public function aMistypedPathSuggestsThePageWithTheClosestSlugFirst(): void
    {
        $suggestions = (new RedirectSuggester())->suggest('/bikes/e-bike/cargo', [
            new RedirectCandidate(2, 0, 'Bikes', '/bikes'),
            new RedirectCandidate(5, 0, 'Cargo e-bikes', '/bikes/e-bikes/cargo'),
            new RedirectCandidate(18, 0, 'Contact', '/contact'),
        ]);

        self::assertSame([[5, 97]], $this->summary($suggestions));
    }

    #[Test]
    public function theLastSegmentFindsAPageThatMovedToAnotherFolder(): void
    {
        $suggestions = (new RedirectSuggester())->suggest('/news/elbe-cycle-route.html', [
            new RedirectCandidate(12, 0, 'Elbe Cycle Route', '/tours/elbe-cycle-route'),
            new RedirectCandidate(14, 0, 'News', '/news'),
        ]);

        self::assertSame([[12, 91]], $this->summary($suggestions));
    }

    #[Test]
    public function anOldSlugCountsForItsPageAndOnlyItsBestPathIsSuggested(): void
    {
        $suggestions = (new RedirectSuggester())->suggest('/workshop/price-list', [
            new RedirectCandidate(9, 0, 'Repair prices', '/workshop/prices'),
            new RedirectCandidate(9, 0, 'Repair prices', '/workshop/price-list', true),
        ]);

        self::assertCount(1, $suggestions);
        self::assertSame(100, $suggestions[0]->score);
        self::assertTrue($suggestions[0]->candidate->oldSlug);
    }

    #[Test]
    public function equalScoresAreRankedByPathThenPage(): void
    {
        $suggestions = (new RedirectSuggester())->suggest('/tour', [
            new RedirectCandidate(13, 0, 'B', '/tours'),
            new RedirectCandidate(11, 0, 'A', '/tours'),
            new RedirectCandidate(12, 0, 'C', '/touro'),
        ]);

        self::assertSame([[12, 80], [11, 80], [13, 80]], $this->summary($suggestions));
    }

    #[Test]
    public function weakMatchesAreDroppedAndAtMostThreeSuggested(): void
    {
        $candidates = [];
        foreach (['/about-us', '/about-me', '/about-them', '/about-all', '/imprint'] as $index => $path) {
            $candidates[] = new RedirectCandidate($index + 1, 0, 'Page', $path);
        }

        $suggestions = (new RedirectSuggester())->suggest('/about-uss', $candidates);

        self::assertSame([1, 4, 2], array_map(static fn (RedirectSuggestion $suggestion): int => $suggestion->candidate->pageId, $suggestions));
        self::assertSame([], (new RedirectSuggester())->suggest('/wp-login.php', $candidates));
    }

    #[Test]
    public function caseAndTrailingSlashesDoNotMatter(): void
    {
        $suggestions = (new RedirectSuggester())->suggest('/Products', [new RedirectCandidate(11, 0, 'Products', '/products/')]);

        self::assertSame([[11, 100]], $this->summary($suggestions));
    }

    /**
     * @param list<RedirectSuggestion> $suggestions
     * @return list<array{int, int}>
     */
    private function summary(array $suggestions): array
    {
        return array_map(static fn (RedirectSuggestion $suggestion): array => [$suggestion->candidate->pageId, $suggestion->score], $suggestions);
    }
}
