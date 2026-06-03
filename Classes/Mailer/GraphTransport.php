<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Mailer;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
 *
 * Note on dependencies: TYPO3 TransportFactory loads custom transports via
 * GeneralUtility::makeInstance(FQCN, $mailSettings). makeInstance only
 * consults the DI container when no constructor arguments are passed
 * (see GeneralUtility.php), so we cannot use constructor autowiring here.
 * Services are looked up lazily inside doSend() via makeInstance, which
 * works because TokenAcquisitionService, OAuthClientService and
 * RequestFactory are all exposed as public container services.
 */
final class GraphTransport extends AbstractTransport
{
    private const PROVIDER = 'microsoft_graph';
    private const SEND_MAIL_ENDPOINT = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    /**
     * @param array<string, mixed> $mailSettings Reserved for future per-deployment
     *     overrides (e.g. a different provider identifier). Not used yet.
     */
    public function __construct(array $mailSettings = [])
    {
        parent::__construct(
            null,
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)
        );
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

        $oauthClient = GeneralUtility::makeInstance(OAuthClientService::class);
        $tokenAcquisition = GeneralUtility::makeInstance(TokenAcquisitionService::class);
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $mapper = GeneralUtility::makeInstance(EmailToGraphPayloadMapper::class);

        $senderUpn = (string)$oauthClient->getActiveClientMetadataValueByProvider(
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

        $token = $tokenAcquisition->getClientCredentialsToken(self::PROVIDER);
        if ($token === null || $token === '') {
            throw new GraphMailerException(
                'TokenAcquisitionService returned no access token for provider microsoft_graph. '
                . 'Check the OAuth client configuration and credentials.'
            );
        }

        $payload = $mapper->map($original);
        $url = sprintf(self::SEND_MAIL_ENDPOINT, rawurlencode($senderUpn));

        $response = $requestFactory->request($url, 'POST', [
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
                $hint = ' — 404 MailboxNotEnabledForRESTAPI means the sender UPN exists but has no Exchange Online '
                    . 'license. Assign an Exchange Online Plan 1 (or M365 Business Basic) license to this mailbox in '
                    . 'the Microsoft 365 admin center.';
            } elseif ($status === 404 && str_contains($body, 'ErrorInvalidUser')) {
                $hint = ' — 404 ErrorInvalidUser means the sender UPN does not exist in the tenant at all. '
                    . 'Verify the exact UPN in the Microsoft 365 admin center (Active users). Note that an SMTP '
                    . 'address can differ from the account UPN — the sender_upn metadata must match the login UPN, '
                    . 'not necessarily the primary SMTP address.';
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
