<?php

declare(strict_types=1);

return [
    'dependencies' => ['core', 'backend', 'dashboard'],
    'imports' => [
        '@maikschneider/analytics-matomo/evolution-chart.js' => 'EXT:analytics_matomo/Resources/Public/JavaScript/evolution-chart.js',
        '@maikschneider/analytics-matomo/' => 'EXT:analytics_matomo/Resources/Public/JavaScript/',
    ],
];
