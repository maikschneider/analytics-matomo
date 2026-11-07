<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Authorization;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Custom options of backend groups (be_groups.custom_options). Admins have all of them.
 */
final class StatisticsPermission
{
    public const PAGE = 'pageStatistics';
    public const SITE = 'siteStatistics';
    public const SITE_SEARCH = 'siteSearch';

    public static function isGranted(BackendUserAuthentication $backendUser, string $option): bool
    {
        return $backendUser->check('custom_options', 'analytics_matomo:' . $option);
    }
}
