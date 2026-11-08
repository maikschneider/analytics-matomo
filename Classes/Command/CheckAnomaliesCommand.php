<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Command;

use MaikSchneider\AnalyticsMatomo\Configuration\MatomoConfigurationResolver;
use MaikSchneider\AnalyticsMatomo\Matomo\MatomoApiException;
use MaikSchneider\AnalyticsMatomo\Monitoring\AnomalyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

final class CheckAnomaliesCommand extends Command
{
    public function __construct(private readonly SiteFinder $sites, private readonly MatomoConfigurationResolver $resolver, private readonly AnomalyService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Check only this TYPO3 site identifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = false;
        $identifier = $input->getOption('site');
        $sites = is_string($identifier) ? [$this->sites->getSiteByIdentifier($identifier)] : $this->sites->getAllSites();
        foreach ($sites as $site) {
            $configuration = $this->resolver->resolve($site);
            if ($configuration === null) {
                continue;
            }
            try {
                $this->service->refresh($configuration);
                $output->writeln($site->getIdentifier() . ': checked');
            } catch (MatomoApiException $exception) {
                $failed = true;
                $output->writeln($site->getIdentifier() . ': ' . $exception->getMessage(), OutputInterface::OUTPUT_PLAIN);
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
