<?php

declare(strict_types=1);

namespace MauticPlugin\SendReputeBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use Mautic\EmailBundle\Entity\Email;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

final class EmailButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private CorePermissions $security
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectButton', 0],
        ];
    }

    public function injectButton(CustomButtonEvent $event): void
    {
        $email = $event->getItem();
        if (!$this->security->isAdmin() || !$email instanceof Email || null === $email->getId()) {
            return;
        }

        if (!$this->security->hasEntityAccess(
            'email:emails:viewown',
            'email:emails:viewother',
            $email->getCreatedBy()
        )) {
            return;
        }

        $event->addButton(
            [
                'attr' => [
                    'href'        => $this->router->generate('sendrepute_email_preflight', ['emailId' => $email->getId()]),
                    'data-toggle' => null,
                ],
                'btnText'   => 'SendRepute preflight',
                'iconClass' => 'ri-shield-check-line',
                'priority'  => -50,
            ],
            ButtonHelper::LOCATION_PAGE_ACTIONS,
            'mautic_email_action'
        );
    }
}