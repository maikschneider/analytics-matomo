<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Statistics;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

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
        private PageRecordResolver $pageRecordResolver,
    ) {
    }

    /**
     * @param bool $requireBarPermission false where module access already grants the page statistics (Page details)
     */
    public function resolve(int $pageId, int $languageId, bool $requireBarPermission = true): ?PageTarget
    {
        $backendUser = $this->getBackendUser();
        if ($pageId <= 0 || ($requireBarPermission && !StatisticsPermission::isGranted($backendUser, StatisticsPermission::PAGE))) {
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

        $pageUrl = (string)$site->getRouter()->generateUri($this->pageRecordResolver->resolve($pageId, $language), ['_language' => $language], '', RouterInterface::ABSOLUTE_URL);

        return new PageTarget($pageId, $languageId, $pageUrl, $configuration);
    }

    /**
     * The page in every site language it is translated to and the backend user may access
     *
     * @return array<int, PageTarget> by language ID
     */
    public function resolveTranslations(int $pageId): array
    {
        try {
            $languages = $this->siteFinder->getSiteByPageId($pageId)->getLanguages();
        } catch (SiteNotFoundException) {
            return [];
        }
        $targets = [];
        foreach ($languages as $language) {
            $page = $this->pageRecordResolver->resolve($pageId, $language);
            $isTranslated = $language->getLanguageId() === 0 || (is_array($page) && isset($page['_LOCALIZED_UID']));
            $target = $isTranslated ? $this->resolve($pageId, $language->getLanguageId(), false) : null;
            if ($target !== null) {
                $targets[$language->getLanguageId()] = $target;
            }
        }
        return $targets;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
