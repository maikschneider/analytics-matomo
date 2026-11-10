<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Redirect;

/**
 * A path that leads to a page: its current URL path or an old slug, which a redirect still knows
 */
final readonly class RedirectCandidate
{
    /**
     * @param int $pageId page in the default language
     */
    public function __construct(
        public int $pageId,
        public int $languageId,
        public string $title,
        public string $path,
        public bool $oldSlug = false,
    ) {
    }
}
