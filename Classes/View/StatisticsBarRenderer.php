<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\View;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final readonly class StatisticsBarRenderer
{
    public function __construct(
        private ViewFactoryInterface $viewFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function render(ServerRequestInterface $request, array $variables): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:analytics_matomo/Resources/Private/Templates'],
            partialRootPaths: ['EXT:analytics_matomo/Resources/Private/Partials'],
            request: $request,
        ));
        $view->assignMultiple($variables);
        return $view->render('StatisticsBar');
    }
}
