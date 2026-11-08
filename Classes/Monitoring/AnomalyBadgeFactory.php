<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Monitoring;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final readonly class AnomalyBadgeFactory
{
    public function __construct(private AnomalyService $service, private ModuleProvider $modules, private UriBuilder $uriBuilder)
    {
    }

    /** @return array<string, mixed> */
    public function create(MatomoConfiguration $configuration, BackendUserAuthentication $user): array
    {
        $alerts = $this->service->getCached($configuration);
        foreach ($alerts as &$alert) {
            $alert['formattedChange'] = ($alert['change'] < 0 ? '−' : '+') . number_format(abs($alert['change']), 0) . ($alert['metric'] === 'visits' ? ' %' : ' pp');
        }
        unset($alert);
        $health = $this->modules->getModule('system_reports_status', $user) ?? $this->modules->getModule('system_reports', $user);
        return [
            'anomalies' => $alerts,
            'healthUrl' => $user->isAdmin() && $health !== null ? (string)$this->uriBuilder->buildUriFromRoute($health->getIdentifier(), ['report' => 'tx_reports_status']) : '',
        ];
    }
}
