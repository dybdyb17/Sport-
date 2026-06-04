<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Met à jour User::lastSeenAt à chaque requête utilisateur connecté,
 * mais au maximum toutes les 60 secondes (évite spam DB).
 */
class UserActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = new \DateTimeImmutable();
        $lastSeen = $user->getLastSeenAt();

        // Met à jour seulement si plus vieux que 60 secondes (perf)
        if (null === $lastSeen || $lastSeen->modify('+60 seconds') < $now) {
            $user->setLastSeenAt($now);
            $this->em->flush();
        }
    }
}
