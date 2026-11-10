<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Redirect;

final readonly class RedirectSuggestion
{
    /**
     * @param int $score 0 to 100
     */
    public function __construct(
        public RedirectCandidate $candidate,
        public int $score,
    ) {
    }
}
