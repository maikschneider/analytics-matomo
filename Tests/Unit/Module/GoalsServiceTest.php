<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Tests\Unit\Module;

use MaikSchneider\AnalyticsMatomo\Module\GoalsService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class GoalsServiceTest extends UnitTestCase
{
    #[Test]
    public function channelSharesStayWithinTheGoalsConversionsWhereConversionsExceedVisits(): void
    {
        $recorded = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/Matomo/GoalChannelsAttribution.json'), true, flags: JSON_THROW_ON_ERROR);
        $rows = array_column(GoalsService::channelRows($recorded, 2), null, 'label');

        // Matomo credits 6 conversions to the campaign channel of 2 visits
        self::assertSame([6, 2], [$rows['Campaigns']['nb_conversions'], $rows['Campaigns']['nb_visits']]);
        self::assertEqualsWithDelta(6 / 91, $rows['Campaigns']['conversion_share'], 0.0001);
        self::assertEqualsWithDelta(54 / 91, $rows['Direct Entry']['conversion_share'], 0.0001);
        self::assertEqualsWithDelta(1.0, array_sum(array_column($rows, 'conversion_share')), 0.0001);
        foreach ($rows as $label => $row) {
            self::assertLessThanOrEqual(1, $row['conversion_share'], $label);
            self::assertArrayNotHasKey('conversion_rate', $row, $label);
        }
    }

    #[Test]
    public function channelsWithoutConversionsHaveNoShare(): void
    {
        self::assertSame(0, GoalsService::channelRows([['label' => 'Websites', 'nb_visits' => 3, 'goals' => []]], 1)[0]['conversion_share']);
    }
}
