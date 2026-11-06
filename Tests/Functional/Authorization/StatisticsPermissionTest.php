<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Functional\Authorization;

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class StatisticsPermissionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'dashboard',
    ];

    protected array $testExtensionsToLoad = [
        'maikschneider/analytics-matomo',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database.csv');
    }

    #[Test]
    public function offersBothOptionsInBackendGroups(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
        $fieldDefinition = ['items' => []];

        $this->get(TcaItemsProcessorFunctions::class)->populateCustomPermissionOptions($fieldDefinition);

        $values = array_column($fieldDefinition['items'], 'value');
        self::assertContains('analytics_matomo:pageStatistics', $values);
        self::assertContains('analytics_matomo:siteStatistics', $values);
    }

    #[Test]
    public function grantsOnlyTheOptionsOfTheGroups(): void
    {
        $editor = $this->setUpBackendUser(2);

        self::assertTrue(StatisticsPermission::isGranted($editor, StatisticsPermission::PAGE));
        self::assertFalse(StatisticsPermission::isGranted($editor, StatisticsPermission::SITE));
    }

    #[Test]
    public function grantsEverythingToAdmins(): void
    {
        $admin = $this->setUpBackendUser(1);

        self::assertTrue(StatisticsPermission::isGranted($admin, StatisticsPermission::PAGE));
        self::assertTrue(StatisticsPermission::isGranted($admin, StatisticsPermission::SITE));
    }
}
