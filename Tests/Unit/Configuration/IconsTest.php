<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class IconsTest extends UnitTestCase
{
    #[Test]
    public function moduleIconFollowsTheCoreModuleIconStyle(): void
    {
        $icons = require __DIR__ . '/../../../Configuration/Icons.php';
        $source = (string)($icons['module-analytics-matomo']['source'] ?? '');
        self::assertStringEndsWith('/Module.svg', $source);

        $svg = (string)file_get_contents(__DIR__ . '/../../../Resources/Public/Icons/Module.svg');
        $root = (string)strstr($svg, '>', true);
        self::assertStringContainsString('viewBox="0 0 64 64"', $root);
        self::assertStringNotContainsString('width=', $root);
        self::assertStringNotContainsString('height=', $root);
        self::assertStringContainsString('currentColor', $svg);
        self::assertStringContainsString('var(--icon-color-accent, #ff8700)', $svg);
        self::assertStringNotContainsString('#000', $svg);
        self::assertFileExists(__DIR__ . '/../../../Resources/Public/Icons/Extension.svg');
    }
}
