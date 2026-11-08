<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Annotation;

use MaikSchneider\AnalyticsMatomo\Annotation\Annotation;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AnnotationTest extends UnitTestCase
{
    #[Test]
    public function readsTheRecordedEmptyAnswer(): void
    {
        [$all] = $this->fixture('Annotations.json');
        self::assertSame([], Annotation::listFromApiResult($all));
    }

    #[Test]
    public function readsMatomo5AndMatomo4ShapesSortedByDateWithDecodedNotes(): void
    {
        $fixture = $this->fixture('SyntheticAnnotations.json');

        $annotations = Annotation::listFromApiResult($fixture['matomo5']);
        self::assertSame(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'], array_map(static fn (Annotation $a): string => $a->date, $annotations));
        self::assertSame('Newsletter "November" sent', $annotations[1]->note);

        $annotations = Annotation::listFromApiResult($fixture['matomo4']);
        self::assertSame(['2026-11-01', '2026-11-04'], array_map(static fn (Annotation $a): string => $a->date, $annotations));
    }

    #[Test]
    public function rejectsAnError(): void
    {
        [, $error] = $this->fixture('Annotations.json');
        $this->expectException(MatomoApiException::class);
        Annotation::listFromApiResult($error);
    }

    #[Test]
    public function findsTheNotesOfOnePageUrlOnly(): void
    {
        $annotations = Annotation::listFromApiResult($this->fixture('SyntheticAnnotations.json')['matomo5']);

        $about = Annotation::forPage($annotations, 'https://example.com/about');
        self::assertCount(1, $about);
        self::assertSame('2026-11-02', $about[0]->date);

        $german = Annotation::forPage($annotations, 'https://example.com/de/ueber-uns?tab=1&view=2');
        self::assertCount(1, $german);
        self::assertSame([], Annotation::forPage($annotations, 'https://example.com/'));
    }

    #[Test]
    public function noteStartsWithThePageUrlAndFitsMatomosLimit(): void
    {
        $note = Annotation::note('https://example.com/about', 'editor');
        self::assertSame('TYPO3: https://example.com/about edited by editor', $note);
        self::assertCount(1, Annotation::forPage([new Annotation('2026-11-02', (string)$note)], 'https://example.com/about'));

        $long = (string)Annotation::note('https://example.com/about', str_repeat('x', 300));
        self::assertSame(255, mb_strlen($long));
        self::assertCount(1, Annotation::forPage([new Annotation('2026-11-02', $long)], 'https://example.com/about'));

        self::assertNull(Annotation::note('https://example.com/' . str_repeat('a', 250), 'editor'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/' . $name), true, flags: JSON_THROW_ON_ERROR);
    }
}
