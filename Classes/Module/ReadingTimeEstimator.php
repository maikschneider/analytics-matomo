<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Module;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Estimates how long reading the visible text content of a page takes.
 */
final readonly class ReadingTimeEstimator
{
    /** Below this, time on page says more about navigation than about reading */
    public const MINIMUM_WORDS = 100;

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * @return array{words: int, seconds: int}|null null for pages with too little text
     */
    public function estimate(int $pageId, int $languageId, int $wordsPerMinute): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $rows = $queryBuilder
            ->select('header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([$languageId, -1], Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $words = 0;
        foreach ($rows as $row) {
            $text = html_entity_decode(strip_tags(str_replace('<', ' <', implode(' ', array_map(strval(...), $row)))), ENT_QUOTES | ENT_HTML5);
            $words += (int)preg_match_all('/[\p{L}\p{N}]+/u', $text);
        }
        if ($words < self::MINIMUM_WORDS || $wordsPerMinute <= 0) {
            return null;
        }
        return ['words' => $words, 'seconds' => (int)round($words / $wordsPerMinute * 60)];
    }
}
