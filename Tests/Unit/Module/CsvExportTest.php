<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Module;

use MaikSchneider\AnalyticsMatomo\Module\CsvExport;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CsvExportTest extends UnitTestCase
{
    #[Test]
    public function rowsAreQuotedWhereNeededAndPrefixedWithAByteOrderMark(): void
    {
        $csv = (new CsvExport())->render(['Label', 'Visits'], [['a, "b"', 3], ['c', 0.25], ['d', null]]);

        self::assertSame("\u{FEFF}Label,Visits\n\"a, \"\"b\"\"\",3\nc,0.25\nd,\n", $csv);
    }

    #[Test]
    public function textThatSpreadsheetsWouldEvaluateIsNeutralised(): void
    {
        $csv = (new CsvExport())->render(['=Header'], [['=HYPERLINK("x")'], ['+1+1'], ['-cmd'], ['@SUM(A1)'], ["\tx"], ["\rx"], [-0.5], ['-0.5'], ['https://example.com/a=b']]);
        $lines = explode("\n", substr($csv, 3));

        self::assertSame("'=Header", $lines[0]);
        self::assertSame('"\'=HYPERLINK(""x"")"', $lines[1]);
        self::assertSame("'+1+1", $lines[2]);
        self::assertSame("'-cmd", $lines[3]);
        self::assertSame("'@SUM(A1)", $lines[4]);
        self::assertSame("\"'\tx\"", $lines[5]);
        self::assertSame("\"'\rx\"", $lines[6]);
        // Numbers stay numbers
        self::assertSame('-0.5', $lines[7]);
        self::assertSame('-0.5', $lines[8]);
        self::assertSame('https://example.com/a=b', $lines[9]);
    }
}
