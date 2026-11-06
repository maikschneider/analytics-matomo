<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Reads the Matomo connection of a site from its site settings. Without an own
 * URL, URL and site ID are taken together from the first of EXT:matomo_integration,
 * EXT:matomo_widgets and EXT:matomo_tracking that has a URL, so sites using one of
 * them don't have to configure Matomo twice. The own token is used with any of
 * them; the token of EXT:matomo_widgets only with its own URL, so it is never
 * sent to another server.
 */
final readonly class MatomoConfigurationResolver
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function resolve(Site $site): ?MatomoConfiguration
    {
        $settings = $site->getSettings();
        $configuration = $site->getConfiguration();
        $tokenAuth = $this->value($settings->get('analyticsMatomo.tokenAuth'));

        $connections = [
            [$settings->get('analyticsMatomo.url'), $settings->get('analyticsMatomo.idSite'), null],
            [$configuration['matomoIntegrationUrl'] ?? null, $configuration['matomoIntegrationSiteId'] ?? null, null],
            [$configuration['matomoWidgetsUrl'] ?? null, $configuration['matomoWidgetsIdSite'] ?? null, $configuration['matomoWidgetsTokenAuth'] ?? null],
            [$this->getMatomoTrackingUrl(), $configuration['matomoTrackingSiteId'] ?? null, null],
        ];
        foreach ($connections as [$url, $idSite, $connectionTokenAuth]) {
            $url = $this->value($url);
            if ($url === '') {
                continue;
            }
            $idSite = (int)$this->value($idSite);
            if ($idSite <= 0) {
                return null;
            }
            return new MatomoConfiguration($url, $idSite, $tokenAuth !== '' ? $tokenAuth : $this->value($connectionTokenAuth));
        }

        return null;
    }

    private function value(mixed $value): string
    {
        return is_scalar($value) && (string)$value !== '0' ? trim((string)$value) : '';
    }

    private function getMatomoTrackingUrl(): mixed
    {
        try {
            return $this->extensionConfiguration->get('matomo_tracking', 'matomoUrl');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return null;
        }
    }
}
