<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Controller;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Behaviour > 404 pages: the admin (1), an English-only editor who may write
 * redirects (3) and one who may not (4)
 */
final class NotFoundTest extends FunctionalTestCase
{
    use ModuleRequestTrait;

    private const ADMIN = 1;
    private const REDIRECT_EDITOR = 3;
    private const EDITOR = 4;

    protected array $coreExtensionsToLoad = ['dashboard', 'redirects'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    /** @var list<array<string, string>> parameters of every sub-request sent to Matomo */
    private array $calls = [];
    private ?ServerRequestInterface $previousRequest = null;

    protected function setUp(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Redirects.csv');
        $groups = $this->get(ConnectionPool::class)->getConnectionForTable('be_groups');
        $groups->update('be_groups', ['groupMods' => 'analytics_matomo,analytics_matomo_behaviour'], ['uid' => 1]);
        $groups->update('be_groups', ['tables_select' => 'sys_redirect', 'tables_modify' => 'sys_redirect'], ['uid' => 2]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-02 14:30:00')));
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/SyntheticNotFound.json'), true, flags: JSON_THROW_ON_ERROR);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request) use ($rows): FulfilledPromise {
            $segments = $this->answerWithoutSegments($request);
            if ($segments !== null) {
                return $segments;
            }
            parse_str((string)$request->getBody(), $body);
            self::assertIsArray($body['urls']);
            $results = [];
            foreach ($body['urls'] as $url) {
                parse_str((string)$url, $parameters);
                /** @var array<string, string> $parameters */
                $this->calls[] = $parameters;
                self::assertSame('Actions.getPageTitles', $parameters['method']);
                $results[] = $rows;
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    protected function tearDown(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN');
        if ($this->previousRequest === null) {
            unset($GLOBALS['TYPO3_REQUEST']);
        } else {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        }
        parent::tearDown();
    }

    #[Test]
    public function listsPathsAboveTheMinimumWithHitsTopReferrerAndSuggestions(): void
    {
        $html = $this->notFound(self::ADMIN);

        self::assertCount(1, $this->calls);
        self::assertSame(['404/URL', '1', '-1', '0'], [$this->calls[0]['filter_pattern'], $this->calls[0]['flat'], $this->calls[0]['filter_limit'], $this->calls[0]['format_metrics']]);
        self::assertSame(['/abuot', '/redirected', '/de/ueber-un', '/summer-sale-2025', '/&lt;script&gt;alert(1)&lt;/script&gt;', '/about-old', '/hash#tag', '/nothing-like-it', '/question?mark'], $this->listedPaths($html));
        self::assertMatchesRegularExpression('#<code>/abuot</code></td>\s*<td>7</td>\s*<td><span class="text-break">https://www\.google\.com/</span></td>#', $html);
        // Anyone can send tracking requests, so titles are untrusted
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('<span class="text-break">javascript:alert(1)</span>', $html);
        self::assertMatchesRegularExpression('#About <code>/about</code>.*?60 % match#s', $this->row($html, '/abuot'));
        // Below the minimum of 2 hits, and a bot path of the default ignore list
        self::assertStringNotContainsString('/saxony', $html);
        self::assertStringNotContainsString('wp-login', $html);
    }

    #[Test]
    public function suggestionsComeFromTheLanguageOfThePathAndFromOldSlugs(): void
    {
        $html = $this->notFound(self::ADMIN);

        self::assertStringContainsString('Über uns <code>/de/ueber-uns</code>', $this->row($html, '/de/ueber-un'));
        self::assertStringNotContainsString('<code>/about</code>', $this->row($html, '/de/ueber-un'));
        // A disabled redirect still knows the old slug of the page
        self::assertMatchesRegularExpression('#About <code>/about-old</code>.*?100 % match.*?Old URL of the page#s', $this->row($html, '/about-old'));
        // Hidden pages are no target
        self::assertStringContainsString('No similar page', $this->row($html, '/summer-sale-2025'));
    }

    #[Test]
    public function pathsWithAnActiveRedirectShowItInsteadOfSuggestions(): void
    {
        $html = $this->notFound(self::ADMIN);

        self::assertMatchesRegularExpression('#Redirected.*?Edit redirect#s', $this->row($html, '/redirected'));
        self::assertStringNotContainsString('redirectPath', $this->row($html, '/redirected'));
    }

    #[Test]
    public function aSuggestionOpensAPreviewThatCreatesNothing(): void
    {
        $html = $this->notFound(self::ADMIN, ['redirectPath' => '/abuot', 'redirectTarget' => '2', 'createRedirect' => '1']);

        self::assertStringContainsString('<h2>Create this redirect?</h2>', $html);
        self::assertStringContainsString('<code>example.com/abuot</code>', $html);
        self::assertStringContainsString('<code>https://example.com/about</code>', $html);
        self::assertMatchesRegularExpression('#Status code</dt>\s*<dd class="col-sm-9">301</dd>#', $html);
        self::assertSame([], $this->redirects('/abuot'), 'A GET request creates no redirect');
    }

    #[Test]
    public function thePreviewButtonCreatesTheRedirectThroughDataHandler(): void
    {
        $response = $this->render(self::ADMIN, ['id' => '1', 'report' => 'notFound'], 'behaviour', ['redirectPath' => '/abuot', 'redirectTarget' => '2', 'createRedirect' => '1']);

        self::assertSame(303, $response->getStatusCode());
        $redirects = $this->redirects('/abuot');
        self::assertCount(1, $redirects);
        self::assertSame(['pid' => 0, 'source_host' => 'example.com', 'target' => 't3://page?uid=2&_language=0', 'target_statuscode' => 301, 'is_regexp' => 0], array_intersect_key($redirects[0], ['pid' => 1, 'source_host' => 1, 'target' => 1, 'target_statuscode' => 1, 'is_regexp' => 1]));
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertSame('Redirect from /abuot to "About" created.', $messages[0]->getMessage());
        self::assertStringContainsString('Redirected', $this->row($this->notFound(self::ADMIN), '/abuot'));
    }

    #[Test]
    public function onlyListedPathsAndTheirSuggestionsCanBeCreated(): void
    {
        foreach ([['/abuot', '7'], ['/somewhere', '2'], ['/redirected', '7']] as [$path, $target]) {
            $this->render(self::ADMIN, ['id' => '1', 'report' => 'notFound'], 'behaviour', ['redirectPath' => $path, 'redirectTarget' => $target, 'createRedirect' => '1']);
        }

        self::assertCount(1, $this->get(ConnectionPool::class)->getConnectionForTable('sys_redirect')->select(['uid'], 'sys_redirect', ['source_path' => '/redirected'])->fetchAllAssociative());
        self::assertSame([], $this->redirects('/abuot'));
        self::assertSame([], $this->redirects('/somewhere'));
    }

    #[Test]
    public function editorsWithWriteAccessToRedirectsCreateThem(): void
    {
        $html = $this->notFound(self::REDIRECT_EDITOR);
        self::assertStringContainsString('redirectPath=/abuot&amp;redirectTarget=2', $this->row($html, '/abuot'));

        $this->render(self::REDIRECT_EDITOR, ['id' => '1', 'report' => 'notFound'], 'behaviour', ['redirectPath' => '/abuot', 'redirectTarget' => '2', 'createRedirect' => '1']);

        self::assertCount(1, $this->redirects('/abuot'));
    }

    #[Test]
    public function editorsWithoutWriteAccessToRedirectsOnlySeeSuggestions(): void
    {
        $html = $this->notFound(self::EDITOR);

        self::assertStringContainsString('About <code>/about</code>', $this->row($html, '/abuot'));
        self::assertStringContainsString('Creating redirects needs write access to redirects.', $html);
        self::assertStringNotContainsString('redirectPath', $html);
        self::assertStringNotContainsString('Edit redirect', $html);

        $this->render(self::EDITOR, ['id' => '1', 'report' => 'notFound'], 'behaviour', ['redirectPath' => '/abuot', 'redirectTarget' => '2', 'createRedirect' => '1']);
        self::assertSame([], $this->redirects('/abuot'));
        self::assertStringNotContainsString('Create this redirect?', $this->notFound(self::EDITOR, ['redirectPath' => '/abuot', 'redirectTarget' => '2']));
    }

    #[Test]
    public function editorsGetSuggestionsOnlyInTheirLanguages(): void
    {
        $html = $this->notFound(self::EDITOR);

        self::assertStringContainsString('No similar page', $this->row($html, '/de/ueber-un'));
    }

    /**
     * @param array<string, string> $query
     */
    private function notFound(int $userId, array $query = []): string
    {
        return (string)$this->render($userId, ['id' => '1', 'report' => 'notFound'] + $query, 'behaviour')->getBody();
    }

    /**
     * @return list<string>
     */
    private function listedPaths(string $html): array
    {
        preg_match_all('#<tr data-path="([^"]+)">#', $html, $matches);
        return $matches[1];
    }

    private function row(string $html, string $path): string
    {
        if (preg_match('#<tr data-path="' . preg_quote($path, '#') . '">.*?</tr>#s', $html, $match) !== 1) {
            self::fail('No row of ' . $path);
        }
        return $match[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function redirects(string $path): array
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('sys_redirect')
            ->select(['*'], 'sys_redirect', ['source_path' => $path, 'deleted' => 0])->fetchAllAssociative();
    }
}
