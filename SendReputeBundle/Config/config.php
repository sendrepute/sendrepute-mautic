<?php

declare(strict_types=1);

use MauticPlugin\SendReputeBundle\EventSubscriber\EmailButtonSubscriber;

return [
    'name'        => 'SendRepute',
    'description' => 'Explicit paid, advisory preflight analysis for selected email drafts.',
    'version'     => '0.1.0',
    'author'      => 'SendRepute',
    'routes'      => [
        'main' => [
            'sendrepute_email_preflight' => [
                'path'       => '/sendrepute/email/{emailId}/preflight',
                'controller' => 'MauticPlugin\SendReputeBundle\Controller\PreflightController::preflightAction',
                'method'     => ['GET', 'POST'],
                'requirements' => [
                    'emailId' => '\d+',
                ],
            ],
        ],
        'public' => [],
        'api'    => [],
    ],
    'services' => [
        'events' => [
            'sendrepute.email_button_subscriber' => [
                'class'     => EmailButtonSubscriber::class,
                'arguments' => [
                    'router',
                    'mautic.security',
                ],
            ],
        ],
    ],
];