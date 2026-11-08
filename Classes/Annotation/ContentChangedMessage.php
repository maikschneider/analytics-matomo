<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Annotation;

/**
 * An editor changed a page in the live workspace. Handled synchronously by
 * default; route it to an asynchronous transport to keep Matomo out of the save.
 */
final readonly class ContentChangedMessage
{
    /**
     * @param string $date day of the change (Y-m-d)
     */
    public function __construct(
        public string $siteIdentifier,
        public int $pageId,
        public int $languageId,
        public string $username,
        public string $date,
    ) {
    }
}
