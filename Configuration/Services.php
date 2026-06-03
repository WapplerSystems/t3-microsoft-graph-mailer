<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WapplerSystems\MicrosoftGraphMailer\Mailer\GraphTransport;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load(
        'WapplerSystems\\MicrosoftGraphMailer\\',
        __DIR__ . '/../Classes/*'
    );

    // TYPO3's TransportFactory loads the configured MAIL.transport class via
    // GeneralUtility::makeInstance(FQCN, $mailSettings). When the class is a
    // private DI service makeInstance bypasses the container and calls the
    // constructor directly with $mailSettings as the first positional argument,
    // which breaks autowiring. Mark the transport public so makeInstance
    // resolves it through the container with all dependencies injected.
    $services->set(GraphTransport::class)->public();
};
