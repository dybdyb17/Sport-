<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class LoginRedirectHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $roles = $token->getRoleNames();

        $target = match (true) {
            in_array('ROLE_ADMIN', $roles, true) => 'app_admin_dashboard',
            in_array('ROLE_COACH', $roles, true) => 'app_coach_dashboard',
            default                               => 'app_espace_client',
        };

        try {
            $url = $this->urlGenerator->generate($target);
        } catch (RouteNotFoundException) {
            // Route pas encore créée (lots 2-4) : fallback gracieux
            $url = $this->urlGenerator->generate('app_home');
        }

        return new RedirectResponse($url);
    }
}
