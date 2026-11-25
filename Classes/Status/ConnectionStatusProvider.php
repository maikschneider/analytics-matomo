<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Status;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\Day;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\UrlPageMapper;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
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
        private ConnectionRepository $connections,
        private ImportRepository $imports,
        private UrlPageMapper $urlPageMapper,
        private Context $context,
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
            $message = $items === [] ? htmlspecialchars(sprintf($this->label('status.healthy'), $configuration->idSite)) : '';
            foreach ($this->pageViewStore($site, $configuration, $result['timezone']) as $line) {
                $severity = $severity === null || $line['severity']->value > $severity->value ? $line['severity'] : $severity;
                $items[] = '<li>' . htmlspecialchars($line['text']) . '</li>';
            }
            $message .= $items === [] ? '' : '<ul>' . implode('', $items) . '</ul>';
            $statuses[$identifier] = new Status($title, $value, $message, $severity ?? ContextualFeedbackSeverity::OK);
        }
        return $statuses;
    }

    /**
     * Stored range, last import and truncated days of the page view store of
     * the site's Matomo site; nothing when it has no store. Warns when today
     * was not imported recently or the site's URLs are being mapped again.
     *
     * @param ?\DateTimeZone $siteTimezone of the Matomo site, null if unknown
     * @return list<array{text: string, severity: ContextualFeedbackSeverity}>
     */
    private function pageViewStore(Site $site, MatomoConfiguration $configuration, ?\DateTimeZone $siteTimezone): array
    {
        if ($configuration->demo) {
            return [];
        }
        $connection = $this->connections->find($configuration);
        $overview = $connection === null ? null : $this->imports->overview($connection);
        if ($connection === null || $overview === null) {
            return [];
        }

        $now = $this->context->getPropertyFromAspect('date', 'full');
        \assert($now instanceof \DateTimeImmutable);
        $timezone = $now->getTimezone();
        $lastImport = (new \DateTimeImmutable('@' . $overview->lastImportedAt))->setTimezone($timezone);
        // The stored days are the Matomo site's
        $siteNow = $siteTimezone === null ? $now : $now->setTimezone($siteTimezone);
        $today = Day::fromDate($siteNow);
        $isStale = $this->imports->coverage($connection, $today, $today, $siteNow)->isStale;
        $lines = [[
            'text' => sprintf(
                $this->label('status.pageViewStore'),
                Day::toDate($overview->oldestDay, $timezone)->format('d.m.Y'),
                Day::toDate($overview->newestDay, $timezone)->format('d.m.Y'),
                $lastImport->format(Day::fromDate($lastImport) === Day::fromDate($now) ? 'H:i' : 'd.m.Y H:i'),
                $overview->truncatedDays,
            ),
            'severity' => $isStale ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK,
        ]];
        if ($isStale) {
            $lines[] = ['text' => sprintf($this->label('status.pageViewStoreStale'), intdiv(ImportRepository::STALE_AFTER, 3600)), 'severity' => ContextualFeedbackSeverity::WARNING];
        }
        if ($this->urlPageMapper->isRemapPending($site, $connection)) {
            $lines[] = ['text' => $this->label('status.pageViewStoreRemap'), 'severity' => ContextualFeedbackSeverity::WARNING];
        }
        return $lines;
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
