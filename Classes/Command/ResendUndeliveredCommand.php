<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\MicrosoftGraphMailer\Exception\GraphMailerException;
use WapplerSystems\MicrosoftGraphMailer\Mailer\GraphTransport;
use WapplerSystems\OauthService\Service\OAuthClientService;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsCommand(
    name: 'microsoft-graph-mailer:resend-undelivered',
    description: 'Retry spooled emails via Microsoft Graph. Successful deliveries delete the .eml/.json pair; failures stay in the spool with retry_count incremented.',
)]
final class ResendUndeliveredCommand extends Command
{
    public const DEFAULT_LIMIT = 50;

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of mails to retry in this run', self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be retried without actually calling Graph')
            ->addOption('max-age-days', null, InputOption::VALUE_REQUIRED, 'Delete (do not retry) entries older than this many days; 0 disables', 0)
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Override the spool directory path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory = $this->resolveDirectory($input);
        if ($directory === null) {
            $io->note('Spool directory does not exist or is disabled. Nothing to do.');
            return Command::SUCCESS;
        }

        $jsonFiles = glob($directory . '/*.json') ?: [];
        if ($jsonFiles === []) {
            $io->success(sprintf('Spool %s is empty.', $directory));
            return Command::SUCCESS;
        }

        $limit = max(1, (int)$input->getOption('limit'));
        $dryRun = (bool)$input->getOption('dry-run');
        $maxAgeDays = max(0, (int)$input->getOption('max-age-days'));
        $maxAgeCutoff = $maxAgeDays > 0 ? (time() - $maxAgeDays * 86400) : 0;

        $oauthClient = GeneralUtility::makeInstance(OAuthClientService::class);
        $tokenAcquisition = GeneralUtility::makeInstance(TokenAcquisitionService::class);
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);

        $senderUpn = (string)$oauthClient->getActiveClientMetadataValueByProvider(
            GraphTransport::PROVIDER,
            'sender_upn',
            ''
        );
        if ($senderUpn === '') {
            $io->error('No active microsoft_graph OAuth client with a sender_upn. Cannot retry. Configure a client first.');
            return Command::FAILURE;
        }

        try {
            $token = $tokenAcquisition->getClientCredentialsToken(GraphTransport::PROVIDER);
        } catch (\Throwable $e) {
            $io->error('Token acquisition failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
        if ($token === null || $token === '') {
            $io->error('TokenAcquisitionService returned no token.');
            return Command::FAILURE;
        }

        sort($jsonFiles);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $abandoned = 0;
        $processed = 0;

        foreach ($jsonFiles as $jsonFile) {
            if ($processed >= $limit) {
                $skipped++;
                continue;
            }

            $meta = json_decode((string)@file_get_contents($jsonFile), true);
            if (!is_array($meta) || !isset($meta['graph_payload']) || !is_array($meta['graph_payload'])) {
                $io->warning(sprintf('Skipping %s: malformed JSON or missing graph_payload.', basename($jsonFile)));
                $skipped++;
                continue;
            }

            if ($maxAgeCutoff > 0) {
                $failedAt = isset($meta['failed_at']) ? (int)strtotime((string)$meta['failed_at']) : 0;
                if ($failedAt > 0 && $failedAt < $maxAgeCutoff) {
                    if (!$dryRun) {
                        $this->deletePair($jsonFile);
                    }
                    $io->writeln(sprintf('  <comment>abandoned</comment> %s (older than %d days)', basename($jsonFile, '.json'), $maxAgeDays));
                    $abandoned++;
                    continue;
                }
            }

            $base = basename($jsonFile, '.json');
            $processed++;

            if ($dryRun) {
                $io->writeln(sprintf('  <info>would retry</info> %s → %s', $base, $senderUpn));
                continue;
            }

            try {
                GraphTransport::postSendMail($requestFactory, $senderUpn, $token, $meta['graph_payload']);
                $this->deletePair($jsonFile);
                $io->writeln(sprintf('  <info>sent</info> %s', $base));
                $sent++;
            } catch (GraphMailerException $e) {
                $meta['retry_count'] = (int)($meta['retry_count'] ?? 0) + 1;
                $meta['last_retry_at'] = date(\DATE_ATOM);
                $meta['last_retry_error'] = $e->getMessage();
                file_put_contents(
                    $jsonFile,
                    (string)json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
                $io->writeln(sprintf('  <error>failed</error> %s (retry #%d): %s', $base, $meta['retry_count'], $e->getMessage()));
                $failed++;
            }
        }

        $io->section('Summary');
        $io->definitionList(
            ['processed' => (string)$processed],
            ['sent' => (string)$sent],
            ['failed (kept for next run)' => (string)$failed],
            ['abandoned (too old)' => (string)$abandoned],
            ['skipped (over limit / malformed)' => (string)$skipped],
            ['dry-run' => $dryRun ? 'yes' : 'no'],
        );

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveDirectory(InputInterface $input): ?string
    {
        $configured = $input->getOption('dir');
        if (is_string($configured) && $configured !== '') {
            return is_dir($configured) ? rtrim($configured, '/') : null;
        }
        return GraphTransport::resolveSpoolDirectory();
    }

    private function deletePair(string $jsonFile): void
    {
        $base = substr($jsonFile, 0, -5);
        @unlink($jsonFile);
        @unlink($base . '.eml');
    }
}
