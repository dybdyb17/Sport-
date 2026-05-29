<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * Intercepte les erreurs 403 pour rendre une page d'erreur stylée premium
 * UNIQUEMENT quand l'utilisateur est déjà connecté.
 *
 * Si l'utilisateur est anonyme, on laisse Symfony rediriger normalement
 * vers la page de connexion (comportement standard du security firewall).
 */
class AccessDeniedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Environment           $twig,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 10],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof AccessDeniedException) {
            return;
        }

        // Anonyme → laisser le firewall rediriger vers /login
        $token = $this->tokenStorage->getToken();
        if (null === $token || null === $token->getUser()) {
            return;
        }

        // Utilisateur connecté mais sans les droits → page 403 stylée
        $html = $this->twig->render('bundles/TwigBundle/Exception/error403.html.twig');
        $event->setResponse(new Response($html, Response::HTTP_FORBIDDEN));
        $event->stopPropagation();
    }
}
