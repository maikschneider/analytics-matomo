<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Command;

use MaikSchneider\AnalyticsMatomo\Digest\WeeklyDigest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Mail\MailerInterface;

final class SendDigestCommand extends Command
{
    public function __construct(
        private readonly WeeklyDigest $digest,
        private readonly MailerInterface $mailer,
        private readonly Context $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the mails instead of sending them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        /** @var \DateTimeImmutable $today */
        $today = $this->context->getPropertyFromAspect('date', 'full');
        $failed = false;
        foreach ($this->digest->getRecipients() as $user) {
            $recipient = (string)($user->user['email'] ?? '');
            [$email, $errors] = $this->digest->create($user, $today);
            foreach ($errors as $error) {
                $failed = true;
                $output->writeln($recipient . ': ' . $error, OutputInterface::OUTPUT_PLAIN);
            }
            if ($email === null) {
                $output->writeln($recipient . ': no site with statistics');
                continue;
            }
            if ($dryRun) {
                $output->writeln(['To: ' . $recipient, 'Subject: ' . $email->getSubject(), '', (string)$email->getTextBody(true), ''], OutputInterface::OUTPUT_PLAIN);
                continue;
            }
            try {
                $this->mailer->send($email);
                $output->writeln($recipient . ': sent');
            } catch (TransportExceptionInterface $e) {
                $failed = true;
                $output->writeln($recipient . ': ' . $e->getMessage(), OutputInterface::OUTPUT_PLAIN);
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
