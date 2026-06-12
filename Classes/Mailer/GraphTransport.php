<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Mailer;

use Psr\Log\LoggerInterface;
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
 * token, HTTP 4xx/5xx) the message is serialised to an .eml + .json pair in
 * the spool directory so a recovery / retry can pick it up later. Default
 * location: <typo3_var>/typo3-mail-spool/. Override via $TYPO3_CONF_VARS
 * ['MAIL']['transport_graph_fallback_directory']; set to false to disable
 * the fallback and re-raise GraphMailerException.
 *
 * Use microsoft-graph-mailer:list-undelivered to audit the spool and
 * microsoft-graph-mailer:resend-undelivered (manually or via Scheduler task)
 * to retry the saved Graph payloads.
 */
final class GraphTransport extends AbstractTransport
{
    public const PROVIDER = 'microsoft_graph';
    public const SEND_MAIL_ENDPOINT_TEMPLATE = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';
    public const DEFAULT_SPOOL_RELATIVE_PATH = '/typo3-mail-spool';

    private readonly mixed $fallbackDirectorySetting;
    private readonly LoggerInterface $log;

    /**
     * @param array<string, mixed> $mailSettings TYPO3 mail settings array passed by
     *     TransportFactory. Reads transport_graph_fallback_directory.
     */
    public function __construct(array $mailSettings = [])
    {
        $this->log = GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class);
        parent::__construct(null, $this->log);

        $this->fallbackDirectorySetting = $mailSettings['transport_graph_fallback_directory']
            ?? $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_graph_fallback_directory']
            ?? null;
    }

    public function __toString(): string
    {
        return 'ms-graph://' . self::PROVIDER;
    }

    /**
     * Returns the default spool directory location used when nothing is
     * configured. Public so the Resend command and List command share the
     * exact same path resolution.
     */
    public static function defaultSpoolDirectory(): string
    {
        return Environment::getVarPath() . self::DEFAULT_SPOOL_RELATIVE_PATH;
    }

    /**
     * Resolves the configured spool directory (or default) into an absolute
     * path, creating it on demand. Returns null when the operator has set the
     * config to false to disable the fallback entirely.
     */
    public static function resolveSpoolDirectory(?LoggerInterface $logger = null): ?string
    {
        $setting = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_graph_fallback_directory'] ?? null;
        if ($setting === false) {
            return null;
        }

        $path = is_string($setting) && $setting !== ''
            ? $setting
            : self::defaultSpoolDirectory();

        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            $logger?->error('Microsoft Graph spool directory cannot be created', ['path' => $path]);
            return null;
        }

        return rtrim($path, '/');
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

        $mapper = GeneralUtility::makeInstance(EmailToGraphPayloadMapper::class);
        $graphPayload = $mapper->map($original);

        try {
            $this->trySendViaGraph($graphPayload);
        } catch (GraphMailerException $e) {
            $fallbackDir = $this->resolveFallbackDirectoryWithInstanceSetting();
            if ($fallbackDir === null) {
                throw $e;
            }

            $emlPath = $this->writeFallbackFile($original, $e, $fallbackDir, $graphPayload);
            $this->log->warning(
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

    /**
     * @param array<string, mixed> $graphPayload
     */
    private function trySendViaGraph(array $graphPayload): void
    {
        $oauthClient = GeneralUtility::makeInstance(OAuthClientService::class);
        $tokenAcquisition = GeneralUtility::makeInstance(TokenAcquisitionService::class);
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);

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

        try {
            self::postSendMail($requestFactory, $senderUpn, $token, $graphPayload);
            return;
        } catch (GraphMailerException $primaryError) {
            $rewritten = self::rewritePayloadForSendAsFallback($graphPayload, $senderUpn, $primaryError);
            if ($rewritten === null) {
                throw $primaryError;
            }
            $this->log->info(
                'Microsoft Graph 403 ErrorSendAsDenied — retrying with sender_upn rewrite (original From moved to Reply-To)',
                [
                    'original_from' => $graphPayload['message']['from']['emailAddress']['address'] ?? null,
                    'rewritten_from' => $senderUpn,
                ]
            );
            self::postSendMail($requestFactory, $senderUpn, $token, $rewritten);
        }
    }

    /**
     * If $error is the Microsoft 365 "Send As denied" rejection AND the
     * current payload has a From-address that differs from the OAuth identity
     * ($senderUpn), return a payload variant whose From is rewritten to
     * $senderUpn — the original From is moved into Reply-To so replies still
     * reach the intended mailbox. Returns null when no useful retry is
     * possible (different error, no From, or From already matches senderUpn).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function rewritePayloadForSendAsFallback(
        array $payload,
        string $senderUpn,
        GraphMailerException $error
    ): ?array {
        if (!str_contains($error->getMessage(), 'ErrorSendAsDenied')) {
            return null;
        }
        $currentFrom = (string)($payload['message']['from']['emailAddress']['address'] ?? '');
        if ($currentFrom === '' || strcasecmp($currentFrom, $senderUpn) === 0) {
            return null;
        }

        $originalEntry = $payload['message']['from'];
        $payload['message']['from']['emailAddress']['address'] = $senderUpn;
        // Keep the display name (e.g. "PART Engineering") so recipients still
        // see the friendly label, even though the underlying mailbox changed.

        // Prepend original From to Reply-To list, deduping by address.
        $existing = $payload['message']['replyTo'] ?? [];
        $seen = [strtolower($currentFrom)];
        $replyTo = [$originalEntry];
        foreach ($existing as $entry) {
            $addr = strtolower((string)($entry['emailAddress']['address'] ?? ''));
            if ($addr !== '' && !in_array($addr, $seen, true)) {
                $seen[] = $addr;
                $replyTo[] = $entry;
            }
        }
        $payload['message']['replyTo'] = $replyTo;

        return $payload;
    }

    /**
     * Static so the Resend command can reuse it without re-instantiating the
     * full transport. Throws GraphMailerException on any non-202 response with
     * a hint string mapping the most common AADSTS / Graph error shapes to
     * remediation steps.
     *
     * @param array<string, mixed> $graphPayload
     */
    public static function postSendMail(
        RequestFactory $requestFactory,
        string $senderUpn,
        string $token,
        array $graphPayload
    ): void {
        $url = sprintf(self::SEND_MAIL_ENDPOINT_TEMPLATE, rawurlencode($senderUpn));

        $response = $requestFactory->request($url, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $graphPayload,
            'timeout' => 30,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        if ($status === 202) {
            return;
        }

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

    private function resolveFallbackDirectoryWithInstanceSetting(): ?string
    {
        if ($this->fallbackDirectorySetting === false) {
            return null;
        }
        return self::resolveSpoolDirectory($this->log);
    }

    /**
     * @param array<string, mixed> $graphPayload
     */
    private function writeFallbackFile(
        Email $email,
        GraphMailerException $reason,
        string $directory,
        array $graphPayload
    ): string {
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
            'retry_count' => 0,
            'graph_payload' => $graphPayload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $emlPath;
    }
}
