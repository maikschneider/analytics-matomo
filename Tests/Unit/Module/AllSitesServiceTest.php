<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Module;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use MaikSchneider\AnalyticsMatomo\Module\AllSitesService;
use MaikSchneider\AnalyticsMatomo\Statistics\Period;
use MaikSchneider\AnalyticsMatomo\Tests\Unit\InMemoryCacheTrait;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AllSitesServiceTest extends UnitTestCase
{
    use InMemoryCacheTrait;

    private const NO_ACCESS_FIXTURE = __DIR__ . '/../../Fixtures/Matomo/AllSitesNoViewAccess.json';

    /** @var list<array<string, mixed>> form parameters of every HTTP request */
    private array $sent = [];

    #[Test]
    public function sitesOfOneConnectionShareOneRequest(): void
    {
        $totals = $this->subject([[['nb_visits' => 32], ['nb_visits' => 5]], [['nb_visits' => 4]]])->getTotals([
            'main' => $this->site('main', 'https://matomo.example.com/', 3, 'token'),
            'blog' => $this->site('blog', 'https://matomo.example.com', 5, 'token'),
            'other' => $this->site('other', 'https://matomo.example.com', 6, 'other-token'),
        ], $this->period(), false);

        self::assertSame([['nb_visits' => 32], ['nb_visits' => 5], ['nb_visits' => 4]], array_column($totals, 'current'));
        self::assertSame([['3', '5'], ['6']], array_map($this->siteIds(...), $this->sent));
    }

    #[Test]
    public function aSiteWithoutAccessOnlyAffectsItselfAndIsAskedAgain(): void
    {
        $sites = [
            'main' => $this->site('main', 'https://matomo.example.com', 3, 'token'),
            'outlet' => $this->site('outlet', 'https://matomo.example.com', 4, 'token'),
            'blog' => $this->site('blog', 'https://matomo.example.com', 5, 'token'),
        ];
        $noAccess = json_decode((string)file_get_contents(self::NO_ACCESS_FIXTURE), true)[1];
        $subject = $this->subject([
            [['nb_visits' => 32], $noAccess, ['nb_visits' => 5]],
            [$noAccess],
        ]);

        $totals = $subject->getTotals($sites, $this->period(), false);
        self::assertSame(['nb_visits' => 32], $totals['main']['current']);
        self::assertNull($totals['outlet']['current']);
        self::assertSame(['nb_visits' => 5], $totals['blog']['current']);

        $again = $subject->getTotals($sites, $this->period(), false);
        self::assertSame($totals, $again);
        self::assertSame([['3', '4', '5'], ['4']], array_map($this->siteIds(...), $this->sent));
    }

    #[Test]
    public function anUnreachableMatomoIsAskedOnce(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())->method('request')->willThrowException(new ConnectException('timeout', new Request('POST', 'https://matomo.example.com')));
        $subject = new AllSitesService($this->resolver(), new MatomoClient($requestFactory), $this->createStatisticsCache(), new NullLogger());

        $totals = $subject->getTotals([
            'main' => $this->site('main', 'https://matomo.example.com', 3, 'token'),
            'blog' => $this->site('blog', 'https://matomo.example.com', 5, 'token'),
        ], $this->period(), false);

        self::assertSame([null, null], array_column($totals, 'current'));
    }

    /**
     * @param list<list<mixed>> $answers bulk answers in order
     */
    private function subject(array $answers): AllSitesService
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(function (string $url, string $method, array $options) use (&$answers): Response {
            $this->sent[] = $options['form_params'];
            $stream = new Stream('php://temp', 'rw');
            $stream->write((string)json_encode(array_shift($answers)));
            $stream->rewind();
            return new Response($stream, 200);
        });
        return new AllSitesService($this->resolver(), new MatomoClient($requestFactory), $this->createStatisticsCache(), new NullLogger());
    }

    private function resolver(): MatomoConfigurationResolver
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new ExtensionConfigurationExtensionNotConfiguredException('', 1));
        return new MatomoConfigurationResolver($extensionConfiguration);
    }

    private function site(string $identifier, string $url, int $idSite, string $token): Site
    {
        return new Site($identifier, $idSite * 10, ['settings' => ['analyticsMatomo' => ['url' => $url, 'idSite' => $idSite, 'tokenAuth' => $token]]]);
    }

    private function period(): Period
    {
        return Period::fromPreset('7', new \DateTimeImmutable('2026-11-02'));
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<string>
     */
    private function siteIds(array $parameters): array
    {
        return array_values(array_map(static function (string $url): string {
            parse_str($url, $request);
            /** @var array<string, string> $request */
            return $request['idSite'];
        }, (array)$parameters['urls']));
    }
}
