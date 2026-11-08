<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Annotation;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;

/**
 * A Matomo annotation. Notes written by this extension start with the page URL,
 * so they can be told apart from other annotations and assigned to their page.
 */
final readonly class Annotation
{
    private const PREFIX = 'TYPO3: ';
    private const MAX_LENGTH = 255;

    /**
     * @param string $date Y-m-d
     */
    public function __construct(
        public string $date,
        public string $note,
    ) {
    }

    /**
     * Matomo 5+ answers Annotations.getAll with a list per site ID, Matomo 4 with
     * an object keyed by note ID, and the JSON renderer may turn the site map into
     * a list; the annotations are found at any depth.
     *
     * @return list<self> oldest first
     * @throws MatomoApiException
     */
    public static function listFromApiResult(mixed $result): array
    {
        if (!is_array($result) || ($result['result'] ?? null) === 'error') {
            throw new MatomoApiException('Matomo answered with unexpected annotations: ' . mb_substr((string)json_encode($result), 0, 200), 1794120001);
        }
        $annotations = [];
        $collect = static function (array $node) use (&$collect, &$annotations): void {
            if (isset($node['date'], $node['note']) && is_string($node['date']) && is_scalar($node['note'])) {
                $annotations[] = new self(substr($node['date'], 0, 10), html_entity_decode((string)$node['note'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                return;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        $collect($result);
        usort($annotations, static fn (self $a, self $b): int => $a->date <=> $b->date);
        return $annotations;
    }

    /**
     * @param list<self> $annotations
     * @return list<self>
     */
    public static function forPage(array $annotations, string $pageUrl): array
    {
        $prefix = self::PREFIX . $pageUrl . ' ';
        return array_values(array_filter($annotations, static fn (self $annotation): bool => str_starts_with($annotation->note, $prefix)));
    }

    /**
     * @return string|null null when the URL leaves no room for the rest of the note
     */
    public static function note(string $pageUrl, string $username): ?string
    {
        $note = self::PREFIX . $pageUrl . ' edited by ' . $username;
        if (mb_strlen(self::PREFIX . $pageUrl . ' edited by ') >= self::MAX_LENGTH) {
            return null;
        }
        return mb_substr($note, 0, self::MAX_LENGTH);
    }
}
