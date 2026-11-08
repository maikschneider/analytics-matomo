<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Digest;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use MaikSchneider\AnalyticsMatomo\Command\SendDigestCommand;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SendDigestCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['maikschneider/analytics-matomo'];
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/analytics_matomo/Tests/Functional/Fixtures/AnnotationSites' => 'typo3conf/sites',
    ];

    private string $mbox = '';

    /** @var list<string> idSite of every sub-request */
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Digest.csv');
        unset($GLOBALS['BE_USER']);
        $this->mbox = $this->instancePath . '/typo3temp/var/digest.mbox';
        @unlink($this->mbox);
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'mbox';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = $this->mbox;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'typo3@example.com';

        /** @var \DateTimeImmutable $now */
        $now = $this->get(Context::class)->getPropertyFromAspect('date', 'full');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['matomo'] = fn (callable $handler): \Closure => function (RequestInterface $request) use ($yesterday): FulfilledPromise {
            parse_str((string)$request->getBody(), $body);
            $results = [];
            foreach (is_array($body['urls'] ?? null) ? $body['urls'] : [] as $url) {
                parse_str((string)$url, $parameters);
                $this->sites[] = is_string($parameters['idSite'] ?? null) ? $parameters['idSite'] : '';
                $date = is_string($parameters['date'] ?? null) ? $parameters['date'] : '';
                $current = str_ends_with($date, $yesterday);
                $results[] = match (true) {
                    ($parameters['period'] ?? '') === 'day' => [],
                    ($parameters['method'] ?? '') === 'VisitsSummary.get' => ['nb_visits' => $current ? 120 : 100, 'bounce_rate' => '40%', 'avg_time_on_site' => 95],
                    ($parameters['method'] ?? '') === 'Actions.get' => ['nb_pageviews' => $current ? 300 : 250],
                    ($parameters['method'] ?? '') === 'Actions.getPageUrls' => $current ? [
                        ['label' => '/about', 'url' => 'https://example.com/about', 'nb_hits' => 50],
                        ['label' => '/board', 'url' => 'https://example.com/board', 'nb_hits' => 40],
                        ['label' => '/de/ueber-uns', 'url' => 'https://example.com/de/ueber-uns', 'nb_hits' => 35],
                        ['label' => '/about?session=secret', 'url' => 'https://example.com/about?session=secret#top', 'nb_hits' => 20],
                        ['label' => '/x', 'url' => 'https://other.example/x', 'nb_hits' => 30],
                    ] : [],
                    default => [],
                };
            }
            return new FulfilledPromise(new Response(200, [], (string)json_encode($results)));
        };
    }

    #[Test]
    public function mailsActiveMembersOfOptedInGroupsWithAnEmailOnly(): void
    {
        $command = $this->command();
        self::assertSame(0, $command->execute([]), $command->getDisplay());

        self::assertSame(['lea@example.com', 'rob@example.com'], array_keys($this->mails()), $command->getDisplay());
    }

    #[Test]
    public function aMailHasTheWeekOfTheSitesAndPagesTheRecipientMaySeeInTheirLanguage(): void
    {
        $this->command()->execute([]);
        $mails = $this->mails();

        $lea = $mails['lea@example.com'];
        self::assertStringContainsString('Subject: Matomo: Ihre Woche', $lea);
        self::assertStringContainsString('example.com', $lea);
        self::assertStringNotContainsString('shop.example.org', $lea);
        self::assertStringContainsString('120', $lea);
        self::assertStringContainsString('+20.0 %', $lea);
        self::assertStringContainsString('https://example.com/about', $lea);
        self::assertStringContainsString('- 70 About: https://example.com/about' . "\r\n", $lea);
        self::assertStringNotContainsString('secret', $lea);
        self::assertStringNotContainsString('/board', $lea);
        self::assertStringNotContainsString('ueber-uns', $lea);
        self::assertStringNotContainsString('other.example', $lea);
        self::assertStringContainsString('https://example.com/typo3/?redirect=analytics_matomo_visitors', $lea);

        $rob = $mails['rob@example.com'];
        self::assertStringContainsString('Subject: Matomo: Your week', $rob);
        self::assertStringContainsString('shop.example.org', $rob);
        self::assertStringContainsString('https://example.com/de/ueber-uns', $rob);
    }

    #[Test]
    public function aDryRunRendersWithoutSending(): void
    {
        $command = $this->command();
        self::assertSame(0, $command->execute(['--dry-run' => true]));

        self::assertFileDoesNotExist($this->mbox);
        $display = $command->getDisplay();
        self::assertStringContainsString('lea@example.com', $display);
        self::assertStringContainsString('rob@example.com', $display);
        self::assertStringContainsString('https://example.com/about', $display);
    }

    #[Test]
    public function aSiteIsAskedOnceForAllRecipients(): void
    {
        $this->command()->execute([]);

        self::assertSame(['3', '7'], array_values(array_unique($this->sites)));
        self::assertCount(count(array_keys($this->sites, '7', true)), array_keys($this->sites, '3', true));
    }

    private function command(): CommandTester
    {
        return new CommandTester($this->get(SendDigestCommand::class));
    }

    /**
     * @return array<string, string> decoded mails by recipient
     */
    private function mails(): array
    {
        self::assertFileExists($this->mbox);
        $mails = [];
        foreach (preg_split('/(?=^To: )/m', (string)file_get_contents($this->mbox), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $decoded = quoted_printable_decode(preg_replace_callback('/^Subject: (.*)$/m', static fn (array $match): string => 'Subject: ' . mb_decode_mimeheader($match[1]), $raw) ?? '');
            self::assertSame(1, preg_match('/^To: .*?([\w.]+@[\w.]+)/m', $decoded, $to));
            $mails[$to[1] ?? ''] = $decoded;
        }
        ksort($mails);
        return $mails;
    }
}
