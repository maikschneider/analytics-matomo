<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

/**
 * CSV for spreadsheets: UTF-8 with a byte order mark, so Excel detects the encoding.
 * Text cells starting like a formula get a leading apostrophe, as labels come from
 * tracked URLs, titles and search keywords (OWASP "CSV injection").
 */
final readonly class CsvExport
{
    /**
     * @param list<string> $header
     * @param list<list<mixed>> $rows
     */
    public function render(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open a temporary stream for the CSV export', 1794080001);
        }
        foreach ([$header, ...$rows] as $row) {
            fputcsv($stream, array_map($this->cell(...), $row), ',', '"', '', "\n");
        }
        rewind($stream);
        $csv = (string)stream_get_contents($stream);
        fclose($stream);
        return "\u{FEFF}" . $csv;
    }

    private function cell(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $value = is_scalar($value) ? (string)$value : '';
        if ($value !== '' && !is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
