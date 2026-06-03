<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Mailer;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use TYPO3\CMS\Core\Http\RequestFactory;
use WapplerSystems\MicrosoftGraphMailer\Exception\GraphMailerException;
use WapplerSystems\OauthService\Service\OAuthClientService;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

/**
 * Symfony Mailer transport that delivers emails via the Microsoft Graph
 * /users/{id}/sendMail endpoint. Authenticates via OAuth 2.0 client_credentials
 * obtained from wapplersystems/oauth-service.
 *
 * Wiring: set $TYPO3_CONF_VARS['MAIL']['transport'] to this class FQCN.
 * Sender mailbox + tenant id are read from the active OAuth client's metadata
 * JSON (keys: tenant_id, sender_upn).
 */
final class GraphTransport extends AbstractTransport
{
    private const PROVIDER = 'microsoft_graph';
    private const SEND_MAIL_ENDPOINT = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    public function __construct(
        private readonly TokenAcquisitionService $tokenAcquisition,
        private readonly OAuthClientService $oauthClient,
        private readonly RequestFactory $requestFactory,
        private readonly EmailToGraphPayloadMapper $mapper,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger ?? new NullLogger());
    }

    public function __toString(): string
    {
        return 'ms-graph://' . self::PROVIDER;
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (!$original instanceof Email) {
            throw new GraphMailerException(sprintf(
                'GraphTransport requires Symfony\Component\Mime\Email, got %s',
                $original instanceof Message ? $original::class : get_debug_type($original)
            ));
        }

        $senderUpn = (string)$this->oauthClient->getActiveClientMetadataValueByProvider(
            self::PROVIDER,
            'sender_upn',
            ''
        );
        if ($senderUpn === '') {
            throw new GraphMailerException(
                'No sender_upn configured in the active microsoft_graph OAuth client metadata. '
                . 'Set {"sender_upn":"noreply@yourdomain.com","tenant_id":"…"} in the OAuth Services backend module.'
            );
        }

        $token = $this->tokenAcquisition->getClientCredentialsToken(self::PROVIDER);
        if ($token === null || $token === '') {
            throw new GraphMailerException(
                'TokenAcquisitionService returned no access token for provider microsoft_graph. '
                . 'Check the OAuth client configuration and credentials.'
            );
        }

        $payload = $this->mapper->map($original);
        $url = sprintf(self::SEND_MAIL_ENDPOINT, rawurlencode($senderUpn));

        $response = $this->requestFactory->request($url, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => 30,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        if ($status !== 202) {
            $requestId = $response->getHeaderLine('request-id');
            $body = (string)$response->getBody();

            $hint = '';
            if ($status === 401 && $body === '') {
                $hint = ' — empty 401 body almost always means the access token carries no application roles. '
                    . 'In Azure: App registrations → your app → API permissions → confirm "Mail.Send" is listed under '
                    . 'Application permissions (NOT Delegated) and shows "Granted for <tenant>". '
                    . 'Click "Grant admin consent" if the status is orange. Then invalidate the token cache '
                    . '(e.g. typo3 cache:flush) before retrying.';
            } elseif ($status === 403 && str_contains($body, 'Access is denied')) {
                $hint = ' — 403 ErrorAccessDenied typically means an Application Access Policy in Exchange Online '
                    . 'is blocking this mailbox. Run Get-ApplicationAccessPolicy in Exchange Online PowerShell and '
                    . 'add the sender mailbox to the allowed group.';
            } elseif ($status === 404 && str_contains($body, 'MailboxNotEnabledForRESTAPI')) {
                $hint = ' — 404 MailboxNotEnabledForRESTAPI means the sender UPN has no Exchange Online license or '
                    . 'is not a real mailbox. Verify the mailbox in Microsoft 365 admin center.';
            }

            throw new GraphMailerException(sprintf(
                'Microsoft Graph sendMail failed (HTTP %d, request-id=%s, sender=%s): %s%s',
                $status,
                $requestId !== '' ? $requestId : 'n/a',
                $senderUpn,
                $body !== '' ? $body : '<empty body>',
                $hint
            ));
        }
    }
}
