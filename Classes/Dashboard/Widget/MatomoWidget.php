<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard\Widget;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelection;
use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use MaikSchneider\AnalyticsMatomo\Dashboard\SiteStatisticsService;
use MaikSchneider\AnalyticsMatomo\Dashboard\Traffic;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Statistics\PageUrlMapper;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\TileFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\EventDataInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * One widget class for all Matomo reports; the option "report" selects which.
 * All widgets of a user show the same site and period (see DashboardSelectionResolver).
 */
final class MatomoWidget implements WidgetInterface, RequestAwareWidgetInterface, AdditionalCssInterface, JavaScriptInterface, EventDataInterface
{
    public const REPORT_PERFORMANCE = 'performance';
    public const REPORT_TRAFFIC = 'traffic';
    public const REPORT_TOP_PAGES = 'topPages';
    /** Module section and report page with the details of each widget */
    private const DETAILS = [
        self::REPORT_PERFORMANCE => ['analytics_matomo_visitors', 'overview'],
        self::REPORT_TRAFFIC => ['analytics_matomo_visitors', 'overview'],
        self::REPORT_TOP_PAGES => ['analytics_matomo_behaviour', 'pages'],
    ];
    private const TOP_PAGES = 10;
    private const CHART_COLORS = ['#ff8700', '#0078e6', '#79a548', '#a855f7'];

    private ?ServerRequestInterface $request = null;

    /**
     * @var array<string, mixed>
     */
    private array $eventData = [];

    /**
     * @param array{report?: string, refreshAvailable?: bool} $options
     */
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly DashboardSelectionResolver $selectionResolver,
        private readonly SiteStatisticsService $statisticsService,
        private readonly TileFactory $tileFactory,
        private readonly PageUrlMapper $pageUrlMapper,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly LoggerInterface $logger,
        private readonly array $options = [],
    ) {
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $this->eventData = [];
        $report = $this->options['report'] ?? '';
        $backendUser = $this->getBackendUser();
        $selection = $this->selectionResolver->resolve($backendUser);
        $variables = [
            'll' => 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf',
            'configuration' => $this->configuration,
            'report' => $report,
            'selection' => $selection,
            'periods' => Period::PRESETS,
            'hasPermission' => StatisticsPermission::isGranted($backendUser, StatisticsPermission::SITE),
        ];
        if ($selection !== null) {
            $variables['reportUrl'] = $selection->configuration->getReportUrl($selection->period);
            [$module, $page] = self::DETAILS[$report] ?? ['', ''];
            if ($module !== '' && $backendUser->check('modules', 'analytics_matomo') && $backendUser->check('modules', $module)) {
                $variables['detailsUrl'] = (string)$this->uriBuilder->buildUriFromRoute($module, ['report' => $page]);
            }
            try {
                $variables += $this->loadReport($report, $selection);
            } catch (MatomoApiException $e) {
                $this->logger->warning('Loading Matomo statistics failed: {message}', [
                    'message' => $e->getMessage(),
                    'site' => $selection->site->getIdentifier(),
                ]);
                $variables['failed'] = true;
                // Editors can't fix the connection, so only admins get the details
                $variables['errorMessage'] = $backendUser->isAdmin() ? $e->getMessage() : '';
            }
        }

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:analytics_matomo/Resources/Private/Templates'],
            partialRootPaths: ['EXT:dashboard/Resources/Private/Partials', 'EXT:analytics_matomo/Resources/Private/Partials'],
            layoutRootPaths: ['EXT:dashboard/Resources/Private/Layouts'],
            request: $this->request,
        ));
        $view->assignMultiple($variables);
        return $view->render('Widget/Matomo');
    }

    /**
     * @return array<string, mixed>
     */
    public function getEventData(): array
    {
        return $this->eventData;
    }

    /**
     * @return array{report?: string, refreshAvailable?: bool}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return [
            'EXT:analytics_matomo/Resources/Public/Css/statistics-bar.css',
            'EXT:analytics_matomo/Resources/Public/Css/dashboard.css',
        ];
    }

    public function getJavaScriptModuleInstructions(): array
    {
        $instructions = [JavaScriptModuleInstruction::create('@maikschneider/analytics-matomo/dashboard-widget.js')];
        if (($this->options['report'] ?? '') === self::REPORT_TRAFFIC) {
            $instructions[] = JavaScriptModuleInstruction::create('@typo3/dashboard/contrib/chartjs.js');
            $instructions[] = JavaScriptModuleInstruction::create('@typo3/dashboard/chart-initializer.js');
        }
        return $instructions;
    }

    /**
     * @return array<string, mixed>
     * @throws MatomoApiException
     */
    private function loadReport(string $report, DashboardSelection $selection): array
    {
        if ($report === self::REPORT_PERFORMANCE) {
            return ['tiles' => $this->tileFactory->createForSite($this->statisticsService->getPerformance($selection->configuration, $selection->period))];
        }
        if ($report === self::REPORT_TRAFFIC) {
            $traffic = $this->statisticsService->getTraffic($selection->configuration, $selection->period);
            $this->eventData = ['graphConfig' => $this->trafficChart($traffic)];
            return ['traffic' => $traffic, 'totals' => [
                'visits' => array_sum($traffic->visits),
                'newVisits' => array_sum($traffic->newVisits),
                'returningVisits' => array_sum($traffic->returningVisits),
            ], 'isEmpty' => $traffic->totalVisits() === 0];
        }
        if ($report === self::REPORT_TOP_PAGES) {
            $pages = $this->topPages($selection);
            return ['pages' => $pages, 'isEmpty' => $pages === []];
        }
        throw new \InvalidArgumentException('Unknown Matomo widget report "' . $report . '"', 1793960201);
    }

    /**
     * Top pages of the selected site; URLs of other sites tracked in the same
     * Matomo site are left out.
     *
     * @return list<array<string, mixed>>
     * @throws MatomoApiException
     */
    private function topPages(DashboardSelection $selection): array
    {
        $backendUser = $this->getBackendUser();
        $pages = [];
        foreach ($this->statisticsService->getTopPages($selection->configuration, $selection->period) as $page) {
            $match = $this->pageUrlMapper->match($selection->site, $page->url);
            if ($match === null || !$backendUser->checkLanguageAccess($match->language->getLanguageId())) {
                continue;
            }
            $record = $match->pageId === null ? false : BackendUtility::readPageAccess($match->pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
            // URLs without a readable page, e.g. of other systems on the same domain, only for admins
            if ($record === false && !$backendUser->isAdmin()) {
                continue;
            }
            $path = parse_url($page->url, PHP_URL_PATH);
            $pages[] = [
                'url' => $page->url,
                'path' => is_string($path) && $path !== '' ? $path : '/',
                'title' => $record === false ? '' : (string)($record['title'] ?? ''),
                'pageModuleUrl' => $record === false ? '' : (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $match->pageId]),
                'flag' => $match->language->getFlagIdentifier(),
                'languageTitle' => $match->language->getTitle(),
                'views' => $page->views,
                'share' => $this->percent($page->share),
                'shareWidth' => round($page->share * 100, 1),
                'change' => $page->previousViews > 0 ? $this->signedPercent($page->views / $page->previousViews - 1) : null,
                'changeRating' => $page->previousViews === 0 || $page->views === $page->previousViews ? null : ($page->views > $page->previousViews ? 'positive' : 'negative'),
            ];
            if (count($pages) === self::TOP_PAGES) {
                break;
            }
        }
        return $pages;
    }

    /**
     * @return array<string, mixed>
     */
    private function trafficChart(Traffic $traffic): array
    {
        $ll = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:';
        $series = [
            'visits' => $traffic->visits,
            'visitors' => $traffic->visitors,
            'newVisits' => $traffic->newVisits,
            'returningVisits' => $traffic->returningVisits,
        ];
        $datasets = [];
        foreach (array_keys($series) as $index => $key) {
            $datasets[] = [
                'label' => $this->getLanguageService()->sL($ll . 'metric.' . $key),
                'data' => $series[$key],
                'borderColor' => self::CHART_COLORS[$index],
                'backgroundColor' => self::CHART_COLORS[$index],
                'tension' => 0.3,
                'pointRadius' => 2,
                // Visits are shown by default, the other series on demand via the legend
                'hidden' => $key !== 'visits' && $key !== 'visitors',
            ];
        }
        return [
            'type' => 'line',
            'data' => [
                'labels' => array_map(static fn (string $date): string => (new \DateTimeImmutable($date))->format('d.m.'), $traffic->dates),
                'datasets' => $datasets,
            ],
            'options' => [
                'maintainAspectRatio' => false,
                'interaction' => ['mode' => 'index', 'intersect' => false],
                'plugins' => ['legend' => ['position' => 'bottom']],
                'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            ],
        ];
    }

    private function percent(float $ratio): string
    {
        return number_format($ratio * 100, 1) . ' %';
    }

    private function signedPercent(float $ratio): string
    {
        return ($ratio > 0 ? '+' : '−') . $this->percent(abs($ratio));
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
