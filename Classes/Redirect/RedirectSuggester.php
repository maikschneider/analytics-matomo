<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Redirect;

/**
 * Ranks pages as redirect targets for a path that ended on the 404 page. The score
 * is the mean Levenshtein similarity of the whole path and of its last segment:
 * the whole path finds typos, the last segment finds pages moved to another folder.
 */
final class RedirectSuggester
{
    public const MINIMUM_SCORE = 50;
    public const LIMIT = 3;

    /**
     * @param list<RedirectCandidate> $candidates
     * @return list<RedirectSuggestion> the best path of each page, best first
     */
    public function suggest(string $path, array $candidates): array
    {
        $path = self::normalize($path);
        if ($path === '') {
            return [];
        }
        $best = [];
        foreach ($candidates as $candidate) {
            $candidatePath = self::normalize($candidate->path);
            $score = (int)round(50 * (self::similarity($path, $candidatePath) + self::similarity(self::lastSegment($path), self::lastSegment($candidatePath))));
            $key = $candidate->pageId . '-' . $candidate->languageId;
            if ($score >= self::MINIMUM_SCORE && $score > ($best[$key]->score ?? -1)) {
                $best[$key] = new RedirectSuggestion($candidate, $score);
            }
        }
        $suggestions = array_values($best);
        usort($suggestions, static fn (RedirectSuggestion $a, RedirectSuggestion $b): int => [$b->score, $a->candidate->path, $a->candidate->pageId] <=> [$a->score, $b->candidate->path, $b->candidate->pageId]);
        return array_slice($suggestions, 0, self::LIMIT);
    }

    /**
     * Without query, case, outer slashes and a file extension of an old static site
     */
    private static function normalize(string $path): string
    {
        $path = mb_strtolower(trim(rawurldecode((string)parse_url($path, PHP_URL_PATH)), '/'));
        return (string)preg_replace('/\.(html?|php|aspx?)$/', '', $path);
    }

    private static function lastSegment(string $path): string
    {
        $position = strrpos($path, '/');
        return $position === false ? $path : substr($path, $position + 1);
    }

    private static function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        return 1 - levenshtein($a, $b) / max(strlen($a), strlen($b));
    }
}
