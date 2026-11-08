<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Annotation;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Controller\StatisticsBarController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class StatisticsBarAnnotationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/AnnotationSites' => 'typo3conf/sites',
    ];

    /** @var list<string> */
    private array $methods = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Annotations.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        /** @var \DateTimeImmutable $now */
        $now = $this->get(Context::class)->getPropertyFromAspect('date', 'full');
        $changed = $now->modify('-5 days')->format('Y-m-d');
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request) use ($changed): FulfilledPromise {
            parse_str((string)$request->getBody(), $body);
            $results = [];
            foreach (is_array($body['urls'] ?? null) ? $body['urls'] : [] as $url) {
                parse_str((string)$url, $parameters);
                $method = is_string($parameters['method'] ?? null) ? $parameters['method'] : '';
                $this->methods[] = $method;
                if ($method === 'Annotations.getAll') {
                    $results[] = [3 => [
                        ['date' => $changed, 'note' => 'TYPO3: https://example.com/about edited by editor'],
                        ['date' => $changed, 'note' => 'TYPO3: https://example.com/about-us edited by editor'],
                    ]];
                } elseif (($parameters['period'] ?? '') === 'day') {
                    [$from, $to] = explode(',', (is_string($parameters['date'] ?? null) ? $parameters['date'] : '') . ',');
                    $days = [];
                    for ($day = new \DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
                        $days[$day->format('Y-m-d')] = [['nb_hits' => $day->format('Y-m-d') < $changed ? 10 : 40]];
                    }
                    $results[] = $days;
                } else {
                    $results[] = [['nb_hits' => 300]];
                }
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    #[Test]
    public function showsTheAverageDailyViewsBeforeAndSinceTheLastChangeOfThePage(): void
    {
        $html = $this->bar(2);

        self::assertStringContainsString('data-annotation-impact', $html);
        self::assertMatchesRegularExpression('#Changed on [^<]+: 40 views per day since, 10 before \(\+300(\.0)?\s?%\)#u', $html);
    }

    #[Test]
    public function aPageWithoutAnnotationsHasNoComparison(): void
    {
        $html = $this->bar(1);

        self::assertStringContainsString('tx-analytics-matomo-metric', $html);
        self::assertStringNotContainsString('data-annotation-impact', $html);
    }

    #[Test]
    public function sitesWithoutTheSettingDoNotAskForAnnotations(): void
    {
        $html = $this->bar(11);

        self::assertStringContainsString('tx-analytics-matomo-metric', $html);
        self::assertNotContains('Annotations.getAll', $this->methods);
    }

    private function bar(int $pageId): string
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/analytics-matomo/statistics-bar'))
            ->withQueryParams(['pageId' => (string)$pageId, 'days' => '14'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $data = json_decode((string)$this->get(StatisticsBarController::class)($request)->getBody(), true);
        self::assertIsArray($data);
        self::assertIsString($data['html']);
        return $data['html'];
    }
}
