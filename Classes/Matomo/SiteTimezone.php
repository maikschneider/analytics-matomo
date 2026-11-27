<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Matomo;

/**
 * The time zone of a Matomo site: Matomo's reports of a day run from midnight
 * to midnight in it.
 */
final class SiteTimezone
{
    /**
     * @param mixed $site answer of SitesManager.getSiteFromId
     * @return \DateTimeZone|null null when the answer has no time zone PHP understands
     */
    public static function fromSite(mixed $site): ?\DateTimeZone
    {
        $name = is_array($site) ? ($site['timezone'] ?? null) : null;
        if (!is_string($name)) {
            return null;
        }
        // Matomo also allows manual offsets like "UTC+5.5", which PHP doesn't know by name
        if (preg_match('/^UTC([+-])(\d{1,2})(?:\.(\d+))?$/', $name, $matches)) {
            $name = sprintf('%s%02d:%02d', $matches[1], (int)$matches[2], (int)round((float)('0.' . ($matches[3] ?? '0')) * 60));
        }
        try {
            return new \DateTimeZone($name);
        } catch (\Exception) {
            return null;
        }
    }
}
