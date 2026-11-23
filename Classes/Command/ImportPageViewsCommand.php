<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Command;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfiguration;
use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ConnectionRepository;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\Day;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\ImportResult;
use MaikSchneider\AnalyticsMatomo\Statistics\Store\PageViewImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Days kept per Matomo site, today included; older days are deleted', '400')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Import the days from this day (Y-m-d) again, e.g. after Matomo reprocessed its logs; without --to up to today')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Import the days up to this day (Y-m-d) again; without --from this day only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $budget = $this->integerOption($input, 'max-requests', 0);
        $timeout = $this->integerOption($input, 'timeout', 1);
        $retentionDays = $this->integerOption($input, 'retention-days', 1);
        $from = $this->dayOption($input, 'from');
        $to = $this->dayOption($input, 'to');
        if ($budget === null || $timeout === null || $retentionDays === null || $from === false || $to === false) {
            $output->writeln('<error>--max-requests must be 0 or more, --timeout and --retention-days 1 or more, --from and --to days as Y-m-d</error>');
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

        $groups = $this->groups($input->getOption('site'));
        if ($groups === []) {
            $output->writeln('No Matomo site to import: demo sites and sites without Matomo configuration are not stored');
            return Command::SUCCESS;
        }
        $lockers = [];
        $results = [];
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
                }
            }

            foreach (array_keys($lockers) as $connection) {
                $result = $this->importer->importConnection($groups[$connection]['sites'], $groups[$connection]['configuration'], min($budget, self::FIRST_PASS_DAYS), $timeout, $retentionDays, $range);
                $budget -= $result->requests;
                $results[$connection] = [$result];
            }
            foreach (array_keys($lockers) as $connection) {
                $first = $results[$connection][0];
                if ($budget <= 0 || $first->remainingDays === 0) {
                    continue;
                }
                $result = $this->importer->importConnection($groups[$connection]['sites'], $groups[$connection]['configuration'], $budget, $timeout, $retentionDays, $range, [...$first->importedDays, ...array_keys($first->failedDays)]);
                $budget -= $result->requests;
                $results[$connection][] = $result;
            }
        } finally {
            foreach ($lockers as $locker) {
                $locker->release();
            }
        }

        $failed = false;
        foreach ($groups as $connection => $group) {
            $sites = implode(', ', array_map(static fn (Site $site): string => $site->getIdentifier(), $group['sites']));
            if (!isset($results[$connection])) {
                $output->writeln($sites . ': skipped (running)');
                continue;
            }
            $failed = $this->report($output, $sites, $results[$connection]) || $failed;
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Configured sites grouped by connection: several TYPO3 sites can share
     * one Matomo site. Requests use the configuration (token) of the first
     * site. Demo sites and configurations with a segment are not stored.
     *
     * @return array<int, array{configuration: MatomoConfiguration, sites: list<Site>}> by connection uid
     */
    private function groups(mixed $identifier): array
    {
        $groups = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $configuration = $this->resolver->resolve($site);
            if ($configuration === null || $configuration->demo || $configuration->segment !== '') {
                continue;
            }
            $connection = $this->connections->getOrCreate($configuration);
            $groups[$connection]['configuration'] ??= $configuration;
            $groups[$connection]['sites'][] = $site;
        }
        if (!is_string($identifier)) {
            return $groups;
        }
        // The other sites of the connection get the day's mapping as well
        return array_filter($groups, static fn (array $group): bool => in_array($identifier, array_map(static fn (Site $site): string => $site->getIdentifier(), $group['sites']), true));
    }

    /**
     * @param list<ImportResult> $results
     * @return bool whether a day failed
     */
    private function report(OutputInterface $output, string $sites, array $results): bool
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
