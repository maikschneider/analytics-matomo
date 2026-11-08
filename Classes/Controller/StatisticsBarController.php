<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Controller;

use MaikSchneider\AnalyticsMatomo\Annotation\Annotation;
use MaikSchneider\AnalyticsMatomo\Annotation\AnnotationService;
use MaikSchneider\AnalyticsMatomo\Annotation\ChangeImpact;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Module\MetricFormatter;
use MaikSchneider\AnalyticsMatomo\Statistics\PageMetrics;
use MaikSchneider\AnalyticsMatomo\Statistics\PageStatistics;
use MaikSchneider\AnalyticsMatomo\Statistics\PageStatisticsService;
use MaikSchneider\AnalyticsMatomo\Statistics\PageTarget;
use MaikSchneider\AnalyticsMatomo\Statistics\PageTargetResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Statistics\TileFactory;
use MaikSchneider\AnalyticsMatomo\View\StatisticsBarRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Renders the filled statistics bar for the Page module (AJAX).
 */
#[AsController]
final readonly class StatisticsBarController
{
    public function __construct(
        private PageTargetResolver $pageTargetResolver,
        private PageStatisticsService $statisticsService,
        private TileFactory $tileFactory,
        private StatisticsBarRenderer $renderer,
        private LoggerInterface $logger,
        private Context $context,
        private UriBuilder $uriBuilder,
        private AnnotationService $annotationService,
        private SiteFinder $siteFinder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $days = Period::normalizeDays((int)($queryParams['days'] ?? 0));
        $target = $this->pageTargetResolver->resolve((int)($queryParams['pageId'] ?? 0), (int)($queryParams['languageId'] ?? 0));
        if ($target === null) {
            return new JsonResponse(['html' => ''], 404);
        }

        $tiles = [];
        $impact = null;
        $error = '';
        try {
            $statistics = $this->statisticsService->getStatistics($target->configuration, $target->pageUrl, $days);
            $tiles = $this->tileFactory->create($statistics);
            $impact = $this->impact($target, $statistics, $days);
        } catch (MatomoApiException $e) {
            $this->logger->warning('Loading Matomo statistics failed: {message}', [
                'message' => $e->getMessage(),
                'pageUrl' => $target->pageUrl,
            ]);
            $error = $e->getMessage();
        }

        $backendUser = $this->getBackendUser();
        return new JsonResponse(['html' => $this->renderer->render($request, [
            'target' => $target,
            'tiles' => $tiles,
            'impact' => $impact,
            'failed' => $error !== '',
            // Editors can't fix the connection, so only admins get the details
            'errorMessage' => $backendUser->isAdmin() ? $error : '',
            'days' => $days,
            'periods' => Period::DAYS,
            'reportUrl' => $target->configuration->getReportUrl(Period::lastDays($days, $this->context->getPropertyFromAspect('date', 'full'))),
            'detailsUrl' => $backendUser->check('modules', 'analytics_matomo') && $backendUser->check('modules', 'analytics_matomo_page')
                ? (string)$this->uriBuilder->buildUriFromRoute('analytics_matomo_page', ['id' => $target->pageId, 'language' => $target->languageId, 'period' => $days])
                : '',
        ])]);
    }

    /**
     * Daily views before and since the last annotated change of the page in the period
     *
     * @return array{date: string, before: string, after: string, change: string}|null
     */
    private function impact(PageTarget $target, PageStatistics $statistics, int $days): ?array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($target->pageId);
        } catch (SiteNotFoundException) {
            return null;
        }
        /** @var \DateTimeImmutable $now */
        $now = $this->context->getPropertyFromAspect('date', 'full');
        $changes = Annotation::forPage($this->annotationService->get($site, $target->configuration, Period::lastDays($days, $now)), $target->pageUrl);
        if ($changes === []) {
            return null;
        }
        $views = array_map(static fn (PageMetrics $metrics): int => $metrics->views, $statistics->daily);
        $impact = ChangeImpact::fromDailyViews($views, $changes[count($changes) - 1]->date, $now->format('Y-m-d'));
        if ($impact === null) {
            return null;
        }
        $formatter = new MetricFormatter();
        return [
            'date' => $impact->date,
            'before' => $formatter->format(round($impact->before, 1), 'number'),
            'after' => $formatter->format(round($impact->after, 1), 'number'),
            'change' => $impact->change === null ? '' : $formatter->format($impact->change, 'growth'),
        ];
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
