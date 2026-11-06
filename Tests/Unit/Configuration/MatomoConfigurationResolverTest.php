<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Configuration;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MatomoConfigurationResolverTest extends UnitTestCase
{
    #[Test]
    public function siteSettingsWin(): void
    {
        $site = new Site('main', 1, [
            'settings' => ['analyticsMatomo' => ['url' => 'https://own.example.com', 'idSite' => 7, 'tokenAuth' => 'own-token']],
            'matomoIntegrationUrl' => 'https://integration.example.com',
            'matomoIntegrationSiteId' => '3',
            'matomoWidgetsTokenAuth' => 'widgets-token',
        ]);

        self::assertEquals(
            new MatomoConfiguration('https://own.example.com', 7, 'own-token'),
            $this->subject()->resolve($site)
        );
    }

    #[Test]
    public function urlAndSiteIdFallBackToMatomoIntegrationWithOwnToken(): void
    {
        $site = new Site('main', 1, [
            'settings' => ['analyticsMatomo' => ['tokenAuth' => 'own-token']],
            'matomoIntegrationUrl' => 'https://integration.example.com',
            'matomoIntegrationSiteId' => '3',
        ]);

        self::assertEquals(
            new MatomoConfiguration('https://integration.example.com', 3, 'own-token'),
            $this->subject()->resolve($site)
        );
    }

    #[Test]
    public function widgetsTokenIsNeverSentToAnotherMatomo(): void
    {
        $site = new Site('main', 1, [
            'matomoIntegrationUrl' => 'https://integration.example.com',
            'matomoIntegrationSiteId' => '3',
            'matomoWidgetsUrl' => 'https://widgets.example.com',
            'matomoWidgetsIdSite' => '4',
            'matomoWidgetsTokenAuth' => 'widgets-token',
        ]);

        self::assertEquals(
            new MatomoConfiguration('https://integration.example.com', 3, ''),
            $this->subject()->resolve($site)
        );
    }

    #[Test]
    public function widgetsConnectionIncludesItsToken(): void
    {
        $site = new Site('main', 1, [
            'matomoWidgetsUrl' => 'https://widgets.example.com',
            'matomoWidgetsIdSite' => '4',
            'matomoWidgetsTokenAuth' => 'widgets-token',
        ]);

        self::assertEquals(
            new MatomoConfiguration('https://widgets.example.com', 4, 'widgets-token'),
            $this->subject()->resolve($site)
        );
    }

    #[Test]
    public function urlAndSiteIdFallBackToMatomoTracking(): void
    {
        $site = new Site('main', 1, ['matomoTrackingSiteId' => '12']);

        self::assertEquals(
            new MatomoConfiguration('https://tracking.example.com/', 12, ''),
            $this->subject('https://tracking.example.com/')->resolve($site)
        );
    }

    #[Test]
    public function siteWithoutUrlOrSiteIdHasNoConfiguration(): void
    {
        self::assertNull($this->subject()->resolve(new Site('main', 1, ['matomoIntegrationSiteId' => '3'])));
        self::assertNull($this->subject()->resolve(new Site('main', 1, ['matomoIntegrationUrl' => 'https://integration.example.com'])));
        // An incomplete own connection must not be completed with the site ID of another Matomo
        self::assertNull($this->subject()->resolve(new Site('main', 1, [
            'settings' => ['analyticsMatomo' => ['url' => 'https://own.example.com']],
            'matomoIntegrationUrl' => 'https://integration.example.com',
            'matomoIntegrationSiteId' => '3',
        ])));
    }

    private function subject(?string $matomoTrackingUrl = null): MatomoConfigurationResolver
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        if ($matomoTrackingUrl === null) {
            $extensionConfiguration->method('get')->willThrowException(new ExtensionConfigurationExtensionNotConfiguredException('', 1));
        } else {
            $extensionConfiguration->method('get')->with('matomo_tracking', 'matomoUrl')->willReturn($matomoTrackingUrl);
        }
        return new MatomoConfigurationResolver($extensionConfiguration);
    }
}
