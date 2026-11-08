<?php

use MaikSchneider\AnalyticsMatomo\Annotation\ContentChangeHook;
use MaikSchneider\AnalyticsMatomo\Authorization\StatisticsPermission;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']['options']['defaultLifetime'] ??= 3600;
// Cached reports follow the structure of the code, so a deployment's cache:flush has to clear them
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']['groups'] ??= ['system'];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['analytics_matomo'] = ContentChangeHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['analytics_matomo'] = ContentChangeHook::class;

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
        StatisticsPermission::CONTENT_REVIEW => [
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.contentReview',
            'actions-document-history-open',
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.contentReview.description',
        ],
        StatisticsPermission::WEEKLY_DIGEST => [
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.weeklyDigest',
            'actions-envelope',
            'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:permission.weeklyDigest.description',
        ],
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'][1762560000] = 'EXT:analytics_matomo/Resources/Private/Templates/Email/';
