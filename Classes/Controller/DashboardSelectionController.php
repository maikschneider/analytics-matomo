<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Controller;

use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Stores the site and period chosen in a Matomo dashboard widget or the backend module (AJAX).
 */
#[AsController]
final readonly class DashboardSelectionController
{
    public function __construct(
        private DashboardSelectionResolver $selectionResolver,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $saved = is_array($body) && $this->selectionResolver->save(
            $this->getBackendUser(),
            isset($body['site']) ? (string)$body['site'] : null,
            isset($body['period']) ? (string)$body['period'] : null,
            (string)($body['from'] ?? ''),
            (string)($body['to'] ?? ''),
            isset($body['compare']) ? (bool)$body['compare'] : null,
        );
        return new JsonResponse(['success' => $saved], $saved ? 200 : 403);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
