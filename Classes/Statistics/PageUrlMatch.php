<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final readonly class PageUrlMatch
{
    /**
     * @param int|null $pageId page in the default language; null if the URL is not a page
     */
    public function __construct(
        public SiteLanguage $language,
        public ?int $pageId,
    ) {
    }
}
