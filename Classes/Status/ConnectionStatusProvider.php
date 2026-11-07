<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Status;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

/**
 * One status per site in the Reports module (EXT:reports is optional).
 */
final readonly class ConnectionStatusProvider implements StatusProviderInterface
{
    private const LL = 'LLL:EXT:analytics_matomo/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private SiteFinder $siteFinder,
        private MatomoConfigurationResolver $configurationResolver,
        private ConnectionCheck $connectionCheck,
    ) {
    }

    public function getLabel(): string
    {
        return 'Matomo Analytics';
    }

    /**
     * @return array<string, Status> by site identifier
     */
    public function getStatus(): array
    {
        $sites = $this->siteFinder->getAllSites(false);
        $configurations = [];
        $expected = [];
        foreach ($sites as $identifier => $site) {
            $configuration = $this->configurationResolver->resolve($site);
            $configurations[$identifier] = $configuration;
            if ($configuration !== null) {
                $expected[$this->connection((string)$identifier, $configuration)][] = $configuration->idSite;
            }
        }

        $statuses = [];
        foreach ($sites as $identifier => $site) {
            $title = $identifier . ' (' . $site->getBase() . ')';
            $configuration = $configurations[$identifier];
            if ($configuration === null) {
                $statuses[$identifier] = new Status($title, $this->label('status.notConfigured'), '', ContextualFeedbackSeverity::INFO);
                continue;
            }
            $maxHours = $site->getSettings()->get('analyticsMatomo.statusMaxHoursWithoutVisits', 24);
            $result = $this->connectionCheck->check($site, $configuration, $expected[$this->connection((string)$identifier, $configuration)], is_numeric($maxHours) ? (int)$maxHours : 24);
            $severity = null;
            $items = [];
            foreach ($result['findings'] as $finding) {
                $severity = $severity === null || $finding['severity']->value > $severity->value ? $finding['severity'] : $severity;
                $items[] = '<li>' . htmlspecialchars(vsprintf($this->label('status.' . $finding['key']), $finding['arguments'])) . '</li>';
            }
            $value = $result['version'] === '' ? $this->label('status.unreachable') : sprintf($this->label('status.version'), $result['version']);
            $message = $items === [] ? htmlspecialchars(sprintf($this->label('status.healthy'), $configuration->idSite)) : '<ul>' . implode('', $items) . '</ul>';
            $statuses[$identifier] = new Status($title, $value, $message, $severity ?? ContextualFeedbackSeverity::OK);
        }
        return $statuses;
    }

    private function connection(string $identifier, MatomoConfiguration $configuration): string
    {
        // Demo data are generated per site, so demo sites never share a token
        return hash('sha256', implode("\n", [rtrim($configuration->url, '/'), $configuration->tokenAuth, $configuration->demo ? 'demo' . $identifier : '']));
    }

    private function label(string $key): string
    {
        return $this->getLanguageService()->sL(self::LL . $key);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
