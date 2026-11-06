<?php

declare(strict_types=1);

use MaikSchneider\AnalyticsMatomo\Controller\StatisticsBarController;

return [
    'analytics_matomo_statistics_bar' => [
        'path' => '/analytics-matomo/statistics-bar',
        'target' => StatisticsBarController::class,
        'inheritAccessFromModule' => 'web_layout',
    ],
];
