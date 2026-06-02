<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistryInterface;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load(
        'WapplerSystems\\MicrosoftGraphMailer\\',
        __DIR__ . '/../Classes/*'
    );

    $builder->addCompilerPass(
        new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if (!$container->hasDefinition(ProviderRegistryInterface::class)
                    && !$container->hasAlias(ProviderRegistryInterface::class)) {
                    return;
                }

                $registry = $container->findDefinition(ProviderRegistryInterface::class);
                $registry->addMethodCall('register', [
                    new Definition(ProviderDefinition::class, [
                        'microsoft_graph',
                        'Microsoft Graph (Mail)',
                        'microsoft_graph',
                        'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
                        'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
                        ['https://graph.microsoft.com/.default'],
                    ]),
                ]);
            }
        }
    );
};