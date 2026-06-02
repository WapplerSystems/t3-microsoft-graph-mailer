<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistryInterface;

defined('TYPO3') or die();

(static function () {
    $registry = GeneralUtility::makeInstance(ProviderRegistryInterface::class);
    $registry->register(new ProviderDefinition(
        identifier: 'microsoft_graph',
        title: 'Microsoft Graph (Mail)',
        type: 'microsoft_graph',
        authorizationUrl: 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
        tokenUrl: 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
        defaultScopes: ['https://graph.microsoft.com/.default'],
        setupGuideUrl: 'https://github.com/WapplerSystems/t3-microsoft-graph-mailer/blob/main/Documentation/AzureAppRegistration.md',
    ));
})();
