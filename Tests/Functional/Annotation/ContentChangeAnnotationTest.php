<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Annotation;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Annotation\ContentChangeHook;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ContentChangeAnnotationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/AnnotationSites' => 'typo3conf/sites',
    ];

    /** @var list<array<mixed>> sub-requests of every bulk request sent */
    private array $calls = [];

    /** @var list<array<string, mixed>> annotations of the day Matomo already has */
    private array $existing = [];

    private bool $matomoDown = false;

    private int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Annotations.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request): FulfilledPromise|RejectedPromise {
            $this->attempts++;
            if ($this->matomoDown) {
                return new RejectedPromise(new ConnectException('Connection refused', $request));
            }
            parse_str((string)$request->getBody(), $body);
            $results = [];
            foreach (is_array($body['urls'] ?? null) ? $body['urls'] : [] as $url) {
                parse_str((string)$url, $parameters);
                $parameters['token_auth'] = $body['token_auth'] ?? '';
                $this->calls[] = $parameters;
                $results[] = ($parameters['method'] ?? '') === 'Annotations.getAll'
                    ? [3 => $this->existing]
                    : ['id' => 1, 'idNote' => 1, 'date' => $parameters['date'] ?? '', 'note' => $parameters['note'] ?? ''];
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    #[Test]
    public function liveContentChangeAnnotatesThePageUrlOncePerDayAndLanguage(): void
    {
        $this->updateContent(1, 'About us, new');
        $this->updateContent(1, 'About us, newer');
        $this->updatePage(2, 'About, new');

        $today = $this->today();
        self::assertSame([
            ['method' => 'Annotations.getAll', 'period' => 'day', 'date' => $today, 'idSite' => '3', 'token_auth' => 'view-token'],
            ['method' => 'Annotations.add', 'date' => $today, 'note' => 'TYPO3: https://example.com/about edited by admin', 'idSite' => '3', 'token_auth' => 'view-token'],
        ], $this->calls);

        $this->calls = [];
        $this->updateContent(2, 'Über uns, neu');
        self::assertSame(['Annotations.getAll', 'Annotations.add'], array_column($this->calls, 'method'));
        self::assertSame(['TYPO3: https://example.com/de/ueber-uns edited by admin'], array_column($this->calls, 'note'));
    }

    #[Test]
    public function newAndDeletedContentElementsCount(): void
    {
        $dataHandler = $this->dataHandler(['tt_content' => ['NEW1' => ['pid' => 2, 'CType' => 'header', 'header' => 'New']]], []);
        self::assertSame([], $dataHandler->errorLog);
        self::assertSame(['Annotations.getAll', 'Annotations.add'], array_column($this->calls, 'method'));

        $this->get(CacheManager::class)->getCache('analytics_matomo')->flush();
        $this->calls = [];
        $this->existing = [];
        $this->dataHandler([], ['tt_content' => [1 => ['delete' => 1]]]);
        self::assertSame(['Annotations.getAll', 'Annotations.add'], array_column($this->calls, 'method'));
    }

    #[Test]
    public function aNoteOfTheDayInMatomoIsNotRepeated(): void
    {
        $this->existing = [['date' => $this->today(), 'note' => 'TYPO3: https://example.com/about edited by someone-else', 'idNote' => 4]];
        $this->updateContent(1, 'About us, new');
        $this->updateContent(1, 'About us, newer');

        self::assertSame(['Annotations.getAll'], array_column($this->calls, 'method'));
    }

    #[Test]
    public function publishingFromAWorkspaceAnnotates(): void
    {
        $this->dataHandler([], ['tt_content' => [1 => ['version' => ['action' => 'publish', 'swapWith' => 99]]]]);

        self::assertSame(['Annotations.getAll', 'Annotations.add'], array_column($this->calls, 'method'));
    }

    #[Test]
    public function publishingAWholeWorkspaceAnnotates(): void
    {
        $this->dataHandler([], ['tt_content' => [1 => ['version' => ['action' => 'swap', 'swapWith' => 99]]]]);

        self::assertSame(['Annotations.getAll', 'Annotations.add'], array_column($this->calls, 'method'));
    }

    #[Test]
    public function contentForAllLanguagesAnnotatesEveryLanguage(): void
    {
        $this->updateContent(6, 'For all languages, new');

        self::assertSame([
            'TYPO3: https://example.com/about edited by admin',
            'TYPO3: https://example.com/de/ueber-uns edited by admin',
        ], array_values(array_filter(array_column($this->calls, 'note'))));
    }

    #[Test]
    public function savingWorksWhenTheMessageCannotBeDispatched(): void
    {
        GeneralUtility::addInstance(ContentChangeHook::class, new ContentChangeHook(
            new class() implements MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    throw new \RuntimeException('Transport unavailable', 1762560001);
                }
            },
            $this->get(SiteFinder::class),
            $this->get(Context::class),
            $this->get(LogManager::class)->getLogger(ContentChangeHook::class),
        ));
        $dataHandler = $this->updateContent(1, 'Saved without a message');

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame('Saved without a message', BackendUtility::getRecord('tt_content', 1)['header'] ?? null);
    }

    #[Test]
    public function workspaceDraftsSitesWithoutTheSettingFoldersAndDemoSitesAreNotAnnotated(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->updateContent(1, 'Draft');
        $GLOBALS['BE_USER']->workspace = 0;

        $this->updateContent(3, 'In a folder');
        $this->updateContent(4, 'Products of a site without annotations');
        $this->updateContent(5, 'Demo');

        self::assertSame([], $this->calls);
    }

    #[Test]
    public function savingWorksWhileMatomoIsDownAndChangesAfterAPauseTryAgain(): void
    {
        $this->matomoDown = true;
        $dataHandler = $this->updateContent(1, 'Saved anyway');
        $this->updatePage(2, 'Saved without waiting for Matomo again');

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame('Saved anyway', BackendUtility::getRecord('tt_content', 1)['header'] ?? null);
        self::assertSame(1, $this->attempts);

        $this->matomoDown = false;
        $this->updateContent(1, 'Still pausing');
        self::assertSame([], $this->calls);

        $this->get(CacheManager::class)->getCache('analytics_matomo')->flush();
        $this->updateContent(1, 'Saved again');
        self::assertSame(['Annotations.getAll', 'Annotations.add'], array_column($this->calls, 'method'));
    }

    private function updateContent(int $uid, string $header): DataHandler
    {
        return $this->dataHandler(['tt_content' => [$uid => ['header' => $header]]], []);
    }

    private function updatePage(int $uid, string $title): DataHandler
    {
        return $this->dataHandler(['pages' => [$uid => ['title' => $title]]], []);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     * @param array<string, array<int, array<string, mixed>>> $commands
     */
    private function dataHandler(array $data, array $commands): DataHandler
    {
        $dataHandler = $this->get(DataHandler::class);
        $dataHandler->start($data, $commands, $GLOBALS['BE_USER']);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        return $dataHandler;
    }

    private function today(): string
    {
        /** @var \DateTimeImmutable $now */
        $now = $this->get(Context::class)->getPropertyFromAspect('date', 'full');
        return $now->format('Y-m-d');
    }
}
