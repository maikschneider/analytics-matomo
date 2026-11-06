<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;

/**
 * A page the current backend user may see statistics for.
 */
final readonly class PageTarget
{
    public function __construct(
        public int $pageId,
        public int $languageId,
        public string $pageUrl,
        public MatomoConfiguration $configuration,
    ) {
    }
}
