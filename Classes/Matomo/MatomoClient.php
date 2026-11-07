<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Matomo;

use GuzzleHttp\Exception\GuzzleException;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Sends several Matomo Reporting API calls in one HTTP request (API.getBulkRequest).
 * The token goes into the POST body, as Matomo recommends, never into the URL.
 */
final readonly class MatomoClient
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private RequestFactory $requestFactory,
        private DemoData $demoData = new DemoData(),
    ) {
    }

    /**
     * @param list<array<string, int|string>> $requests API parameters per call, e.g. ['method' => 'Actions.getPageUrl', ...]
     * @param string $language language of translated labels, e.g. 'de'; Matomo ignores it inside the single requests
     * @return list<mixed> one decoded result per request, in the same order
     * @throws MatomoApiException
     */
    public function bulkRequest(MatomoConfiguration $configuration, array $requests, string $language = ''): array
    {
        if ($configuration->demo) {
            return $this->demoData->results($requests, $configuration);
        }
        $body = [
            'module' => 'API',
            'method' => 'API.getBulkRequest',
            'format' => 'json',
            'urls' => array_map(
                static fn (array $request): string => http_build_query($request + ['idSite' => $configuration->idSite] + ($configuration->segment === '' ? [] : ['segment' => $configuration->segment])),
                $requests
            ),
        ];
        if ($language !== '') {
            $body['language'] = $language;
        }
        if ($configuration->tokenAuth !== '') {
            $body['token_auth'] = $configuration->tokenAuth;
        }

        try {
            $response = $this->requestFactory->request($configuration->getApiUrl(), 'POST', [
                'form_params' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
                // A 307/308 redirect would resend the token to the redirect target
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException $e) {
            // Without the original exception as previous: the redacted message is all that may be logged
            throw new MatomoApiException('Matomo is not reachable: ' . $this->redact($e->getMessage(), $configuration), 1793960001);
        }

        // Error messages end up in logs and in the backend, so they must never show the token
        $content = $this->redact((string)$response->getBody(), $configuration);
        if ($response->getStatusCode() !== 200) {
            throw new MatomoApiException(sprintf('Matomo answered with HTTP %d: %s', $response->getStatusCode(), $this->errorMessage($content)), 1793960002);
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new MatomoApiException('Matomo answered with invalid JSON: ' . mb_substr($content, 0, 200), 1793960003);
        }
        $this->assertNoError($data);
        foreach ($data as $result) {
            if (is_array($result)) {
                $this->assertNoError($result);
            }
        }
        if (!array_is_list($data) || count($data) !== count($requests)) {
            throw new MatomoApiException('Matomo answered with an unexpected number of results', 1793960004);
        }

        return $data;
    }

    /**
     * @param array<mixed> $result
     */
    private function assertNoError(array $result): void
    {
        if (($result['result'] ?? null) === 'error') {
            throw new MatomoApiException('Matomo API error: ' . (string)($result['message'] ?? 'unknown'), 1793960005);
        }
    }

    private function redact(string $text, MatomoConfiguration $configuration): string
    {
        return $configuration->tokenAuth === '' ? $text : str_replace($configuration->tokenAuth, '***', $text);
    }

    private function errorMessage(string $content): string
    {
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        return mb_substr(strip_tags($content), 0, 200);
    }
}
