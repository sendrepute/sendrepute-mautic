<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\SendReputeBundle\Controller\PreflightController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // The preflight helpers are constructed explicitly by the controller.
    // In particular PreflightException and ApiClient cannot be autowired.
    $services->load('MauticPlugin\\SendReputeBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(
            MauticCoreExtension::DEFAULT_EXCLUDES,
            ['Service']
        )).'}');
    $services->set(PreflightController::class)->tag('controller.service_arguments');
};