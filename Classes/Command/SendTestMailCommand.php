<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Mail\MailMessage;
use WapplerSystems\MicrosoftGraphMailer\Mailer\EmailToGraphPayloadMapper;
use WapplerSystems\MicrosoftGraphMailer\Mailer\GraphTransport;
use WapplerSystems\OauthService\Service\OAuthClientService;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsCommand(
    name: 'microsoft-graph-mailer:test',
    description: 'Send a test email via Microsoft Graph using the active OAuth client.'
)]
final class SendTestMailCommand extends Command
{
    public function __construct(
        private readonly TokenAcquisitionService $tokenAcquisition,
        private readonly OAuthClientService $oauthClient,
        private readonly GraphTransport $graphTransport,
        private readonly EmailToGraphPayloadMapper $mapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'Recipient email address')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Subject line', 'Microsoft Graph Mailer test')
            ->addOption('body', null, InputOption::VALUE_REQUIRED, 'Body text', 'This is a test message sent through Microsoft Graph from TYPO3.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string)$input->getArgument('to');

        $senderUpn = (string)$this->oauthClient->getActiveClientMetadataValueByProvider('microsoft_graph', 'sender_upn', '');
        $tenantId  = (string)$this->oauthClient->getActiveClientMetadataValueByProvider('microsoft_graph', 'tenant_id', '');

        $io->section('Active microsoft_graph OAuth client');
        $io->definitionList(
            ['tenant_id' => $tenantId !== '' ? $tenantId : '<missing>'],
            ['sender_upn' => $senderUpn !== '' ? $senderUpn : '<missing>'],
        );

        if ($tenantId === '' || $senderUpn === '') {
            $io->error('Active microsoft_graph client must have tenant_id and sender_upn in its metadata JSON. Configure it in System > OAuth Services.');
            return Command::FAILURE;
        }

        $io->section('Token acquisition');
        try {
            $token = $this->tokenAcquisition->getClientCredentialsToken('microsoft_graph');
        } catch (\Throwable $e) {
            $io->error('Token acquisition failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
        if ($token === null || $token === '') {
            $io->error('No access token returned. Check client_id, client_secret and admin-consent for Mail.Send.');
            return Command::FAILURE;
        }
        $io->success(sprintf('Access token acquired (%d chars, prefix=%s…).', strlen($token), substr($token, 0, 8)));

        $io->section('Sending test mail');
        $message = (new MailMessage())
            ->from($senderUpn)
            ->to($to)
            ->subject((string)$input->getOption('subject'))
            ->text((string)$input->getOption('body'));

        try {
            $this->graphTransport->send($message);
        } catch (\Throwable $e) {
            $io->error('Graph sendMail failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Test mail accepted by Microsoft Graph for delivery to %s.', $to));
        return Command::SUCCESS;
    }
}
