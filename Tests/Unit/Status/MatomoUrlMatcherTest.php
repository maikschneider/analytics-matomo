<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Status;

use MaikSchneider\AnalyticsMatomo\Status\MatomoUrlMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MatomoUrlMatcherTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'same host, other scheme' => [['http://example.com'], 'https://example.com/de/', true];
        yield 'www is ignored on both sides' => [['http://www.example.com'], 'https://example.com/', true];
        yield 'host is case-insensitive' => [['https://Example.COM'], 'https://example.com/', true];
        yield 'port is ignored' => [['https://example.com'], 'https://example.com:8443/', true];
        yield 'other host' => [['https://example.com'], 'https://example.de/', false];
        yield 'subdomain needs a wildcard' => [['https://example.com'], 'https://shop.example.com/', false];
        yield 'wildcard subdomain' => [['https://.example.com'], 'https://de.shop.example.com/', true];
        yield 'path prefix' => [['https://example.com/shop'], 'https://example.com/shop/de/', true];
        yield 'path is a whole segment' => [['https://example.com/shop'], 'https://example.com/shopping/', false];
        yield 'other path' => [['https://example.com/shop'], 'https://example.com/blog/', false];
        yield 'root path besides a deeper one' => [['https://example.com/shop', 'https://example.com'], 'https://example.com/blog/', true];
        yield 'second URL' => [['https://example.com', 'https://example.de'], 'https://example.de/', true];
        yield 'no site URLs' => [[], 'https://example.com/', false];
    }

    /**
     * @param list<string> $siteUrls
     */
    #[Test]
    #[DataProvider('urls')]
    public function matchesUrlsLikeTheMatomoTracker(array $siteUrls, string $url, bool $expected): void
    {
        self::assertSame($expected, MatomoUrlMatcher::covers($siteUrls, $url));
    }
}
