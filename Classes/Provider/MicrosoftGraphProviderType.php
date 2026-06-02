<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Provider;

use TYPO3\CMS\Core\Http\RequestFactory;
use WapplerSystems\MicrosoftGraphMailer\Exception\GraphMailerException;
use WapplerSystems\OauthService\Attribute\OAuthProviderType;
use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Domain\Model\Connection;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\Type\OAuthProviderTypeInterface;

#[OAuthProviderType('microsoft_graph')]
final class MicrosoftGraphProviderType implements OAuthProviderTypeInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function supportsClientCredentials(): bool
    {
        return true;
    }

    public function fetchClientCredentialsToken(
        ProviderDefinition $providerDefinition,
        Client $client,
        string $clientSecret,
        array $scopes = []
    ): array {
        $tokenUrl = $this->resolveTenantUrl($providerDefinition->tokenUrl, $client);

        $effectiveScopes = $scopes !== [] ? $scopes : $providerDefinition->defaultScopes;
        if ($effectiveScopes === []) {
            $effectiveScopes = ['https://graph.microsoft.com/.default'];
        }

        $response = $this->requestFactory->request($tokenUrl, 'POST', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getClientId(),
                'client_secret' => $clientSecret,
                'scope' => implode(' ', $effectiveScopes),
            ],
            'timeout' => 10,
            'http_errors' => false,
        ]);

        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['access_token'])) {
            throw new GraphMailerException(sprintf(
                'Microsoft identity platform did not return an access_token (HTTP %d): %s',
                $response->getStatusCode(),
                $body
            ));
        }

        return $data;
    }

    public function buildAuthorizationUrl(
        Client $client,
        string $providerAuthorizationUrl,
        string $redirectUri,
        string $state,
        array $scopes = [],
        ?string $codeChallenge = null,
        string $codeChallengeMethod = 'S256'
    ): string {
        $authorizationUrl = $this->resolveTenantUrl($providerAuthorizationUrl, $client);

        $params = [
            'response_type' => 'code',
            'client_id' => $client->getClientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];
        if ($scopes !== []) {
            $params['scope'] = implode(' ', $scopes);
        }
        if ($codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = $codeChallengeMethod;
        }

        return $authorizationUrl . '?' . http_build_query($params);
    }

    public function exchangeCodeForToken(
        ProviderDefinition $providerDefinition,
        Client $client,
        string $clientSecret,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null
    ): array {
        $tokenUrl = $this->resolveTenantUrl($providerDefinition->tokenUrl, $client);

        $formParams = [
            'client_id' => $client->getClientId(),
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];
        if ($codeVerifier !== null) {
            $formParams['code_verifier'] = $codeVerifier;
        }

        $response = $this->requestFactory->request($tokenUrl, 'POST', [
            'form_params' => $formParams,
            'timeout' => 10,
            'http_errors' => false,
        ]);

        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new GraphMailerException('Invalid token response from Microsoft identity platform');
        }

        return $data;
    }

    public function refreshToken(Client $client, Connection $connection, string $refreshToken): array
    {
        $tokenUrl = $this->resolveTenantUrl(
            'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            $client
        );

        $response = $this->requestFactory->request($tokenUrl, 'POST', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $client->getClientId(),
                'client_secret' => $client->getClientSecret(),
            ],
            'timeout' => 10,
            'http_errors' => false,
        ]);

        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new GraphMailerException('Invalid refresh response from Microsoft identity platform');
        }

        return $data;
    }

    private function resolveTenantUrl(string $template, Client $client): string
    {
        if (!str_contains($template, '{tenant}')) {
            return $template;
        }

        $tenant = (string)$client->getMetadataValue('tenant_id', '');
        if ($tenant === '') {
            throw new GraphMailerException(sprintf(
                'OAuth client #%d for provider microsoft_graph has no tenant_id in its metadata JSON. Set {"tenant_id":"…"} in the OAuth Services backend module.',
                $client->getUid() ?? 0
            ));
        }

        return str_replace('{tenant}', rawurlencode($tenant), $template);
    }
}
