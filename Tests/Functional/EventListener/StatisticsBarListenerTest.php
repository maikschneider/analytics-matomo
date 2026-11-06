<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Context\PageContextFactory;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class StatisticsBarListenerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'dashboard',
    ];

    protected array $testExtensionsToLoad = [
        'maikschneider/analytics-matomo',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function addsLoadingBarWithAjaxUrlOfTheSelectedLanguage(): void
    {
        $html = $this->dispatch(2, [1]);

        self::assertStringContainsString('tx-analytics-matomo--loading', $html);
        self::assertMatchesRegularExpression('#data-url="/typo3/ajax/analytics-matomo/statistics-bar\?token=\w+&pageId=2&languageId=1"#', html_entity_decode($html));
    }

    #[Test]
    public function addsNothingForPagesWithoutUrl(): void
    {
        self::assertSame('', $this->dispatch(4, [0]));
    }

    #[Test]
    public function addsNothingWithoutPageStatisticsPermission(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(4);

        self::assertSame('', $this->dispatch(2, [0]));
    }

    #[Test]
    public function addsBarForEditorsWithPageStatisticsPermission(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);

        self::assertStringContainsString('tx-analytics-matomo--loading', $this->dispatch(2, [0]));
    }

    #[Test]
    public function addsNothingInLanguageComparison(): void
    {
        self::assertSame('', $this->dispatch(2, [0, 1]));
    }

    #[Test]
    public function addsStylesheetAndJavaScriptModule(): void
    {
        $this->dispatch(2, [0]);

        $state = $this->get(PageRenderer::class)->getState();
        // TYPO3 v14 stores EXT: paths as PKG: paths
        self::assertMatchesRegularExpression(
            '#(EXT:analytics_matomo/|PKG:maikschneider/analytics-matomo:)Resources/Public/Css/statistics-bar\.css#',
            implode(',', array_keys($state['cssFiles']))
        );
        self::assertStringContainsString('@maikschneider/analytics-matomo/statistics-bar.js', (string)json_encode($state['javaScriptRenderer'], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Builds the request the Page module hands to the event. TYPO3 v13 keeps the
     * selected language and view in the module data, v14 in the page context.
     *
     * @param list<int> $languageIds
     */
    private function dispatch(int $pageId, array $languageIds): string
    {
        $module = $this->get(ModuleProvider::class)->getModule('web_layout');
        self::assertNotNull($module);
        $request = (new ServerRequest('https://example.com/typo3/module/web/layout?id=' . $pageId))
            ->withQueryParams(['id' => (string)$pageId])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']))
            ->withAttribute('route', new Route('/module/web/layout', ['packageName' => 'typo3/cms-backend']))
            ->withAttribute('module', $module)
            ->withAttribute('site', $this->get(SiteFinder::class)->getSiteByPageId($pageId))
            ->withAttribute('moduleData', new ModuleData('web_layout', [
                'function' => count($languageIds) > 1 ? 2 : 1,
                'language' => $languageIds[0],
            ]));
        if (class_exists(PageContextFactory::class)) {
            $request = $request->withAttribute(
                'pageContext',
                $this->get(PageContextFactory::class)->createWithLanguages($request, $pageId, $languageIds, $GLOBALS['BE_USER'])
            );
        }

        $event = new ModifyPageLayoutContentEvent($request, $this->get(ModuleTemplateFactory::class)->create($request));
        $this->get(EventDispatcherInterface::class)->dispatch($event);

        return $event->getHeaderContent();
    }
}
