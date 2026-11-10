<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Redirect;

use MaikSchneider\AnalyticsMatomo\Redirect\NotFoundService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class NotFoundServiceTest extends UnitTestCase
{
    #[Test]
    public function recordedRowsAreAggregatedByPathWithTheirTopReferrer(): void
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/NotFound.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            ['path' => '/videos-old.php', 'hits' => 3, 'referrer' => 'https://www.google.com/'],
            ['path' => '/drums/snare', 'hits' => 1, 'referrer' => ''],
            ['path' => '/wp-login.php', 'hits' => 1, 'referrer' => ''],
        ], NotFoundService::aggregate($rows[0]));
    }

    #[Test]
    public function theTrackedTitleIsDecodedOnceForTheTitleAndOnceForThePath(): void
    {
        self::assertSame(
            ['path' => '/sächsische schweiz', 'referrer' => 'https://example.com/?q=a b'],
            NotFoundService::parse([
                // Matomo's label decodes the title once, so it cannot tell an encoded "%" from a literal one
                'label' => '404/URL = /s%C3%A4chsische%20schweiz?x=1 /From = https://example.com/?q=a b',
                'Actions_PageTitle' => '404/URL = %2Fs%25C3%25A4chsische%2520schweiz%3Fx%3D1 /From = https%3A%2F%2Fexample.com%2F%3Fq%3Da%20b',
            ]),
        );
    }

    #[Test]
    public function withoutTheTrackedTitleTheDecodedLabelIsUsed(): void
    {
        self::assertSame(
            ['path' => '/sächsische schweiz', 'referrer' => 'https://www.google.com/'],
            NotFoundService::parse(['label' => ' 404/URL = /s%C3%A4chsische%20schweiz?x=1 /From = https://www.google.com/']),
        );
    }

    #[Test]
    public function otherTitlesAreNo404Rows(): void
    {
        self::assertNull(NotFoundService::parse(['label' => 'Virtual drum videos']));
        self::assertNull(NotFoundService::parse(['label' => '404/URL = https://evil.example/ /From =']));
        self::assertNull(NotFoundService::parse(['label' => '404/URL = //evil.example/ /From =']));
        self::assertNull(NotFoundService::parse(['label' => -1, 'nb_hits' => 4]));
    }
}
