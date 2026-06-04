<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WapplerSystems\OauthService\Provider\ProviderDefinition;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load(
        'WapplerSystems\\MicrosoftGraphMailer\\',
        __DIR__ . '/../Classes/*'
    );

    // Register the microsoft_graph provider via DI tag so it is available
    // even in the TYPO3 Install Tool's failsafe bootstrap (which does not
    // run ext_localconf.php). Picked up by ProviderRegistry's constructor.
    $services->set('microsoft_graph_mailer.provider_definition', ProviderDefinition::class)
        ->autowire(false)
        ->args([
            '$identifier' => 'microsoft_graph',
            '$title' => 'Microsoft Graph (Mail)',
            '$type' => 'microsoft_graph',
            '$authorizationUrl' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
            '$tokenUrl' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            '$defaultScopes' => ['https://graph.microsoft.com/.default'],
            '$setupGuideUrl' => 'https://github.com/WapplerSystems/t3-microsoft-graph-mailer/blob/main/Documentation/AzureAppRegistration.md',
        ])
        ->tag('oauth_service.provider_definition');
};
