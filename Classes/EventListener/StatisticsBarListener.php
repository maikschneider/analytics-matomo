<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\EventListener;

use MaikSchneider\AnalyticsMatomo\Statistics\PageStatisticsService;
use MaikSchneider\AnalyticsMatomo\Statistics\PageTargetResolver;
use MaikSchneider\AnalyticsMatomo\View\StatisticsBarRenderer;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Adds a loading placeholder of the statistics bar on top of the Page module.
 * The numbers are fetched afterwards by JavaScript, so a slow Matomo never
 * delays the module itself.
 */
#[AsEventListener('analytics-matomo/statistics-bar')]
final readonly class StatisticsBarListener
{
    public function __construct(
        private PageTargetResolver $pageTargetResolver,
        private StatisticsBarRenderer $renderer,
        private PageRenderer $pageRenderer,
        private UriBuilder $uriBuilder,
    ) {
    }

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $languageId = $this->getSelectedLanguageId($request);
        if ($languageId === null) {
            return;
        }
        $target = $this->pageTargetResolver->resolve((int)($request->getQueryParams()['id'] ?? 0), $languageId);
        if ($target === null) {
            return;
        }

        $this->pageRenderer->addCssFile('EXT:analytics_matomo/Resources/Public/Css/statistics-bar.css');
        $this->pageRenderer->loadJavaScriptModule('@maikschneider/analytics-matomo/statistics-bar.js');

        $event->addHeaderContent($this->renderer->render($request, [
            'loading' => true,
            'url' => (string)$this->uriBuilder->buildUriFromRoute('ajax_analytics_matomo_statistics_bar', [
                'pageId' => $target->pageId,
                'languageId' => $target->languageId,
            ]),
            'days' => PageStatisticsService::PERIODS[0],
        ]));
    }

    /**
     * Returns null when several languages are shown side by side, because the
     * bar is about one page URL.
     */
    private function getSelectedLanguageId(ServerRequestInterface $request): ?int
    {
        // TYPO3 v14
        $pageContext = $request->getAttribute('pageContext');
        if ($pageContext instanceof PageContext) {
            return $pageContext->hasMultipleLanguagesSelected() ? null : $pageContext->getPrimaryLanguageId();
        }

        // TYPO3 v13: "function" 2 is the language comparison view, "language" -1 means all languages
        $moduleData = $request->getAttribute('moduleData');
        if (!$moduleData instanceof ModuleData) {
            return 0;
        }
        if ((int)$moduleData->get('function') === 2) {
            return null;
        }
        return max(0, (int)$moduleData->get('language'));
    }
}
