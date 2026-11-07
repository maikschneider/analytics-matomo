<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class PageRecordResolver
{
    public function __construct(private Context $context)
    {
    }

    /**
     * The router looks the page up like a visitor and answers with the site
     * base for a hidden page. Its slug still is the URL it had or will have.
     *
     * @return array<string, mixed>|int
     */
    public function resolve(int $pageId, SiteLanguage $language): array|int
    {
        $context = clone $this->context;
        $context->setAspect('visibility', new VisibilityAspect(includeHiddenPages: true));
        $context->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));
        $page = GeneralUtility::makeInstance(PageRepository::class, $context)->getPage($pageId, true);
        return $page === [] ? $pageId : $page;
    }
}
