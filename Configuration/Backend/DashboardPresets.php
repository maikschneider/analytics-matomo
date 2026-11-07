<?php

declare(strict_types=1);

return [
    'analyticsMatomoOverview' => [
        'title' => 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:widget.preset.title',
        'description' => 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:widget.preset.description',
        'iconIdentifier' => 'content-widget-chart',
        'defaultWidgets' => [
            'analyticsMatomoPerformance',
            'analyticsMatomoTraffic',
            'analyticsMatomoTopPages',
            'analyticsMatomoSites',
        ],
        'showInWizard' => true,
    ],
];
