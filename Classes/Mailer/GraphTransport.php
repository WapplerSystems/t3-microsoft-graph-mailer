<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Mailer;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use TYPO3\CMS\Core\Core\Environment;
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
 * Failure handling: when Graph rejects the request (missing client, missing
 * token, HTTP 4xx/5xx) the original Email is serialised to an .eml file in
 * the fallback spool directory so the operator can recover and re-send it
 * later. The default spool location is var/log/microsoft-graph-mailer/
 * undelivered/. Override via $TYPO3_CONF_VARS['MAIL']['transport_graph_
 * fallback_directory']; set to false to disable the fallback (in which case
 * any failure re-raises GraphMailerException and propagates up).
 *
 * Use the typo3 microsoft-graph-mailer:list-undelivered command to audit the
 * spool directory.
 */
final class GraphTransport extends AbstractTransport
{
    private const PROVIDER = 'microsoft_graph';
    private const SEND_MAIL_ENDPOINT = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private readonly mixed $fallbackDirectorySetting;

    /**
     * @param array<string, mixed> $mailSettings TYPO3 mail settings array passed by
     *     TransportFactory. Reads transport_graph_fallback_directory.
     */
    public function __construct(array $mailSettings = [])
    {
        parent::__construct(
            null,
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)
        );

        // Read at construct time so we capture the value once. TYPO3 caches the
        // transport instance for the request, so re-reading on every send would
        // be wasted work.
        $this->fallbackDirectorySetting = $mailSettings['transport_graph_fallback_directory']
            ?? $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_graph_fallback_directory']
            ?? null;
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

        try {
            $this->trySendViaGraph($original);
        } catch (GraphMailerException $e) {
            $fallbackDir = $this->resolveFallbackDirectory();
            if ($fallbackDir === null) {
                throw $e;
            }

            $emlPath = $this->writeFallbackFile($original, $e, $fallbackDir);
            $this->logger->warning(
                'Microsoft Graph delivery failed; original message spooled to file for recovery',
                [
                    'reason' => $e->getMessage(),
                    'eml_path' => $emlPath,
                    'recipients' => array_map(static fn ($a) => $a->getAddress(), $original->getTo()),
                    'subject' => (string)$original->getSubject(),
                ]
            );
        }
    }

    private function trySendViaGraph(Email $original): void
    {
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

    /**
     * Returns the absolute fallback directory, ensuring it exists, or null when
     * the operator has explicitly disabled the fallback by setting the config
     * value to false.
     */
    private function resolveFallbackDirectory(): ?string
    {
        if ($this->fallbackDirectorySetting === false) {
            return null;
        }

        $configured = is_string($this->fallbackDirectorySetting) && $this->fallbackDirectorySetting !== ''
            ? $this->fallbackDirectorySetting
            : Environment::getVarPath() . '/log/microsoft-graph-mailer/undelivered';

        if (!is_dir($configured)) {
            if (!@mkdir($configured, 0775, true) && !is_dir($configured)) {
                $this->logger->error(
                    'Microsoft Graph fallback directory cannot be created; original exception will be re-thrown',
                    ['path' => $configured]
                );
                return null;
            }
        }

        return rtrim($configured, '/');
    }

    private function writeFallbackFile(Email $email, GraphMailerException $reason, string $directory): string
    {
        $emlBody = $email->toString();
        $recipientHint = '';
        $firstTo = $email->getTo()[0] ?? null;
        if ($firstTo !== null) {
            $recipientHint = '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $firstTo->getAddress());
        }

        $base = sprintf(
            '%s%s-%s',
            date('Ymd-His'),
            $recipientHint,
            substr(sha1($emlBody . microtime(true)), 0, 8)
        );

        $emlPath = $directory . '/' . $base . '.eml';
        $jsonPath = $directory . '/' . $base . '.json';

        file_put_contents($emlPath, $emlBody);
        file_put_contents($jsonPath, (string)json_encode([
            'failed_at' => date(\DATE_ATOM),
            'reason' => $reason->getMessage(),
            'subject' => (string)$email->getSubject(),
            'from' => array_map(static fn ($a) => $a->toString(), $email->getFrom()),
            'to' => array_map(static fn ($a) => $a->toString(), $email->getTo()),
            'cc' => array_map(static fn ($a) => $a->toString(), $email->getCc()),
            'bcc' => array_map(static fn ($a) => $a->toString(), $email->getBcc()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $emlPath;
    }
}
