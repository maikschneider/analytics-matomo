<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Controller;

use MaikSchneider\AnalyticsMatomo\Annotation\Annotation;
use MaikSchneider\AnalyticsMatomo\Annotation\AnnotationService;
use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelection;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use MaikSchneider\AnalyticsMatomo\EventListener\PageViewsListener;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Module\AllSitesService;
use MaikSchneider\AnalyticsMatomo\Module\ContentReviewService;
use MaikSchneider\AnalyticsMatomo\Module\CsvExport;
use MaikSchneider\AnalyticsMatomo\Module\EvolutionChartFactory;
use MaikSchneider\AnalyticsMatomo\Module\GoalsService;
use MaikSchneider\AnalyticsMatomo\Module\MetricFormatter;
use MaikSchneider\AnalyticsMatomo\Module\PageDetailsService;
use MaikSchneider\AnalyticsMatomo\Module\ReadingTimeEstimator;
use MaikSchneider\AnalyticsMatomo\Module\ReportCatalog;
use MaikSchneider\AnalyticsMatomo\Module\ReportService;
use MaikSchneider\AnalyticsMatomo\Module\ReportTableFactory;
use MaikSchneider\AnalyticsMatomo\Module\SegmentService;
use MaikSchneider\AnalyticsMatomo\Module\TransitionsPanelFactory;
use MaikSchneider\AnalyticsMatomo\Module\TransitionsService;
use MaikSchneider\AnalyticsMatomo\Module\TrendingService;
use MaikSchneider\AnalyticsMatomo\Module\VisitorsOverviewService;
use MaikSchneider\AnalyticsMatomo\Monitoring\AnomalyBadgeFactory;
use MaikSchneider\AnalyticsMatomo\Statistics\LinkAccess;
use MaikSchneider\AnalyticsMatomo\Statistics\PageTarget;
use MaikSchneider\AnalyticsMatomo\Statistics\PageTargetResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\TileFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownRadio;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownToggle;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @phpstan-import-type Definition from ReportCatalog
 * @phpstan-import-type Report from ReportTableFactory
 */
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
        private VisitorsOverviewService $overviewService,
        private TileFactory $tileFactory,
        private LoggerInterface $logger,
        private ReportService $reportService,
        private ReportTableFactory $tableFactory,
        private PageRenderer $pageRenderer,
        private PageTargetResolver $pageTargetResolver,
        private PageDetailsService $pageDetailsService,
        private ReadingTimeEstimator $readingTimeEstimator,
        private TransitionsService $transitionsService,
        private TransitionsPanelFactory $transitionsPanelFactory,
        private GoalsService $goalsService,
        private TrendingService $trendingService,
        private LinkAccess $linkAccess,
        private CsvExport $csvExport,
        private SegmentService $segmentService,
        private AllSitesService $allSitesService,
        private ContentReviewService $contentReviewService,
        private IconFactory $iconFactory,
        private AnomalyBadgeFactory $anomalyBadgeFactory,
        private AnnotationService $annotationService,
        private EvolutionChartFactory $chartFactory,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        /** @var BackendUserAuthentication $user */
        $user = $GLOBALS['BE_USER'];
        /** @var ModuleInterface $module */
        $module = $request->getAttribute('module');
        if (!$user->check('modules', 'analytics_matomo') || !$user->check('modules', $module->getIdentifier())) {
            return new HtmlResponse('', 403);
        }
        /** @var ModuleData $moduleData */
        $moduleData = $request->getAttribute('moduleData');
        $parameters = array_replace($request->getQueryParams(), (array)$request->getParsedBody());
        $pageId = max(0, (int)($parameters['id'] ?? 0));
        $route = $module->getIdentifier();
        $section = substr($route, strlen('analytics_matomo_'));
        $pages = $this->catalog->getPages($section, $user);
        if ($pages !== []) {
            $moduleData->clean('report', array_keys($pages));
        }
        $report = (string)$moduleData->get('report');
        $template = $this->moduleTemplateFactory->create($request);
        $template->setTitle($this->label('mlang_tabs_tab'));
        $template->makeDocHeaderModuleMenu(['id' => $pageId]);
        $variables = [
            'title' => $this->translate($pages[$report] ?? self::LABELS . 'section.' . $section),
            'll' => self::LABELS,
            'pageId' => $pageId,
            'refreshPageTree' => $this->settingsMenu($template, $route, $pageId, $parameters, $user),
        ];

        if ($pageId === 0) {
            $template->assignMultiple($variables + ['emptyMessage' => $this->label('empty.page')]);
            return $template->renderResponse('Module/Report');
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            $template->assignMultiple($variables + ['emptyMessage' => $this->label('empty.page')]);
            return $template->renderResponse('Module/Report');
        }
        $saved = $this->selectionResolver->save(
            $user,
            $site->getIdentifier(),
            isset($parameters['period']) ? (string)$parameters['period'] : null,
            (string)($parameters['from'] ?? ''),
            (string)($parameters['to'] ?? ''),
            isset($parameters['compare']) ? (bool)$parameters['compare'] : null,
            $route,
        );
        if (!$saved) {
            $template->assignMultiple($variables + ['emptyMessage' => $this->label('empty.site')]);
            return $template->renderResponse('Module/Report');
        }
        $selection = $this->selectionResolver->resolve($user, $site, $route);
        if ($selection === null) {
            $template->assignMultiple($variables + ['emptyMessage' => $this->label('empty.site')]);
            return $template->renderResponse('Module/Report');
        }
        try {
            $available = $this->reportService->filterAvailablePages($selection->configuration, $section, $pages);
        } catch (MatomoApiException $exception) {
            // The report request shows the error; until then, offer only what every Matomo has
            $this->logger->warning('Checking the Matomo reports failed: {message}', ['message' => $exception->getMessage(), 'site' => $selection->site->getIdentifier()]);
            $available = array_diff_key($pages, $this->catalog->getOptionalPages($section));
        }
        if (count($selection->sites) < 2) {
            unset($available['sites']);
        }
        if ($available !== $pages) {
            $pages = $available;
            $moduleData->clean('report', array_keys($pages));
            $report = (string)$moduleData->get('report');
            $variables['title'] = $this->translate($pages[$report] ?? self::LABELS . 'section.' . $section);
        }
        $goals = null;
        if ($section === 'goals') {
            try {
                $goals = $this->goalsService->getGoals($selection->configuration);
            } catch (MatomoApiException $exception) {
                // The goal page request shows the error
                $this->logger->warning('Loading the Matomo goals failed: {message}', ['message' => $exception->getMessage(), 'site' => $selection->site->getIdentifier()]);
            }
            $pages = ['overview' => self::LABELS . 'report.overview'];
            foreach ($goals ?? [] as $id => $name) {
                $pages['goal' . $id] = $name;
            }
            $moduleData->clean('report', array_keys($pages));
            $report = (string)$moduleData->get('report');
            $variables['title'] = $report === 'overview' ? $this->translate($pages[$report]) : $pages[$report];
        }

        $this->addMenu($template, $route, $pageId, 'report', $pages, $report);
        $languages = $section === 'page' ? [] : [-1 => self::LABELS . 'language.all'];
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
        $segment = $report === 'sites' ? null : $this->segment($template, $route, $pageId, $selection, $parameters, $user);
        if ($segment !== null) {
            $selection = new DashboardSelection($selection->site, $selection->configuration->withSegment($segment['definition']), $selection->period, $selection->sites, $selection->compare);
            $variables['segment'] = $segment['name'];
        }
        try {
            if ($section === 'page') {
                $variables += $this->pageDetails($pageId, (int)$moduleData->get('language'), $selection, (bool)($parameters['transitions'] ?? false), $user);
            } elseif ($section === 'visitors' && $report === 'overview') {
                $overview = $this->overviewService->getOverview($selection->configuration, $selection->period, $selection->compare);
                $variables += $this->anomalyBadgeFactory->create($selection->configuration, $user);
                $variables['tiles'] = $this->tileFactory->createForOverview($overview['current'], $overview['previous']);
                $variables['chart'] = $this->chartFactory->withAnnotations($overview['chart'], $this->annotationService->get($selection->site, $selection->configuration, $selection->period));
                $variables['counters'] = $this->overviewService->getCounters($selection->configuration);
                $this->pageRenderer->addCssFile('EXT:analytics_matomo/Resources/Public/Css/statistics-bar.css');
                $this->pageRenderer->addCssFile('EXT:analytics_matomo/Resources/Public/Css/dashboard.css');
                $this->pageRenderer->loadJavaScriptModule('@maikschneider/analytics-matomo/evolution-chart.js');
            } elseif ($section === 'visitors' && $report === 'sites') {
                $variables['tables'] = $this->tables([$this->allSites($selection)], $selection, $parameters, $route, $pageId, $report, -1);
            } elseif ($section === 'goals') {
                $variables += $this->goals($goals ?? $this->goalsService->getGoals($selection->configuration), $report, $selection, $parameters, $route, $pageId, (int)$moduleData->get('language'));
            } elseif ($section === 'behaviour' && $report === 'trending') {
                $trending = $this->trendingService->getTrending($selection->configuration, $selection->period);
                $reports = [];
                foreach (['rising' => 'report.risingPages', 'falling' => 'report.fallingPages'] as $direction => $title) {
                    $definition = ['module' => 'Actions', 'action' => 'getPageUrls', 'rows' => 'url', 'title' => $title, 'empty' => 'empty.trending', 'columns' => TrendingService::COLUMNS];
                    $reports[] = ['definition' => $definition, 'current' => ReportTableFactory::fromRows($trending[$direction]), 'previous' => null];
                }
                $variables['tables'] = $this->tables($reports, $selection, $parameters, $route, $pageId, $report, (int)$moduleData->get('language'));
            } elseif ($section === 'behaviour' && $report === 'review') {
                $variables += $this->contentReview($selection, $parameters, $route, $pageId, $report, (int)$moduleData->get('language'));
            } else {
                $definitions = $this->catalog->getReports($section, $report);
                $reports = [];
                foreach ($this->reportService->getReports($selection->configuration, $section, $report, $selection->period, $selection->compare, $GLOBALS['LANG']->getLocale()?->getLanguageCode() ?? 'en') as $index => $data) {
                    $reports[] = ['definition' => $definitions[$index]] + $data;
                }
                $variables['tables'] = $this->tables($reports, $selection, $parameters, $route, $pageId, $report, (int)$moduleData->get('language'));
                if ($reports === []) {
                    $variables['emptyMessage'] = $this->label($section === 'behaviour' && $report === 'siteSearch' ? 'empty.siteSearch' : 'report.placeholder');
                }
            }
        } catch (MatomoApiException $exception) {
            $this->logger->warning('Loading Matomo statistics failed: {message}', [
                'message' => $exception->getMessage(),
                'site' => $selection->site->getIdentifier(),
            ]);
            $variables['emptyMessage'] = $this->translate(self::SHARED_LABELS . 'bar.error');
            $variables['errorMessage'] = $user->isAdmin() ? $exception->getMessage() : '';
        }
        $this->pageRenderer->loadJavaScriptModule('@maikschneider/analytics-matomo/module-filters.js');
        if (isset($variables['segment'])) {
            // Matomo answers empty reports for a segment it has not archived for the period yet
            $segmentEmpty = sprintf($this->label('empty.segment'), $variables['segment']);
            foreach ($variables['tables'] ?? [] as $index => $table) {
                if (($table['rows'] ?? []) === [] && ($table['query'] ?? '') === '') {
                    $variables['tables'][$index]['emptyMessage'] = $segmentEmpty;
                }
            }
        }
        foreach ($variables['tables'] ?? [] as $index => $table) {
            if (isset($table['csv'])) {
                return $this->csvResponse($table['csv'], $selection, $section, $report, $index);
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
     * @param array<int, string> $goals
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     * @throws MatomoApiException
     */
    private function goals(array $goals, string $report, DashboardSelection $selection, array $parameters, string $route, int $pageId, int $languageId): array
    {
        if ($goals === []) {
            return ['emptyMessage' => $this->label('empty.goals')];
        }
        $page = $this->goalsService->getPage($selection->configuration, $goals, $report === 'overview' ? null : (int)substr($report, 4), $selection->period, $selection->compare);
        $metrics = GoalsService::METRICS;
        $reports = $page['reports'];
        // Most goals have no revenue; hide the column instead of a row of zeros
        if ((float)($page['current']['revenue'] ?? 0) === 0.0) {
            unset($metrics['revenue']);
            foreach ($reports as $index => $data) {
                $reports[$index]['definition']['columns'] = array_diff_key($data['definition']['columns'] ?? [], ['revenue' => true, 'revenue_entry' => true]);
            }
        }
        $chart = $page['chart'];
        $chart['metrics'] = array_intersect_key($chart['metrics'], $metrics);
        $this->pageRenderer->addCssFile('EXT:analytics_matomo/Resources/Public/Css/statistics-bar.css');
        $this->pageRenderer->addCssFile('EXT:analytics_matomo/Resources/Public/Css/dashboard.css');
        $this->pageRenderer->loadJavaScriptModule('@maikschneider/analytics-matomo/evolution-chart.js');
        return [
            'tiles' => $this->tileFactory->createForOverview($page['current'], $page['previous'], $metrics),
            'chart' => $chart,
            'tables' => $this->tables($reports, $selection, $parameters, $route, $pageId, $report, $languageId),
        ];
    }

    /**
     * @param list<array{definition: Definition, current: Report, previous: Report|null}> $reports
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function tables(array $reports, DashboardSelection $selection, array $parameters, string $route, int $pageId, string $report, int $languageId): array
    {
        $tables = [];
        $baseUrl = (string)$this->uriBuilder->buildUriFromRoute($route, ['id' => $pageId, 'report' => $report]);
        $export = isset($parameters['export']) && is_numeric($parameters['export']) ? (int)$parameters['export'] : null;
        foreach ($reports as $index => ['definition' => $definition, 'current' => $current, 'previous' => $previous]) {
            $options = array_intersect_key($definition, ['minimum' => true, 'sort' => true, 'truncated' => true, 'flag' => true]) + ['index' => $index];
            if ($index === $export) {
                $options['export'] = ['url' => $this->label('table.url'), 'metric' => $this->label('table.metric'), 'value' => $this->label('table.value')];
            }
            foreach (['title', 'empty'] as $option) {
                if (isset($definition[$option])) {
                    $options[$option] = $this->label($definition[$option]);
                }
            }
            foreach ($definition['columns'] ?? [] as $key => $column) {
                $options['columns'][$key] = [$this->label($column[0]), $column[1]];
                // Reports built from raw answers carry no documentation of their own
                if (isset($column[2])) {
                    $current['metadata']['metricsDocumentation'][$key] = $this->label($column[2]);
                }
            }
            if (($definition['rows'] ?? '') === 'link') {
                $current = $this->linkAccess->filterReport($selection->site, $current, $GLOBALS['BE_USER']);
            }
            $tables[] = $this->tableFactory->create($current, $previous, $parameters, $baseUrl, ($definition['rows'] ?? '') === 'url' ? $selection->site : null, $languageId, $pageId, $options);
        }
        return $tables;
    }

    /**
     * Pages without views and old pages with views of the selected subtree, over a fixed period
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     * @throws MatomoApiException
     */
    private function contentReview(DashboardSelection $selection, array $parameters, string $route, int $pageId, string $report, int $languageId): array
    {
        $review = $this->contentReviewService->getReview($selection->site, $selection->configuration, $pageId);
        $reports = [];
        foreach (['withoutViews' => ContentReviewService::COLUMNS_WITHOUT_VIEWS, 'old' => ContentReviewService::COLUMNS_OLD] as $list => $columns) {
            $definition = [
                'module' => 'Actions', 'action' => 'getPageUrls', 'rows' => 'url', 'columns' => $columns, 'truncated' => $review['truncated'],
                'title' => 'report.review.' . $list, 'empty' => 'empty.review.' . $list,
            ];
            if ($list === 'old') {
                $definition['sort'] = 'priority';
            }
            $reports[] = ['definition' => $definition, 'current' => ReportTableFactory::fromRows($review[$list]), 'previous' => null];
        }
        $tables = $this->tables($reports, $selection, $parameters, $route, $pageId, $report, $languageId);
        $period = $review['period'];
        if ($review['trackedSince'] === null || $review['trackedSince'] > $period->from) {
            $tables[0]['emptyMessage'] = sprintf($this->label('empty.review.young'), $review['trackedSince']?->format('Y-m-d') ?? '–', ContentReviewService::DAYS);
        }
        return [
            'tables' => $tables,
            'reportHint' => sprintf($this->label('review.hint'), $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), ContentReviewService::OLD_DAYS),
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws MatomoApiException
     */
    private function pageDetails(int $pageId, int $languageId, DashboardSelection $selection, bool $withTransitions, BackendUserAuthentication $user): array
    {
        if ($pageId === 0) {
            return ['emptyMessage' => $this->label('empty.page')];
        }
        $target = $this->pageTargetResolver->resolve($pageId, $languageId, false);
        if ($target === null) {
            return ['emptyMessage' => $this->label('empty.pageWithoutUrl')];
        }
        $translations = $this->pageTargetResolver->resolveTranslations($pageId);
        unset($translations[$languageId]);
        $languageUrls = array_map(static fn (PageTarget $translation): string => $translation->pageUrl, $translations);
        $details = $this->pageDetailsService->getDetails($target->configuration->withSegment($selection->configuration->segment), $target->pageUrl, $selection->period, $selection->compare, $languageUrls);
        $this->pageRenderer->addCssFile('EXT:analytics_matomo/Resources/Public/Css/statistics-bar.css');
        $this->pageRenderer->addCssFile('EXT:analytics_matomo/Resources/Public/Css/dashboard.css');
        $this->pageRenderer->loadJavaScriptModule('@maikschneider/analytics-matomo/evolution-chart.js');
        return [
            'pageDetails' => [
                'url' => $target->pageUrl,
                'speed' => $this->speed($details['pageRow']),
                'reading' => $this->reading($target, $details['current'], (int)$selection->site->getSettings()->get('analyticsMatomo.wordsPerMinute', 220)),
                'languages' => $this->languages($selection, $pageId, $languageId, [$languageId => $details['current']] + $details['languages']),
                'transitionsUrl' => (string)$this->uriBuilder->buildUriFromRoute('analytics_matomo_page', ['id' => $pageId, 'language' => $languageId, 'transitions' => 1]),
                'transitions' => $withTransitions ? $this->transitions($selection, $target, $user) : null,
                'goals' => $this->pageGoals($details['goals'], $details['pageRow']),
                'trend' => $details['trend'] === null ? null : $details['trend'] + ['change' => (new MetricFormatter())->format($details['trend']['difference'], 'difference')],
            ],
            'minimumSamples' => ReportCatalog::MINIMUM_TIMING_SAMPLES,
            'tiles' => $this->tileFactory->createForOverview($details['current'], $details['previous'], EvolutionChartFactory::PAGE_METRICS),
            'chart' => $this->chartFactory->withAnnotations($details['chart'], Annotation::forPage($this->annotationService->get($selection->site, $target->configuration, $selection->period), $target->pageUrl)),
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws MatomoApiException
     */
    private function transitions(DashboardSelection $selection, PageTarget $target, BackendUserAuthentication $user): array
    {
        $transitions = $this->transitionsService->getTransitions($target->configuration->withSegment($selection->configuration->segment), $target->pageUrl, $selection->period);
        return $transitions === null ? ['empty' => true] : $this->transitionsPanelFactory->create($selection->site, $transitions, $user);
    }

    /**
     * Conversions of every goal after a view of the page; Matomo only lists goals converted after the page
     *
     * @param array<int, string> $goals
     * @param array<string, mixed> $pageRow
     * @return list<array{name: string, viewedBefore: string, attributed: string, entrances: string}>
     */
    private function pageGoals(array $goals, array $pageRow): array
    {
        $formatter = new MetricFormatter();
        $rows = [];
        foreach ($goals as $id => $name) {
            $conversions = $pageRow['goals']['idgoal=' . $id] ?? [];
            $rows[] = [
                'name' => $name,
                'viewedBefore' => $formatter->format($conversions['nb_conversions_page_uniq'] ?? 0, 'number'),
                'attributed' => $formatter->format($conversions['nb_conversions_attrib'] ?? 0, 'number'),
                'entrances' => $formatter->format($conversions['nb_conversions_entry'] ?? 0, 'number'),
            ];
        }
        return $rows;
    }

    /**
     * One row per language of the page, in the order of the site languages; none for a page in one language
     *
     * @param array<int, array<string, mixed>> $rows Matomo rows by language ID
     * @return list<array<string, mixed>>
     */
    private function languages(DashboardSelection $selection, int $pageId, int $languageId, array $rows): array
    {
        if (count($rows) < 2) {
            return [];
        }
        $formatter = new MetricFormatter();
        $languages = [];
        foreach ($selection->site->getLanguages() as $language) {
            $row = $rows[$language->getLanguageId()] ?? null;
            if ($row === null) {
                continue;
            }
            $languages[] = [
                'title' => $language->getTitle(),
                'flag' => $language->getFlagIdentifier(),
                'views' => $formatter->format($row['nb_hits'] ?? null, 'number'),
                'bounceRate' => $formatter->format($row['bounce_rate'] ?? null, 'percent'),
                'averageTimeOnPage' => $formatter->format($row['avg_time_on_page'] ?? null, 'duration_s'),
                'url' => (string)$this->uriBuilder->buildUriFromRoute('analytics_matomo_page', ['id' => $pageId, 'language' => $language->getLanguageId()]),
                'current' => $language->getLanguageId() === $languageId,
            ];
        }
        return $languages;
    }

    /**
     * @param array<string, mixed> $current
     * @return array{words: int, readingTime: string, timeOnPage: string, ratio: string|null}|null
     */
    private function reading(PageTarget $target, array $current, int $wordsPerMinute): ?array
    {
        $estimate = $this->readingTimeEstimator->estimate($target->pageId, $target->languageId, $wordsPerMinute);
        if ($estimate === null) {
            return null;
        }
        $formatter = new MetricFormatter();
        $timeOnPage = $current['avg_time_on_page'] ?? null;
        return [
            'words' => $estimate['words'],
            'readingTime' => $formatter->format($estimate['seconds'], 'duration_s'),
            'timeOnPage' => $formatter->format($timeOnPage, 'duration_s'),
            'ratio' => is_numeric($timeOnPage) && $estimate['seconds'] > 0 ? $formatter->format($timeOnPage / $estimate['seconds'], 'percent') : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, array{samples: int, value: string|null}>
     */
    private function speed(array $row): array
    {
        $speed = [];
        foreach (['pageLoadTime' => ['avg_page_load_time', 'nb_hits_with_time_network'], 'serverTime' => ['avg_time_server', 'nb_hits_with_time_server']] as $key => [$column, $samplesColumn]) {
            $samples = (int)($row[$samplesColumn] ?? 0);
            $speed[$key] = [
                'samples' => $samples,
                'value' => $samples >= ReportCatalog::MINIMUM_TIMING_SAMPLES && is_numeric($row[$column] ?? null) ? (new MetricFormatter())->format($row[$column], 'duration_s') : null,
            ];
        }
        return $speed;
    }

    /**
     * Segment dropdown with the stored segments of the site; no dropdown without any
     *
     * @param array<string, mixed> $parameters
     * @return array{name: string, definition: string}|null the selected segment
     */
    private function segment(ModuleTemplate $template, string $route, int $pageId, DashboardSelection $selection, array $parameters, BackendUserAuthentication $user): ?array
    {
        try {
            $segments = $this->segmentService->getSegments($selection->configuration);
        } catch (MatomoApiException $exception) {
            // Reports still work for all visits; their own request shows a broken connection
            $this->logger->warning('Loading the Matomo segments failed: {message}', ['message' => $exception->getMessage(), 'site' => $selection->site->getIdentifier()]);
            return null;
        }
        if ($segments === []) {
            return null;
        }
        $selected = $this->segmentService->select($user, $selection->site->getIdentifier(), $segments, isset($parameters['segment']) ? (string)$parameters['segment'] : null);
        $this->addMenu($template, $route, $pageId, 'segment', ['' => self::LABELS . 'segment.all'] + array_map(static fn (array $segment): string => $segment['name'], $segments), $selected);
        return $segments[$selected] ?? null;
    }

    /**
     * Visit totals of every site the user may choose, as a report; a row opens the site
     *
     * @return array{definition: Definition, current: Report, previous: Report|null}
     */
    private function allSites(DashboardSelection $selection): array
    {
        $totals = $this->allSitesService->getTotals($selection->sites, $selection->period, $selection->compare);
        $current = [];
        $previous = [];
        foreach ($selection->sites as $identifier => $site) {
            $rootPageId = $site->getRootPageId();
            $row = ['label' => $this->allSitesService->title($site), 'url' => (string)$site->getBase()];
            $current[] = $row + array_intersect_key($totals[$identifier]['current'] ?? [], array_flip(AllSitesService::COLUMNS)) + [
                '_actions' => [['key' => 'site', 'url' => (string)$this->uriBuilder->buildUriFromRoute('analytics_matomo_visitors', ['id' => $rootPageId, 'report' => 'overview'])]],
            ];
            $previous[] = $row + ($totals[$identifier]['previous'] ?? []);
        }
        $columns = ['label' => ['column.site', 'string']];
        foreach (AllSitesService::COLUMNS as $column) {
            $columns[$column] = ['column.' . $column, $column === 'bounce_rate' ? 'percent' : ($column === 'avg_time_on_site' ? 'duration_s' : 'number')];
        }
        return [
            'definition' => ['module' => 'API', 'action' => 'get', 'title' => 'report.sites', 'columns' => $columns],
            'current' => ReportTableFactory::fromRows($current),
            'previous' => $selection->compare ? ReportTableFactory::fromRows($previous) : null,
        ];
    }

    /**
     * @param array{header: list<string>, rows: list<list<mixed>>} $csv
     */
    private function csvResponse(array $csv, DashboardSelection $selection, string $section, string $report, int $index): ResponseInterface
    {
        $name = implode('-', ['matomo', $selection->site->getIdentifier(), $section, $report, $selection->period->from->format('Y-m-d'), $selection->period->to->format('Y-m-d')]) . ($index > 0 ? '-' . ($index + 1) : '');
        $response = new Response();
        $response->getBody()->write($this->csvExport->render($csv['header'], $csv['rows']));
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Personal settings of the user; saving one returns true, as the page tree then has to reload
     *
     * @param array<string, mixed> $parameters
     */
    private function settingsMenu(ModuleTemplate $template, string $route, int $pageId, array $parameters, BackendUserAuthentication $user): bool
    {
        if (!StatisticsPermission::isGranted($user, StatisticsPermission::PAGE)) {
            return false;
        }
        $changed = isset($parameters['pageTreeViews']);
        if ($changed) {
            $user->uc['analytics_matomo'][PageViewsListener::USER_SETTING] = (bool)$parameters['pageTreeViews'];
            $user->writeUC();
            $this->pageRenderer->loadJavaScriptModule('@maikschneider/analytics-matomo/module-filters.js');
        }
        $enabled = PageViewsListener::isEnabled($user);
        $button = GeneralUtility::makeInstance(DropDownButton::class)
            ->setLabel($this->label('control.settings'))
            ->setIcon($this->iconFactory->getIcon('actions-cog', IconSize::SMALL))
            ->addItem(GeneralUtility::makeInstance(DropDownToggle::class)
                ->setLabel($this->label('settings.pageTreeViews'))
                ->setActive($enabled)
                ->setHref((string)$this->uriBuilder->buildUriFromRoute($route, ['id' => $pageId, 'pageTreeViews' => $enabled ? 0 : 1])));
        $template->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_RIGHT);
        return $changed;
    }

    /**
     * @param array<int|string, string> $options
     */
    private function addMenu(ModuleTemplate $template, string $route, int $pageId, string $parameter, array $options, string $active): void
    {
        if ($options === []) {
            return;
        }
        $activeLabel = $options[$active] ?? '';
        $button = GeneralUtility::makeInstance(DropDownButton::class)
            ->setLabel($this->label('control.' . $parameter) . ': ' . (str_starts_with($activeLabel, 'LLL:') ? $this->translate($activeLabel) : $activeLabel))
            ->setShowLabelText(true);
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
