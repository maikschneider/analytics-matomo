<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Matomo;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MatomoClientTest extends UnitTestCase
{
    private RequestFactory&MockObject $requestFactory;

    private MatomoClient $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->subject = new MatomoClient($this->requestFactory);
    }

    #[Test]
    public function bulkRequestPostsAllCallsWithTokenInBody(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                'https://matomo.example.com/index.php',
                'POST',
                self::callback(static function (array $options): bool {
                    self::assertSame([
                        'module' => 'API',
                        'method' => 'API.getBulkRequest',
                        'format' => 'json',
                        'urls' => [
                            'method=Actions.getPageUrl&pageUrl=https%3A%2F%2Fexample.com%2F&idSite=3',
                            'method=API.getMatomoVersion&idSite=3',
                        ],
                        'token_auth' => 'secret',
                    ], $options['form_params']);
                    return true;
                })
            )
            ->willReturn($this->response(200, '[[{"nb_hits":5}],"6.0.0"]'));

        $result = $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com/', 3, 'secret'), [
            ['method' => 'Actions.getPageUrl', 'pageUrl' => 'https://example.com/'],
            ['method' => 'API.getMatomoVersion'],
        ]);

        self::assertSame([[['nb_hits' => 5]], '6.0.0'], $result);
    }

    #[Test]
    public function bulkRequestOmitsEmptyToken(): void
    {
        $this->requestFactory->method('request')
            ->with(self::anything(), self::anything(), self::callback(
                static fn (array $options): bool => !array_key_exists('token_auth', $options['form_params'])
            ))
            ->willReturn($this->response(200, '["6.0.0"]'));

        $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, ''), [['method' => 'API.getMatomoVersion']]);
    }

    #[Test]
    public function bulkRequestSendsTheSegmentWithEveryCall(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(self::anything(), self::anything(), self::callback(
                static fn (array $options): bool => $options['form_params']['urls'] === [
                    'method=API.get&idSite=1&segment=deviceType%3D%3DSmartphone%2CdeviceType%3D%3DTablet',
                    'method=Live.getCounters&idSite=1&segment=deviceType%3D%3DSmartphone%2CdeviceType%3D%3DTablet',
                ]
            ))
            ->willReturn($this->response(200, '[[],[]]'));

        $configuration = (new MatomoConfiguration('https://matomo.example.com', 1, ''))->withSegment('deviceType==Smartphone,deviceType==Tablet');
        $this->subject->bulkRequest($configuration, [['method' => 'API.get'], ['method' => 'Live.getCounters']]);
    }

    #[Test]
    public function segmentSeparatesCachedData(): void
    {
        $configuration = new MatomoConfiguration('https://matomo.example.com', 1, 'secret');

        self::assertNotSame($configuration->getCacheIdentifier(), $configuration->withSegment('visitorType==new')->getCacheIdentifier());
        self::assertSame($configuration->getCacheIdentifier(), $configuration->withSegment('')->getCacheIdentifier());
    }

    #[Test]
    public function bulkRequestSendsTheLanguageWithTheOuterRequest(): void
    {
        // Matomo ignores `language` inside the single URLs of a bulk request
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(self::anything(), self::anything(), self::callback(
                static fn (array $options): bool => $options['form_params']['language'] === 'de'
                    && $options['form_params']['urls'] === ['method=Referrers.getReferrerType&idSite=1']
            ))
            ->willReturn($this->response(200, '[[]]'));

        $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, ''), [['method' => 'Referrers.getReferrerType']], 'de');
    }

    #[Test]
    public function bulkRequestDoesNotFollowRedirects(): void
    {
        // A 307/308 redirect would resend the token in the body to the redirect target
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(self::anything(), self::anything(), self::callback(
                static fn (array $options): bool => $options['allow_redirects'] === false
            ))
            ->willReturn($this->response(200, '["6.0.0"]'));

        $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, 'secret'), [['method' => 'API.getMatomoVersion']]);
    }

    #[Test]
    public function errorMessagesNeverContainTheToken(): void
    {
        $this->requestFactory->method('request')
            ->willReturn($this->response(500, '{"result":"error","message":"Token abc123token is not valid"}'));

        try {
            $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, 'abc123token'), [['method' => 'API.getMatomoVersion']]);
            self::fail('Expected a MatomoApiException');
        } catch (MatomoApiException $e) {
            self::assertSame('Matomo answered with HTTP 500: Token *** is not valid', $e->getMessage());
        }
    }

    #[Test]
    public function bulkRequestThrowsOnErrorInSubResult(): void
    {
        $this->requestFactory->method('request')
            ->willReturn($this->response(200, '[{"result":"error","message":"You can\'t access this resource"}]'));

        $this->expectException(MatomoApiException::class);
        $this->expectExceptionMessage('You can\'t access this resource');

        $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, ''), [['method' => 'API.getMatomoVersion']]);
    }

    #[Test]
    public function bulkRequestKeepsErrorsOfSingleCallsOnRequest(): void
    {
        $this->requestFactory->method('request')
            ->willReturn($this->response(200, (string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/AllSitesNoViewAccess.json')));

        $results = $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, ''), [['method' => 'API.get', 'idSite' => 3], ['method' => 'API.get', 'idSite' => 1]], keepErrors: true);

        self::assertSame(29, $results[0]['nb_visits'] ?? null);
        self::assertSame('error', $results[1]['result'] ?? null);
    }

    #[Test]
    public function bulkRequestThrowsOnHttpError(): void
    {
        $this->requestFactory->method('request')
            ->willReturn($this->response(401, '{"result":"error","message":"token_auth is not valid"}'));

        $this->expectException(MatomoApiException::class);
        $this->expectExceptionMessage('HTTP 401: token_auth is not valid');

        $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, 'wrong'), [['method' => 'API.getMatomoVersion']]);
    }

    #[Test]
    public function bulkRequestThrowsOnInvalidJson(): void
    {
        $this->requestFactory->method('request')->willReturn($this->response(200, '<html>Maintenance</html>'));

        $this->expectException(MatomoApiException::class);
        $this->expectExceptionCode(1793960003);

        $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, ''), [['method' => 'API.getMatomoVersion']]);
    }

    #[Test]
    public function bulkRequestThrowsOnUnexpectedNumberOfResults(): void
    {
        $this->requestFactory->method('request')->willReturn($this->response(200, '["6.0.0"]'));

        $this->expectException(MatomoApiException::class);
        $this->expectExceptionCode(1793960004);

        $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, ''), [
            ['method' => 'API.getMatomoVersion'],
            ['method' => 'API.getMatomoVersion'],
        ]);
    }

    #[Test]
    public function bulkRequestWrapsConnectionErrors(): void
    {
        $this->requestFactory->method('request')
            ->willThrowException(new ConnectException('Connection refused', new Request('POST', 'https://matomo.example.com/index.php')));

        $this->expectException(MatomoApiException::class);
        $this->expectExceptionCode(1793960001);

        $this->subject->bulkRequest(new MatomoConfiguration('https://matomo.example.com', 1, ''), [['method' => 'API.getMatomoVersion']]);
    }

    private function response(int $status, string $body): Response
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();
        return new Response($stream, $status);
    }
}
