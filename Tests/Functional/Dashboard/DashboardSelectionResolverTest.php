<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Dashboard;

use MaikSchneider\AnalyticsMatomo\Dashboard\DashboardSelectionResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DashboardSelectionResolverTest extends FunctionalTestCase
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
        putenv('ANALYTICS_MATOMO_TEST_TOKEN=secret-token');
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-11-06 14:30:00')));
    }

    protected function tearDown(): void
    {
        putenv('ANALYTICS_MATOMO_TEST_TOKEN');
        parent::tearDown();
    }

    #[Test]
    public function offersConfiguredSitesWhoseRootPageTheUserMaySee(): void
    {
        self::assertSame(['main', 'shop', 'trial'], array_keys($this->get(DashboardSelectionResolver::class)->getSites($this->setUpBackendUser(1))));
        self::assertSame(['main', 'shop'], array_keys($this->get(DashboardSelectionResolver::class)->getSites($this->setUpBackendUser(6))));
        self::assertSame(['main'], array_keys($this->get(DashboardSelectionResolver::class)->getSites($this->setUpBackendUser(5))));
    }

    #[Test]
    public function offersNothingWithoutSiteStatisticsPermission(): void
    {
        $user = $this->setUpBackendUser(4);

        self::assertSame([], $this->get(DashboardSelectionResolver::class)->getSites($user));
        self::assertNull($this->get(DashboardSelectionResolver::class)->resolve($user));
    }

    #[Test]
    public function resolveDefaultsToTheFirstSiteAndSevenDays(): void
    {
        $selection = $this->get(DashboardSelectionResolver::class)->resolve($this->setUpBackendUser(1));

        self::assertNotNull($selection);
        self::assertSame('main', $selection->site->getIdentifier());
        self::assertSame(3, $selection->configuration->idSite);
        self::assertSame('2026-10-31,2026-11-06', $selection->period->toMatomoDate());
        self::assertCount(3, $selection->sites);
    }

    #[Test]
    public function saveStoresTheSelectionOfTheUser(): void
    {
        $user = $this->setUpBackendUser(1);
        $subject = $this->get(DashboardSelectionResolver::class);

        self::assertTrue($subject->save($user, 'shop', 30));
        $selection = $subject->resolve($user);

        self::assertNotNull($selection);
        self::assertSame('shop', $selection->site->getIdentifier());
        self::assertSame(30, $selection->period->days);
    }

    #[Test]
    public function saveRejectsSitesTheUserMayNotSee(): void
    {
        $user = $this->setUpBackendUser(5);
        $subject = $this->get(DashboardSelectionResolver::class);

        self::assertFalse($subject->save($user, 'shop', 14));
        self::assertFalse($subject->save($user, 'unconfigured', 14));
        self::assertSame('main', $subject->resolve($user)?->site->getIdentifier());
    }

    #[Test]
    public function resolveFallsBackWhenTheStoredSiteIsGone(): void
    {
        $user = $this->setUpBackendUser(1);
        $user->uc['analytics_matomo']['dashboard'] = ['site' => 'removed', 'days' => 365];

        $selection = $this->get(DashboardSelectionResolver::class)->resolve($user);

        self::assertNotNull($selection);
        self::assertSame('main', $selection->site->getIdentifier());
        self::assertSame(7, $selection->period->days);
    }
}
