<?php

declare(strict_types=1);

return [
    'dependencies' => ['core', 'backend'],
    'imports' => [
        '@maikschneider/analytics-matomo/' => 'EXT:analytics_matomo/Resources/Public/JavaScript/',
    ],
];
