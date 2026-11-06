<?php

declare(strict_types=1);

return [
    'dependencies' => ['core', 'backend', 'dashboard'],
    'imports' => [
        '@maikschneider/analytics-matomo/' => 'EXT:analytics_matomo/Resources/Public/JavaScript/',
    ],
];
