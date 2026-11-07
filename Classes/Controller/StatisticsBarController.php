<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Controller;

use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Statistics\PageStatisticsService;
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
use TYPO3\CMS\Core\Http\JsonResponse;

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
        $error = '';
        try {
            $statistics = $this->statisticsService->getStatistics($target->configuration, $target->pageUrl, $days);
            $tiles = $this->tileFactory->create($statistics);
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

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
