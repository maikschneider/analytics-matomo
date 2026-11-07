<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

/**
 * The rows of a Matomo URL report that belong to one site, by page.
 */
final readonly class PageUrlReport
{
    /**
     * @param list<PageUrlRow> $rows
     * @param bool $isTruncated Matomo cut off rows at its archiving limit
     */
    public function __construct(
        public array $rows,
        public bool $isTruncated,
    ) {
    }

    /**
     * Rows of every URL of the page in the language. An empty list means the
     * page had no visits; null means unknown, as the page may be in the rows
     * Matomo cut off.
     *
     * @return list<array<string, mixed>>|null
     */
    public function rowsOfPage(int $pageId, int $languageId): ?array
    {
        $rows = [];
        foreach ($this->rows as $row) {
            if ($row->match->pageId === $pageId && $row->match->language->getLanguageId() === $languageId) {
                $rows[] = $row->row;
            }
        }
        return $rows === [] && $this->isTruncated ? null : $rows;
    }
}
