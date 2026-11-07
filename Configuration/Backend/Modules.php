<?php

declare(strict_types=1);

use MaikSchneider\AnalyticsMatomo\Controller\ReportModuleController;

$labels = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang_mod.xlf';
$modules = [
    'analytics_matomo' => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/analytics-matomo',
        'iconIdentifier' => 'module-analytics-matomo',
        'labels' => $labels,
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
    ],
];
foreach (['visitors' => 'overview', 'behaviour' => 'pages', 'acquisition' => 'channels', 'goals' => 'overview', 'page' => 'overview'] as $section => $report) {
    $modules['analytics_matomo_' . $section] = [
        'parent' => 'analytics_matomo',
        'access' => 'user',
        'path' => '/module/analytics-matomo/' . $section,
        'iconIdentifier' => 'module-analytics-matomo',
        'labels' => ['title' => $labels . ':section.' . $section],
        'routes' => ['_default' => ['target' => ReportModuleController::class . '::handleRequest']],
        'moduleData' => ['report' => $report, 'language' => -1],
    ];
}
return $modules;
