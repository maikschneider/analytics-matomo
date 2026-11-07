<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use MaikSchneider\AnalyticsMatomo\Controller\ReportModuleController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Middleware\BackendModuleValidator;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Runs the report module controller like the backend does: through the module
 * validator, which checks module access and fills the module data.
 * Tests using it restore $GLOBALS['TYPO3_REQUEST'] in their tearDown.
 */
trait ModuleRequestTrait
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $body
     */
    private function render(int $userId, array $query = [], string $section = 'visitors', array $body = [], bool $validate = true): ResponseInterface
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser($userId);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $route = $this->get(Router::class)->getRoute('analytics_matomo_' . $section);
        $request = (new ServerRequest('https://example.com/typo3/module/analytics-matomo/' . $section, $body === [] ? 'GET' : 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([
                'HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php', 'SCRIPT_FILENAME' => '/var/www/html/public/typo3/index.php',
            ]))
            ->withAttribute('route', $route)
            ->withQueryParams($query)
            ->withParsedBody($body);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $controller = $this->get(ReportModuleController::class);
        if (!$validate) {
            $module = $this->get(ModuleProvider::class)->getModule('analytics_matomo_' . $section);
            self::assertNotNull($module);
            return $controller->handleRequest($request
                ->withAttribute('module', $module)
                ->withAttribute('moduleData', new ModuleData($module->getIdentifier(), $module->getDefaultModuleData())));
        }
        $handler = new class($controller) implements RequestHandlerInterface {
            public function __construct(private ReportModuleController $controller)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->controller->handleRequest($request);
            }
        };
        return $this->get(BackendModuleValidator::class)->process($request, $handler);
    }
}
