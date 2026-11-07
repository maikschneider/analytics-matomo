<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Decides whether statistics are shown for a page: the backend user has the page
 * statistics permission and may see the page and language, the page has a
 * frontend URL and its site has a Matomo connection.
 */
final readonly class PageTargetResolver
{
    public const DOKTYPES_WITHOUT_URL = [
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];

    public function __construct(
        private SiteFinder $siteFinder,
        private MatomoConfigurationResolver $configurationResolver,
        private Context $context,
    ) {
    }

    public function resolve(int $pageId, int $languageId): ?PageTarget
    {
        $backendUser = $this->getBackendUser();
        if ($pageId <= 0 || !StatisticsPermission::isGranted($backendUser, StatisticsPermission::PAGE)) {
            return null;
        }
        $page = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (!is_array($page) || in_array((int)($page['doktype'] ?? 0), self::DOKTYPES_WITHOUT_URL, true)) {
            return null;
        }
        if (!$backendUser->checkLanguageAccess($languageId)) {
            return null;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $language = $site->getLanguageById($languageId);
        } catch (SiteNotFoundException|\InvalidArgumentException) {
            return null;
        }

        $configuration = $this->configurationResolver->resolve($site);
        if ($configuration === null) {
            return null;
        }

        $pageUrl = (string)$site->getRouter()->generateUri($this->getPage($pageId, $language), ['_language' => $language], '', RouterInterface::ABSOLUTE_URL);

        return new PageTarget($pageId, $languageId, $pageUrl, $configuration);
    }

    /**
     * The router looks the page up like a visitor and answers with the site
     * base for a hidden page. Its slug still is the URL it had or will have.
     *
     * @return array<string, mixed>|int
     */
    private function getPage(int $pageId, SiteLanguage $language): array|int
    {
        $context = clone $this->context;
        $context->setAspect('visibility', new VisibilityAspect(includeHiddenPages: true));
        $context->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));
        $page = GeneralUtility::makeInstance(PageRepository::class, $context)->getPage($pageId, true);
        return $page === [] ? $pageId : $page;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
