<?php

declare(strict_types=1);

use MaikSchneider\AnalyticsMatomo\Controller\DashboardSelectionController;
use MaikSchneider\AnalyticsMatomo\Controller\StatisticsBarController;

return [
    'analytics_matomo_statistics_bar' => [
        'path' => '/analytics-matomo/statistics-bar',
        'target' => StatisticsBarController::class,
        'inheritAccessFromModule' => 'web_layout',
    ],
    'analytics_matomo_dashboard_selection' => [
        'path' => '/analytics-matomo/dashboard-selection',
        'target' => DashboardSelectionController::class,
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'dashboard',
    ],
];
