<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WapplerSystems\MicrosoftGraphMailer\Mailer\GraphTransport;

#[AsCommand(
    name: 'microsoft-graph-mailer:list-undelivered',
    description: 'Audit the fallback spool: list undelivered emails that the GraphTransport spooled to disk after a delivery failure.',
)]
final class ListUndeliveredCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Override the spool directory path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory = $this->resolveDirectory($input);
        if ($directory === null) {
            $io->warning('Spool directory does not exist (yet). No undelivered emails.');
            return Command::SUCCESS;
        }

        $jsonFiles = glob($directory . '/*.json') ?: [];
        if ($jsonFiles === []) {
            $io->success(sprintf('No undelivered emails in %s.', $directory));
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($jsonFiles as $jsonFile) {
            $meta = json_decode((string)@file_get_contents($jsonFile), true);
            if (!is_array($meta)) {
                continue;
            }
            $base = basename($jsonFile, '.json');
            $emlFile = $directory . '/' . $base . '.eml';
            $emlSize = is_file($emlFile) ? filesize($emlFile) : null;
            $rows[] = [
                $base,
                $meta['failed_at'] ?? '?',
                implode(', ', $meta['to'] ?? []),
                (string)($meta['subject'] ?? ''),
                $emlSize !== null ? $this->formatBytes((int)$emlSize) : '<missing>',
                $this->truncate((string)($meta['reason'] ?? ''), 80),
            ];
        }

        usort($rows, static fn ($a, $b) => strcmp((string)$b[1], (string)$a[1]));

        $io->writeln(sprintf('<info>Spool directory:</info> %s', $directory));
        $io->writeln(sprintf('<info>Undelivered emails:</info> %d', count($rows)));
        $io->newLine();
        $io->table(['Base name', 'Failed at', 'To', 'Subject', 'Size', 'Reason'], $rows);
        $io->note('Use a mail client or `cat` to inspect the .eml files. Once recovered, delete the .eml/.json pair to remove it from the spool.');
        return Command::SUCCESS;
    }

    private function resolveDirectory(InputInterface $input): ?string
    {
        $configured = $input->getOption('dir');
        if (is_string($configured) && $configured !== '') {
            return is_dir($configured) ? rtrim($configured, '/') : null;
        }

        $directory = GraphTransport::resolveSpoolDirectory();
        return $directory !== null && is_dir($directory) ? $directory : null;
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, $length - 1) . '…' : $value;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}
