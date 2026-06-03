<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Task;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use WapplerSystems\MicrosoftGraphMailer\Command\ResendUndeliveredCommand;

/**
 * Scheduler task that retries spooled emails by delegating to the
 * microsoft-graph-mailer:resend-undelivered console command.
 *
 * Recommended cadence: every 5 minutes.
 *
 * Custom TCA columns on tx_scheduler_task:
 * - tx_microsoftgraphmailer_batch_size (int, default 50) — max mails per run
 * - tx_microsoftgraphmailer_max_age_days (int, default 0) — abandon entries
 *   older than this; 0 keeps them forever
 */
final class ResendUndeliveredTask extends AbstractTask
{
    public int $tx_microsoftgraphmailer_batch_size = 50;
    public int $tx_microsoftgraphmailer_max_age_days = 0;

    public function execute(): bool
    {
        $command = GeneralUtility::makeInstance(ResendUndeliveredCommand::class);

        $input = new ArrayInput([
            '--limit' => (string)max(1, $this->tx_microsoftgraphmailer_batch_size),
            '--max-age-days' => (string)max(0, $this->tx_microsoftgraphmailer_max_age_days),
        ]);
        $input->setInteractive(false);

        $output = new BufferedOutput();
        $exitCode = $command->run($input, $output);

        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class);
        $logger->info('microsoft-graph-mailer:resend-undelivered finished', [
            'exit_code' => $exitCode,
            'output' => trim($output->fetch()),
        ]);

        return $exitCode === 0;
    }

    public function getAdditionalInformation(): string
    {
        return sprintf(
            'Batch size: %d · Max age (days): %d',
            $this->tx_microsoftgraphmailer_batch_size,
            $this->tx_microsoftgraphmailer_max_age_days
        );
    }

    public function setTaskParameters(array $parameters): void
    {
        if (isset($parameters['tx_microsoftgraphmailer_batch_size'])) {
            $this->tx_microsoftgraphmailer_batch_size = (int)$parameters['tx_microsoftgraphmailer_batch_size'];
        }
        if (isset($parameters['tx_microsoftgraphmailer_max_age_days'])) {
            $this->tx_microsoftgraphmailer_max_age_days = (int)$parameters['tx_microsoftgraphmailer_max_age_days'];
        }
    }
}
