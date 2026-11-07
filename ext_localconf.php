<?php

use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']['options']['defaultLifetime'] ??= 3600;
// Cached reports follow the structure of the code, so a deployment's cache:flush has to clear them
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']['groups'] ??= ['system'];

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['analytics_matomo'] = [
    'header' => 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.header',
    'items' => [
        StatisticsPermission::PAGE => [
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.pageStatistics',
            'actions-eye',
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.pageStatistics.description',
        ],
        StatisticsPermission::SITE => [
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.siteStatistics',
            'content-dashboard',
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.siteStatistics.description',
        ],
        StatisticsPermission::SITE_SEARCH => [
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.siteSearch',
            'actions-search',
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.siteSearch.description',
        ],
    ],
];
