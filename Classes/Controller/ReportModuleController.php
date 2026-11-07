<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Controller;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use MaikSchneider\AnalyticsMatomo\Dashboard\SiteStatisticsService;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Module\ReportCatalog;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\TileFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownRadio;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final readonly class ReportModuleController
{
    private const LABELS = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang_mod.xlf:';
    private const SHARED_LABELS = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private DashboardSelectionResolver $selectionResolver,
        private SiteFinder $siteFinder,
        private ReportCatalog $catalog,
        private UriBuilder $uriBuilder,
        private SiteStatisticsService $statisticsService,
        private TileFactory $tileFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        /** @var BackendUserAuthentication $user */
        $user = $GLOBALS['BE_USER'];
        /** @var ModuleInterface $module */
        $module = $request->getAttribute('module');
        /** @var ModuleData $moduleData */
        $moduleData = $request->getAttribute('moduleData');
        $parameters = array_replace($request->getQueryParams(), (array)$request->getParsedBody());
        $pageId = max(0, (int)($parameters['id'] ?? 0));
        $route = $module->getIdentifier();
        $section = substr($route, strlen('analytics_matomo_'));
        $pages = $this->catalog->getPages($section);
        $moduleData->clean('report', array_keys($pages));
        $report = (string)$moduleData->get('report');
        $template = $this->moduleTemplateFactory->create($request);
        $template->setTitle($this->label('mlang_tabs_tab'));
        $template->makeDocHeaderModuleMenu(['id' => $pageId]);
        $variables = [
            'title' => $this->translate($pages[$report]),
            'll' => self::LABELS,
            'pageId' => $pageId,
        ];
        if (!StatisticsPermission::isGranted($user, StatisticsPermission::SITE)) {
            $template->assignMultiple($variables + ['emptyMessage' => $this->translate(self::SHARED_LABELS . 'widget.noAccess')]);
            return $template->renderResponse('Module/Report');
        }

        $site = null;
        if ($pageId > 0) {
            try {
                $site = $this->siteFinder->getSiteByPageId($pageId);
            } catch (SiteNotFoundException) {
                $template->assignMultiple($variables + ['emptyMessage' => $this->label('empty.site')]);
                return $template->renderResponse('Module/Report');
            }
        }
        if ($site !== null || isset($parameters['site']) || isset($parameters['period']) || isset($parameters['compare'])) {
            $saved = $this->selectionResolver->save(
                $user,
                $site?->getIdentifier() ?? (isset($parameters['site']) ? (string)$parameters['site'] : null),
                isset($parameters['period']) ? (string)$parameters['period'] : null,
                (string)($parameters['from'] ?? ''),
                (string)($parameters['to'] ?? ''),
                isset($parameters['compare']) ? (bool)$parameters['compare'] : null,
            );
            if (!$saved) {
                $template->assignMultiple($variables + ['emptyMessage' => $this->label('empty.site')]);
                return $template->renderResponse('Module/Report');
            }
        }
        $selection = $this->selectionResolver->resolve($user, $site);
        if ($selection === null) {
            $template->assignMultiple($variables + ['emptyMessage' => $this->label('empty.site')]);
            return $template->renderResponse('Module/Report');
        }

        $this->addMenu($template, $route, $pageId, 'report', $pages, $report);
        if ($pageId === 0) {
            $sites = [];
            foreach ($selection->sites as $identifier => $availableSite) {
                $sites[$identifier] = $availableSite->getConfiguration()['websiteTitle'] ?? $identifier;
            }
            $this->addMenu($template, $route, $pageId, 'site', $sites, $selection->site->getIdentifier());
        }
        $languages = [-1 => self::LABELS . 'language.all'];
        foreach ($selection->site->getAvailableLanguages($user) as $language) {
            $languages[$language->getLanguageId()] = $language->getTitle();
        }
        $moduleData->set('language', (int)$moduleData->get('language'));
        $moduleData->clean('language', array_keys($languages));
        $user->pushModuleData($route, $moduleData->toArray());
        $this->addMenu($template, $route, $pageId, 'language', $languages, (string)$moduleData->get('language'));
        $periods = [];
        foreach (Period::PRESETS as $preset) {
            $periods[$preset] = self::SHARED_LABELS . 'period.' . $preset;
        }
        $periods[Period::CUSTOM] = self::SHARED_LABELS . 'period.custom';
        $this->addMenu($template, $route, $pageId, 'period', $periods, $selection->period->preset);
        $this->addMenu($template, $route, $pageId, 'compare', ['0' => self::LABELS . 'compare.off', '1' => self::LABELS . 'compare.on'], $selection->compare ? '1' : '0');
        if ($section === 'visitors' && $report === 'overview') {
            try {
                $variables['tiles'] = $this->tileFactory->createForSite($this->statisticsService->getPerformance($selection->configuration, $selection->period));
            } catch (MatomoApiException $exception) {
                $this->logger->warning('Loading Matomo statistics failed: {message}', [
                    'message' => $exception->getMessage(),
                    'site' => $selection->site->getIdentifier(),
                ]);
                $variables['emptyMessage'] = $this->translate(self::SHARED_LABELS . 'bar.error');
                $variables['errorMessage'] = $user->isAdmin() ? $exception->getMessage() : '';
            }
        }
        $template->assignMultiple($variables + [
            'sharedLabels' => rtrim(self::SHARED_LABELS, ':'),
            'selection' => $selection,
            'language' => $moduleData->get('language'),
            'formAction' => (string)$this->uriBuilder->buildUriFromRoute($route, ['id' => $pageId]),
        ]);
        return $template->renderResponse('Module/Report');
    }

    /**
     * @param array<int|string, string> $options
     */
    private function addMenu(ModuleTemplate $template, string $route, int $pageId, string $parameter, array $options, string $active): void
    {
        $button = GeneralUtility::makeInstance(DropDownButton::class)
            ->setLabel($this->label('control.' . $parameter))
            ->setShowLabelText(true)
            ->setShowActiveLabelText(true);
        foreach ($options as $value => $label) {
            $button->addItem(GeneralUtility::makeInstance(DropDownRadio::class)
                ->setLabel(str_starts_with($label, 'LLL:') ? $this->translate($label) : $label)
                ->setActive((string)$value === $active)
                ->setHref($parameter === 'period' && $value === Period::CUSTOM
                    ? '#matomo-custom-range'
                    : (string)$this->uriBuilder->buildUriFromRoute($route, ['id' => $pageId, $parameter => $value])));
        }
        $template->getDocHeaderComponent()->getButtonBar()->addButton($button);
    }

    private function label(string $key): string
    {
        return $this->translate(self::LABELS . $key);
    }

    private function translate(string $reference): string
    {
        /** @var LanguageService $language */
        $language = $GLOBALS['LANG'];
        return $language->sL($reference);
    }
}
