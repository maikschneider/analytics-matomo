<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Command;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\Day;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportResult;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewImporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Imports Matomo's page report per day into the page view store, for every
 * Matomo site (connection) of the configured TYPO3 sites.
 *
 * The request budget is shared by all connections: first every connection
 * gets its two newest due days (today and, until final, yesterday), then the
 * rest of the budget goes to the backfill, connection by connection. So a
 * long backfill of one Matomo site never keeps another one from being current.
 * After the imports each connection purges the days beyond the retention and
 * goes on with pending re-maps of its sites, within the shared --max-remap.
 *
 * An error of one connection is reported and fails the run; the other
 * connections are imported anyway.
 */
final class ImportPageViewsCommand extends Command
{
    /**
     * Days per connection imported before any connection gets more
     */
    private const FIRST_PASS_DAYS = 2;

    private const LOCK_CAPABILITIES = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly MatomoConfigurationResolver $resolver,
        private readonly ConnectionRepository $connections,
        private readonly PageViewImporter $importer,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /**
     * Key of the lock an import of the connection holds.
     */
    public static function lockKey(int $connection): string
    {
        return 'analytics_matomo_import_' . $connection;
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Import only the Matomo site of this TYPO3 site identifier')
            ->addOption('max-requests', null, InputOption::VALUE_REQUIRED, 'Matomo requests (one per day) of this run across all Matomo sites; today and yesterday of every Matomo site come first, a backfill spreads over runs', '50')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds per request', '120')
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Days kept per Matomo site, today included; older days are deleted. At most the report retention of Matomo (delete_reports_older_than)', '365')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Import the days from this day (Y-m-d) again, e.g. after Matomo reprocessed its logs; without --to up to today')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Import the days up to this day (Y-m-d) again; without --from this day only')
            ->addOption('max-remap', null, InputOption::VALUE_REQUIRED, 'Stored URLs of this run across all sites mapped again to the pages of a TYPO3 site that is new to its Matomo site or whose base or languages changed; a re-map spreads over runs', '20000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $budget = $this->integerOption($input, 'max-requests', 0);
        $timeout = $this->integerOption($input, 'timeout', 1);
        $retentionDays = $this->integerOption($input, 'retention-days', 1);
        $remapBudget = $this->integerOption($input, 'max-remap', 0);
        $from = $this->dayOption($input, 'from');
        $to = $this->dayOption($input, 'to');
        if ($budget === null || $timeout === null || $retentionDays === null || $remapBudget === null || $from === false || $to === false) {
            $output->writeln('<error>--max-requests and --max-remap must be 0 or more, --timeout and --retention-days 1 or more, --from and --to days as Y-m-d</error>');
            return Command::INVALID;
        }
        $range = null;
        if ($from !== null || $to !== null) {
            // Without --to up to today (the importer stops at today), without --from the day of --to
            $range = $to === null ? ['from' => (int)$from, 'to' => 99991231] : ['from' => $from ?? $to, 'to' => $to];
            if ($range['from'] > $range['to']) {
                $output->writeln('<error>--from is after --to</error>');
                return Command::INVALID;
            }
        }

        $identifier = $input->getOption('site');
        try {
            $groups = $this->groups(is_string($identifier) ? $identifier : null);
        } catch (SiteNotFoundException) {
            $output->writeln('<error>Unknown site ' . $identifier . '</error>');
            return Command::INVALID;
        }
        if ($groups === []) {
            $output->writeln('No Matomo site to import: demo sites and sites without Matomo configuration are not stored');
            return Command::SUCCESS;
        }
        $lockers = [];
        /** @var array<int, list<ImportResult>> $results */
        $results = [];
        $remapped = [];
        $errors = [];
        try {
            foreach ($groups as $connection => $group) {
                $locker = $this->lockFactory->createLocker(self::lockKey($connection), self::LOCK_CAPABILITIES);
                try {
                    $acquired = $locker->acquire(self::LOCK_CAPABILITIES);
                } catch (LockAcquireWouldBlockException) {
                    $acquired = false;
                }
                if ($acquired) {
                    $lockers[$connection] = $locker;
                    $results[$connection] = [];
                }
            }

            foreach (array_keys($lockers) as $connection) {
                $this->guarded($groups[$connection]['configuration'], $connection, $errors, function () use ($groups, $connection, &$budget, &$results, $timeout, $retentionDays, $range): void {
                    $result = $this->importer->importConnection($groups[$connection]['sites'], $groups[$connection]['configuration'], min($budget, self::FIRST_PASS_DAYS), $timeout, $retentionDays, $range);
                    $budget -= $result->requests;
                    $results[$connection][] = $result;
                });
            }
            foreach (array_keys($lockers) as $connection) {
                $first = $results[$connection][0] ?? null;
                if ($first === null || isset($errors[$connection]) || $budget <= 0 || $first->remainingDays === 0) {
                    continue;
                }
                $this->guarded($groups[$connection]['configuration'], $connection, $errors, function () use ($groups, $connection, $first, &$budget, &$results, $timeout, $retentionDays, $range): void {
                    $result = $this->importer->importConnection($groups[$connection]['sites'], $groups[$connection]['configuration'], $budget, $timeout, $retentionDays, $range, [...$first->importedDays, ...array_keys($first->failedDays)]);
                    $budget -= $result->requests;
                    $results[$connection][] = $result;
                });
            }
            // Once per connection, after its imports: the re-map never delays today's import
            foreach (array_keys($lockers) as $connection) {
                if (isset($errors[$connection])) {
                    continue;
                }
                $this->guarded($groups[$connection]['configuration'], $connection, $errors, function () use ($groups, $connection, $retentionDays, &$remapBudget, &$remapped): void {
                    $this->importer->purgeExpiredDays($groups[$connection]['configuration'], $retentionDays);
                    $remapped[$connection] = $this->importer->remapSites($groups[$connection]['sites'], $groups[$connection]['configuration'], $remapBudget);
                    $remapBudget -= $remapped[$connection];
                });
            }
        } finally {
            foreach ($lockers as $locker) {
                $locker->release();
            }
        }

        $failed = false;
        foreach ($groups as $connection => $group) {
            $sites = implode(', ', array_map(static fn (Site $site): string => $site->getIdentifier(), $group['sites']));
            $output->writeln(sprintf(
                '%s: Matomo site %d of %s, configuration of site %s',
                $sites,
                $group['configuration']->idSite,
                rtrim($group['configuration']->url, '/'),
                $group['sites'][0]->getIdentifier(),
            ));
            if (!isset($results[$connection])) {
                $output->writeln($sites . ': skipped (running)');
                continue;
            }
            if ($results[$connection] !== []) {
                $failed = $this->report($output, $sites, $results[$connection], $remapped[$connection] ?? 0) || $failed;
            }
            if (isset($errors[$connection])) {
                $output->writeln($sites . ': failed: ' . $errors[$connection], OutputInterface::OUTPUT_PLAIN);
                $failed = true;
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Runs a step of the connection; an error is logged and recorded instead
     * of ending the run.
     *
     * @param array<int, string> $errors error message by connection
     */
    private function guarded(MatomoConfiguration $configuration, int $connection, array &$errors, \Closure $step): void
    {
        try {
            $step();
        } catch (\Throwable $exception) {
            $errors[$connection] = $exception->getMessage();
            $this->logger->warning('Page view import of Matomo site {idSite} failed: {message}', [
                'idSite' => $configuration->idSite,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Configured sites grouped by connection: several TYPO3 sites can share
     * one Matomo site. Requests use the configuration (token) of the first
     * site. Demo sites and configurations with a segment are not stored.
     * Connections are created for the returned groups only.
     *
     * @param string|null $identifier import only the connection of this site (and its other sites, they get the day's mapping as well)
     * @return array<int, array{configuration: MatomoConfiguration, sites: non-empty-list<Site>}> by connection uid
     * @throws SiteNotFoundException for an unknown $identifier
     */
    private function groups(?string $identifier): array
    {
        $only = null;
        if ($identifier !== null) {
            $configuration = $this->storedConfiguration($this->siteFinder->getSiteByIdentifier($identifier));
            if ($configuration === null) {
                return [];
            }
            $only = ConnectionRepository::identifier($configuration);
        }
        $groups = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $configuration = $this->storedConfiguration($site);
            if ($configuration === null) {
                continue;
            }
            $key = ConnectionRepository::identifier($configuration);
            if ($only !== null && $key !== $only) {
                continue;
            }
            $groups[$key]['configuration'] ??= $configuration;
            $groups[$key]['sites'][] = $site;
        }
        $byConnection = [];
        foreach ($groups as $group) {
            $byConnection[$this->connections->getOrCreate($group['configuration'])] = $group;
        }
        return $byConnection;
    }

    private function storedConfiguration(Site $site): ?MatomoConfiguration
    {
        $configuration = $this->resolver->resolve($site);
        return $configuration === null || $configuration->demo || $configuration->segment !== '' ? null : $configuration;
    }

    /**
     * @param list<ImportResult> $results
     * @return bool whether a day failed
     */
    private function report(OutputInterface $output, string $sites, array $results, int $remapped): bool
    {
        $imported = array_sum(array_map(static fn (ImportResult $result): int => count($result->importedDays), $results));
        $failures = array_replace(...array_map(static fn (ImportResult $result): array => $result->failedDays, $results));
        $line = $sites . ': ' . self::days($imported) . ' imported';
        if ($failures !== []) {
            $line .= ', ' . count($failures) . ' failed';
        }
        $remaining = $results[count($results) - 1]->remainingDays;
        if ($remaining > 0) {
            $line .= ', ' . self::days($remaining) . ' left';
        }
        if ($remapped > 0) {
            $line .= ', ' . $remapped . ($remapped === 1 ? ' URL' : ' URLs') . ' mapped again';
        }
        $output->writeln($line);
        foreach ($failures as $day => $message) {
            $output->writeln('  ' . Day::toDate($day, new \DateTimeZone('UTC'))->format('Y-m-d') . ': ' . $message, OutputInterface::OUTPUT_PLAIN);
        }
        return $failures !== [];
    }

    private static function days(int $count): string
    {
        return $count . ($count === 1 ? ' day' : ' days');
    }

    private function integerOption(InputInterface $input, string $name, int $minimum): ?int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !preg_match('/^\d+$/', $value) || (int)$value < $minimum) {
            return null;
        }
        return (int)$value;
    }

    /**
     * @return int|false|null the day (Ymd), false if invalid, null if not given
     */
    private function dayOption(InputInterface $input, string $name): int|false|null
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return false;
        }
        return Day::fromDate($date);
    }
}
