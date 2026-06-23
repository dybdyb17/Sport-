<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginRedirectHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        // 1) Si l'utilisateur a tenté d'accéder à une URL protégée avant de se
        // connecter (ex: il a scanné un QR /admin/checkin/{ref} sans être logué),
        // Symfony a stocké cette URL dans la session via TargetPathTrait. On y
        // retourne en priorité — sinon le coach atterrit sur son dashboard et
        // doit re-scanner. UX cassée.
        if ($request->hasSession()) {
            $session = $request->getSession();
            $targetPath = $this->getTargetPath($session, 'main');
            if ($targetPath) {
                $this->removeTargetPath($session, 'main');
                return new RedirectResponse($targetPath);
            }
        }

        // 2) Pas d'URL d'origine → on envoie vers le dashboard du rôle.
        $roles = $token->getRoleNames();
        $target = match (true) {
            in_array('ROLE_ADMIN', $roles, true) => 'app_admin_dashboard',
            in_array('ROLE_COACH', $roles, true) => 'app_coach_dashboard',
            default                               => 'app_espace_client',
        };

        try {
            $url = $this->urlGenerator->generate($target);
        } catch (RouteNotFoundException) {
            $url = $this->urlGenerator->generate('app_home');
        }

        return new RedirectResponse($url);
    }
}
