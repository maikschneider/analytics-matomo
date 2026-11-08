<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\EventListener;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use MaikSchneider\AnalyticsMatomo\Module\MetricFormatter;
use MaikSchneider\AnalyticsMatomo\Module\TrendingService;
use MaikSchneider\AnalyticsMatomo\Statistics\PageTargetResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\PageViewsService;
use TYPO3\CMS\Backend\Controller\Event\AfterPageTreeItemsPreparedEvent;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\Record;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Page views of the last 30 days next to pages in the page tree and the List module, with a
 * rising or falling arrow. Off by default; users switch it on in the Matomo module.
 *
 * @phpstan-import-type Views from PageViewsService
 */
final class PageViewsListener
{
    public const USER_SETTING = 'pageTreeViews';
    private const LABELS = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:';

    /** @var array<int, array{text: string, title: string}|null> */
    private array $badges = [];
    /** @var array<string, Views|null> */
    private array $siteViews = [];
    private int $remainingFallbacks = 1;

    public function __construct(
        private readonly PageViewsService $pageViewsService,
        private readonly PageTargetResolver $pageTargetResolver,
        private readonly SiteFinder $siteFinder,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
    ) {
    }

    public static function isEnabled(BackendUserAuthentication $user): bool
    {
        return (bool)($user->uc['analytics_matomo'][self::USER_SETTING] ?? false) && StatisticsPermission::isGranted($user, StatisticsPermission::PAGE);
    }

    #[AsEventListener('analytics-matomo/page-tree-views')]
    public function addToPageTree(AfterPageTreeItemsPreparedEvent $event): void
    {
        if (!self::isEnabled($this->getBackendUser())) {
            return;
        }
        $items = $event->getItems();
        foreach ($items as $index => $item) {
            $badge = ($item['recordType'] ?? '') === 'pages' ? $this->badge((int)($item['identifier'] ?? 0), (int)($item['doktype'] ?? 0)) : null;
            if ($badge !== null) {
                $items[$index]['suffix'] = ($item['suffix'] ?? '') . ' · ' . $badge['text'];
                $items[$index]['tooltip'] = trim(($item['tooltip'] ?? '') . "\n" . $badge['title']);
            }
        }
        $event->setItems($items);
    }

    #[AsEventListener('analytics-matomo/record-list-views')]
    public function addToRecordList(ModifyRecordListRecordActionsEvent $event): void
    {
        if (!self::isEnabled($this->getBackendUser())) {
            return;
        }
        // TYPO3 13 passes the row and the table separately and takes the action as HTML
        $record = $this->callUntyped($event, 'getRecord');
        if ($record instanceof RecordInterface) {
            $table = $record->getMainType();
            $row = $record->toArray();
            if ($record instanceof Record) {
                $row['sys_language_uid'] = $record->getLanguageInfo()?->getLanguageId() ?? 0;
            }
        } else {
            $table = (string)$this->callUntyped($event, 'getTable');
            $row = is_array($record) ? $record : [];
        }
        if ($table !== 'pages' || (int)($row['sys_language_uid'] ?? 0) > 0) {
            return;
        }
        $pageId = (int)($row['uid'] ?? 0);
        $badge = $this->badge($pageId, (int)($row['doktype'] ?? 0));
        if ($badge === null) {
            return;
        }
        $url = (string)$this->uriBuilder->buildUriFromRoute('analytics_matomo_page', ['id' => $pageId]);
        $icon = $this->iconFactory->getIcon('actions-eye', IconSize::SMALL);
        if ($record instanceof RecordInterface) {
            $button = GeneralUtility::makeInstance(LinkButton::class)
                ->setHref($url)
                ->setTitle($badge['text'])
                ->setShowLabelText(true)
                ->setIcon($icon)
                ->setAttributes(['title' => $badge['title']]);
            $event->setAction($button, 'analyticsMatomoViews', ActionGroup::primary);
            return;
        }
        $html = sprintf('<a class="btn btn-default" href="%s" title="%s">%s %s</a>', htmlspecialchars($url), htmlspecialchars($badge['title']), $icon->render(), htmlspecialchars($badge['text']));
        $this->callUntyped($event, 'setAction', $html, 'analyticsMatomoViews', 'primary');
    }

    /**
     * Calls a method whose signature differs between TYPO3 13 and 14, as static analysis knows one version only
     */
    private function callUntyped(object $object, string $method, mixed ...$arguments): mixed
    {
        return $object->{$method}(...$arguments);
    }

    /**
     * @return array{text: string, title: string}|null
     */
    private function badge(int $pageId, int $doktype): ?array
    {
        if ($pageId <= 0 || $doktype >= 200 || in_array($doktype, PageTargetResolver::DOKTYPES_WITHOUT_URL, true)) {
            return null;
        }
        if (array_key_exists($pageId, $this->badges)) {
            return $this->badges[$pageId];
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return $this->badges[$pageId] = null;
        }
        if (!array_key_exists($site->getIdentifier(), $this->siteViews)) {
            $this->siteViews[$site->getIdentifier()] = $this->pageViewsService->getViews($site);
        }
        $views = $this->siteViews[$site->getIdentifier()];
        if ($views === null) {
            return $this->badges[$pageId] = null;
        }
        $languageIds = array_values(array_map(static fn (SiteLanguage $language): int => $language->getLanguageId(), $site->getAvailableLanguages($this->getBackendUser())));
        ['current' => $current, 'previous' => $previous] = PageViewsService::ofPage($views, $pageId, $languageIds);
        if (($current === null || $previous === null) && $this->remainingFallbacks > 0) {
            $this->remainingFallbacks--;
            $fallback = $this->pageViewsService->getFallback($this->pageTargetResolver->resolveTranslations($pageId));
            if ($fallback !== null) {
                ['current' => $current, 'previous' => $previous] = $fallback;
            }
        }
        $trend = $current !== null && $previous !== null ? TrendingService::trend($previous, $current, $views['total']) : null;
        $formatter = new MetricFormatter();
        return $this->badges[$pageId] = [
            'text' => $formatter->format($current, 'number') . match ($trend) {
                'up' => ' ↑',
                'down' => ' ↓',
                default => '',
            },
            'title' => sprintf($this->getLanguageService()->sL(self::LABELS . 'pageViews.title'), $formatter->format($current, 'number'), PageViewsService::DAYS, $formatter->format($previous, 'number')),
        ];
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
